<?php

namespace Tests\Feature;

use App\Models\LifecycleRun;
use App\Models\LifecycleTask;
use App\Models\LifecycleTemplate;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Checks\Roles;
use App\Services\Demo\DemoTenant;
use App\Services\Demo\MockGraphClient;
use App\Services\Lifecycle\Directory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StaffLifecycleTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Tenant $tenant;

    private Directory $dir;

    protected function setUp(): void
    {
        parent::setUp();
        config(['tenantsguard.admin.password' => 'secret-pass-123']);
        $this->seed();
        $this->admin = User::first();
        $this->tenant = DemoTenant::create('Harbour Dental (Demo)');
        $this->dir = new Directory($this->tenant);
        $this->actingAs($this->admin);
    }

    private function state(): array
    {
        return $this->tenant->fresh()->demo_state;
    }

    private function user(string $upnStart): ?array
    {
        return collect($this->state()['users'])->first(fn ($u) => str_starts_with($u['userPrincipalName'], $upnStart));
    }

    private function group(string $name): array
    {
        return collect($this->state()['groups'])->firstWhere('displayName', $name);
    }

    private function onboardPayload(array $over = []): array
    {
        $groups = $this->dir->groups();

        return array_merge([
            'first_name' => 'Maya', 'last_name' => 'Singh', 'display_name' => 'Maya Singh', 'username' => 'maya.singh',
            'domain' => 'brightwaterdental.example', 'job_title' => 'Dental Nurse', 'department' => 'Clinical', 'usage_location' => 'AU',
            'manager_id' => $this->user('avery')['id'], 'password_mode' => 'tap', 'tap_hours' => 8,
            'licences' => [MockGraphClient::DEMO_SKUS[0]['skuId']],
            'groups' => [collect($groups)->firstWhere('name', 'Clinic Team')['id']],
            'checklist' => ['device', 'mfa'], 'ticket' => 'T-1001',
        ], $over);
    }

    public function test_hub_and_forms_render_with_live_directory_data(): void
    {
        $this->get(route('operations.index'))->assertOk()->assertSee('Onboard user')->assertSee('Offboard users')->assertSee('Lifecycle activity');
        $this->get(route('operations.tenant', $this->tenant))->assertOk()->assertSee('People')->assertSee('User templates');
        $this->get(route('operations.onboard', $this->tenant))->assertOk()
            ->assertSee('Microsoft 365 Business Premium')->assertSee('of 15 free')
            ->assertSee('Clinic Team')->assertSee('Membership is rule-based')
            ->assertSee('Temporary Access Pass')->assertSee('brightwaterdental.example');
        $this->get(route('operations.offboard', $this->tenant))->assertOk()->assertSee('Who is leaving?');
    }

    public function test_onboarding_creates_a_ready_to_work_account(): void
    {
        $model = $this->user('casey');
        $res = $this->post(route('operations.onboard.run', $this->tenant), $this->onboardPayload([
            'copy_from' => $model['id'], 'save_template' => 'Dental nurse',
        ]));
        $run = LifecycleRun::latest('id')->first();
        $res->assertRedirect(route('operations.run', $run));

        $u = $this->user('maya.singh');
        $this->assertTrue($u['accountEnabled']);
        $this->assertSame('AU', $u['usageLocation']);
        $this->assertSame($this->user('avery')['id'], $u['managerId']);
        $this->assertSame(MockGraphClient::DEMO_SKUS[0]['skuId'], $u['assignedLicenses'][0]['skuId']);
        $this->assertContains($u['id'], $this->group('Clinic Team')['members']);
        $this->assertContains($u['id'], $this->group('All Staff')['members'], 'copied from colleague');
        $this->assertSame('in_progress', $run->status, 'hand-over checklist open');
        $this->assertCount(2, $run->openSteps());
        $this->assertNotNull(LifecycleTemplate::where('name', 'Dental nurse')->first());

        // Credentials are shown once, then gone.
        $this->get(route('operations.run', $run))->assertSee('Temporary Access Pass')->assertSee('Shown once');
        $this->get(route('operations.run', $run))->assertDontSee('Shown once');

        // Duplicate username is caught before anything is created.
        $this->post(route('operations.onboard.run', $this->tenant), $this->onboardPayload())->assertSessionHasErrors('username');
    }

    public function test_future_start_date_creates_disabled_account_and_enables_it_on_the_day(): void
    {
        $this->post(route('operations.onboard.run', $this->tenant), $this->onboardPayload(['start_date' => now()->addDays(3)->toDateString(), 'password_mode' => 'password']));
        $this->assertFalse($this->user('maya.singh')['accountEnabled']);
        $task = LifecycleTask::first();
        $this->assertSame('enable_account', $task->action);

        $this->artisan('lifecycle:process')->assertSuccessful();
        $this->assertFalse($this->user('maya.singh')['accountEnabled'], 'not yet');

        $this->travel(4)->days();
        $this->artisan('lifecycle:process')->assertSuccessful();
        $this->assertTrue($this->user('maya.singh')['accountEnabled']);
        $this->assertSame('done', $task->fresh()->status);
    }

    public function test_offboarding_locks_out_preserves_and_removes_access_in_order(): void
    {
        $leaver = $this->user('frontdesk');
        $this->get(route('operations.offboard', [$this->tenant, 'user' => $leaver['id']]))->assertOk()
            ->assertSee('Current access')->assertSee('Front Desk')->assertSee('FRONTDESK-PC')->assertSee('Type');

        $payload = [
            'users' => [$leaver['id']], 'when' => 'now', 'confirm' => 'wrong@x',
            'steps' => ['block_signin', 'revoke_sessions', 'reset_password', 'remove_mfa', 'auto_reply', 'remove_groups', 'remove_roles', 'devices', 'licences', 'delete_account', 'convert_mailbox', 'collect_hardware'],
            'auto_reply_message' => 'Front Desk has left. Please email reception@brightwaterdental.example.',
            'devices' => [], 'licence_delay_days' => 30, 'delete_after_days' => 90, 'ticket' => 'T-2002', 'reason' => 'Resigned',
        ];
        $this->post(route('operations.offboard.run', $this->tenant), $payload)->assertSessionHasErrors('confirm');
        $this->assertTrue($this->user('frontdesk')['accountEnabled'], 'nothing happened without confirmation');

        $payload['confirm'] = strtoupper($leaver['userPrincipalName']);
        $this->post(route('operations.offboard.run', $this->tenant), $payload)->assertRedirect();
        $run = LifecycleRun::where('type', 'offboard')->first();

        $u = $this->user('frontdesk');
        $this->assertFalse($u['accountEnabled']);
        $this->assertNotEmpty($u['sessionsRevokedAt']);
        $this->assertSame('alwaysEnabled', $u['mailboxSettings']['automaticRepliesSetting']['status']);
        $this->assertNotContains($u['id'], $this->group('All Staff')['members']);
        $this->assertNotEmpty($u['assignedLicenses'], 'licences kept until the mailbox is converted');
        $device = collect($this->state()['managedDevices'])->firstWhere('deviceName', 'FRONTDESK-PC');
        $this->assertSame('retirePending', $device['managementState'], 'without an explicit choice the server uses the non-destructive retire');

        $this->assertContains('All Staff', array_column($run->snapshot['groups'], 'name'), 'access captured before removal');
        $this->assertSame(['remove_licenses', 'delete_user'], LifecycleTask::where('run_id', $run->id)->orderBy('execute_at')->pluck('action')->all());
        $this->assertSame('pending', collect($run->steps)->firstWhere('key', 'convert_mailbox')['status']);
        $this->assertStringContainsString('Set-Mailbox -Identity', collect($run->steps)->firstWhere('key', 'convert_mailbox')['message']);

        // Checklist + scheduler.
        $this->post(route('operations.step', [$run, 'convert_mailbox']))->assertSessionHas('status');
        $this->post(route('operations.step', [$run, 'collect_hardware']));
        $this->travel(31)->days();
        $this->artisan('lifecycle:process');
        $this->assertSame([], $this->user('frontdesk')['assignedLicenses']);
        $this->travel(60)->days();
        $this->artisan('lifecycle:process');
        $this->assertNull($this->user('frontdesk'), 'account deleted after 90 days');
        $this->assertSame('completed', $run->fresh()->status);
    }

    public function test_cannot_offboard_the_last_global_admin_and_tasks_can_be_cancelled(): void
    {
        $s = $this->tenant->demo_state;
        foreach ($s['directoryRoles'] as $i => $r) {
            if ($r['roleTemplateId'] === Roles::GLOBAL_ADMIN) {
                $s['directoryRoles'][$i]['members'] = [$r['members'][0]];
                $only = $r['members'][0];
            }
        }
        $this->tenant->update(['demo_state' => $s]);

        $this->get(route('operations.offboard', [$this->tenant, 'user' => $only['id']]))->assertSee('only Global Administrator');
        $this->post(route('operations.offboard.run', $this->tenant), [
            'users' => [$only['id']], 'when' => 'now', 'confirm' => $only['userPrincipalName'], 'steps' => ['block_signin'],
        ])->assertSessionHasErrors('offboard');
        $this->assertTrue(collect($this->state()['users'])->firstWhere('id', $only['id'])['accountEnabled']);

        $this->post(route('operations.onboard.run', $this->tenant), $this->onboardPayload(['start_date' => now()->addDay()->toDateString()]));
        $task = LifecycleTask::first();
        $this->post(route('operations.task.cancel', $task))->assertSessionHas('status');
        $this->assertSame('cancelled', $task->fresh()->status);
    }

    public function test_permissions(): void
    {
        $ro = User::create(['name' => 'RO', 'email' => 'ro@msp.test', 'password' => 'Password123', 'role_id' => Role::byKey('read_only')->id, 'all_tenants' => true, 'is_active' => true]);
        $this->actingAs($ro);
        $this->get(route('operations.index'))->assertOk()->assertSee('Your role cannot onboard staff');
        $this->get(route('operations.onboard', $this->tenant))->assertForbidden();
        $this->post(route('operations.offboard.run', $this->tenant), [])->assertForbidden();

        $tech = User::create(['name' => 'T', 'email' => 't@msp.test', 'password' => 'Password123', 'role_id' => Role::byKey('technician')->id, 'all_tenants' => true, 'is_active' => true]);
        $this->actingAs($tech);
        $this->get(route('operations.onboard', $this->tenant))->assertOk();
    }
}
