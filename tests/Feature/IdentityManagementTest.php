<?php

namespace Tests\Feature;

use App\Http\Controllers\OperationsController;
use App\Models\LifecycleRun;
use App\Models\LifecycleTask;
use App\Models\LifecycleTemplate;
use App\Models\Setting;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Demo\DemoTenant;
use App\Services\Demo\MockGraphClient;
use App\Services\Lifecycle\Directory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** CIPP-style identity management: multi-user and scheduled offboarding, ownership, inbox rules, bulk onboarding, templates. */
class IdentityManagementTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();
        config(['tenantsguard.admin.password' => 'secret-pass-123']);
        $this->seed();
        $this->admin = User::first();
        $this->tenant = DemoTenant::create('Harbour Dental (Demo)');
        (new Directory($this->tenant))->users(); // seed the demo directory (groups, owners, inbox rules) once
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

    private function offboard(array $users, array $over = [])
    {
        return $this->post(route('operations.offboard.run', $this->tenant), array_merge([
            'users' => array_column($users, 'id'), 'when' => 'now',
            'confirm' => count($users) === 1 ? $users[0]['userPrincipalName'] : 'OFFBOARD '.count($users),
            'steps' => ['block_signin', 'revoke_sessions'],
        ], $over));
    }

    public function test_sidebar_has_identity_management_items_and_picker_routes_to_the_client(): void
    {
        $this->get(route('operations.index'))->assertOk()
            ->assertSeeInOrder(['Identity Management', 'Onboard user', 'Bulk onboard users', 'Offboard users', 'User templates', 'Lifecycle activity']);

        // One connected client: go straight to it.
        $this->get(route('identity.pick', 'onboard'))->assertRedirect(route('operations.onboard', $this->tenant));
        $this->get(route('identity.pick', 'offboard'))->assertRedirect(route('operations.offboard', $this->tenant));

        // Several clients: show a picker, remember the choice.
        $other = DemoTenant::create('Click Clinic (Demo)');
        $this->get(route('identity.pick', 'bulk'))->assertOk()->assertSee('Choose the client')->assertSee('Click Clinic (Demo)');
        $this->get(route('identity.pick', ['bulk', 'tenant' => $other->id]))->assertRedirect(route('operations.bulk', $other));
        $this->get(route('identity.pick', 'bulk'))->assertSee('Last used');
        $this->get('/identity/start/nope')->assertNotFound();
    }

    public function test_offboarding_transfers_group_ownership_and_removes_inbox_rules(): void
    {
        $leaver = $this->user('frontdesk');
        $newOwner = $this->user('casey');
        $this->assertSame([$leaver['id']], $this->group('Reception')['owners']);

        $this->get(route('operations.offboard', [$this->tenant, 'users' => [$leaver['id']]]))->assertOk()
            ->assertSee('Owner of (2)')->assertSee('Forward everything')->assertSee('Forwards mail')->assertSee('New owner');

        $this->offboard([$leaver], ['steps' => ['block_signin', 'remove_rules', 'ownership', 'remove_groups'], 'new_owner_id' => $newOwner['id']])->assertRedirect();
        $run = LifecycleRun::latest('id')->first();

        $this->assertSame([$newOwner['id']], $this->group('Reception')['owners']);
        $this->assertNotContains($leaver['id'], $this->group('Clinic Team')['owners']);
        $this->assertContains($newOwner['id'], $this->group('Clinic Team')['owners']);
        $this->assertSame([], $this->user('frontdesk')['inboxRules']);
        $this->assertStringContainsString('Forward everything', collect($run->steps)->firstWhere('key', 'remove_rules')['message']);
        $this->assertSame('done', collect($run->steps)->firstWhere('key', 'ownership')['status']);
        $this->assertCount(2, $run->snapshot['owned']);

        // The new owner may not be one of the leavers.
        $this->offboard([$newOwner], ['steps' => ['ownership'], 'new_owner_id' => $newOwner['id']])->assertSessionHasErrors('new_owner_id');
    }

    public function test_offboarding_several_people_at_once(): void
    {
        $a = $this->user('frontdesk');
        $b = $this->user('casey');
        $this->get(route('operations.offboard', [$this->tenant, 'users' => [$a['id'], $b['id']]]))->assertOk()
            ->assertSee('OFFBOARD 2')->assertSee('For every device of the selected people')->assertSee('Offboard 2 people');

        $this->offboard([$a, $b], ['confirm' => $a['userPrincipalName']])->assertSessionHasErrors('confirm');
        $this->assertTrue($this->user('frontdesk')['accountEnabled']);

        $this->offboard([$a, $b], ['auto_reply_message' => '{name} has left {company}. Contact {manager}.', 'steps' => ['block_signin', 'auto_reply']])
            ->assertRedirect(route('operations.tenant', $this->tenant))->assertSessionHas('status');
        $this->assertFalse($this->user('frontdesk')['accountEnabled']);
        $this->assertFalse($this->user('casey')['accountEnabled']);
        $this->assertSame(2, LifecycleRun::where('type', 'offboard')->count());
        $reply = $this->user('casey')['mailboxSettings']['automaticRepliesSetting']['externalReplyMessage'];
        $this->assertStringContainsString('Casey Nguyen has left Harbour Dental (Demo).', $reply);
    }

    public function test_scheduled_offboarding_runs_at_the_chosen_time_and_can_be_cancelled(): void
    {
        $leaver = $this->user('frontdesk');
        $this->offboard([$leaver], ['when' => 'later', 'scheduled_at' => now()->subHour()->format('Y-m-d\TH:i')])->assertSessionHasErrors('scheduled_at');

        $at = now()->addDay()->setTime(17, 30);
        $this->offboard([$leaver], ['when' => 'later', 'scheduled_at' => $at->format('Y-m-d\TH:i'), 'steps' => ['block_signin', 'remove_rules']])
            ->assertRedirect()->assertSessionHas('status');
        $run = LifecycleRun::latest('id')->first();
        $this->assertSame('scheduled', $run->status);
        $this->assertTrue($this->user('frontdesk')['accountEnabled'], 'nothing changes before the scheduled time');
        $task = LifecycleTask::where('action', 'offboard')->first();
        $this->assertSame($this->admin->id, $task->payload['by']);
        $this->get(route('operations.index'))->assertSee('Scheduled offboarding');

        $this->artisan('lifecycle:process')->assertSuccessful();
        $this->assertTrue($this->user('frontdesk')['accountEnabled']);

        $this->travel(2)->days();
        $this->artisan('lifecycle:process')->assertSuccessful();
        $this->assertFalse($this->user('frontdesk')['accountEnabled']);
        $this->assertSame([], $this->user('frontdesk')['inboxRules']);
        $run->refresh();
        $this->assertSame('completed', $run->status);
        $this->assertSame('Scheduled offboarding started', $run->steps[0]['label']);
        $this->assertSame('done', $task->fresh()->status);

        // A second scheduled offboarding is cancelled before it runs.
        $other = $this->user('casey');
        $this->offboard([$other], ['when' => 'later', 'scheduled_at' => now()->addDay()->format('Y-m-d\TH:i')]);
        $task2 = LifecycleTask::where('action', 'offboard')->where('status', 'pending')->first();
        $this->post(route('operations.task.cancel', $task2))->assertSessionHas('status');
        $this->assertSame('cancelled', $task2->run->fresh()->status);
        $this->travel(2)->days();
        $this->artisan('lifecycle:process');
        $this->assertTrue($this->user('casey')['accountEnabled']);
    }

    public function test_offboarding_defaults_can_be_saved_per_client(): void
    {
        $leaver = $this->user('frontdesk');
        $this->offboard([$leaver], ['steps' => ['block_signin', 'licences'], 'licence_delay_days' => 7, 'save_defaults' => 1]);
        $saved = json_decode(Setting::find('offboard_defaults_'.$this->tenant->id)->value, true);
        $this->assertSame(['block_signin', 'licences'], $saved['steps']);
        $this->assertSame(7, (int) $saved['licence_delay_days']);
        $this->assertSame(['block_signin', 'licences'], OperationsController::offboardDefaults($this->tenant)['steps']);

        $this->get(route('operations.offboard', [$this->tenant, 'users' => [$this->user('casey')['id']]]))->assertSee('Pre-filled with the saved defaults');
    }

    public function test_bulk_onboarding_previews_validates_and_creates(): void
    {
        $tpl = LifecycleTemplate::create(['tenant_id' => $this->tenant->id, 'name' => 'Clinical', 'is_default' => true, 'data' => [
            'username_format' => 'f.last', 'department' => 'Clinical', 'company' => 'Harbour Dental', 'city' => 'Hobart',
        ]]);
        $sku = MockGraphClient::DEMO_SKUS[0]['skuId'];
        $this->get(route('operations.bulk', $this->tenant))->assertOk()->assertSee('Download example CSV')->assertSee('Clinical (default)');

        $csv = "FirstName;LastName;JobTitle;Manager;Username\nAlex;Morgan;Dental Nurse;Avery Patel;\nPriya;Shah;Receptionist;nobody@nowhere.test;\nFront;Desk;;;frontdesk\nSam;Lee;;;\nSam;Lee;;;";
        $this->post(route('operations.bulk.preview', $this->tenant), [
            'csv' => $csv, 'template_id' => $tpl->id, 'domain' => 'brightwaterdental.example', 'username_format' => 'f.last',
            'usage_location' => 'AU', 'password_mode' => 'password', 'licences' => [$sku], 'groups' => [],
        ])->assertOk()
            ->assertSee('a.morgan@brightwaterdental.example')
            ->assertSee('was not found')
            ->assertSee('already exists in the tenant')
            ->assertSee('Duplicate of another row')
            ->assertSee('2 of 5 people are ready');

        $this->post(route('operations.bulk.run', $this->tenant))->assertOk()->assertSee('shown only once')->assertSee('2 of 2 account(s) created');
        $alex = $this->user('a.morgan');
        $this->assertSame('Clinical', $alex['department']);
        $this->assertSame('Harbour Dental', $alex['companyName']);
        $this->assertSame('Hobart', $alex['city']);
        $this->assertSame($this->user('avery')['id'], $alex['managerId']);
        $this->assertSame($sku, $alex['assignedLicenses'][0]['skuId']);
        $this->assertNotNull($this->user('s.lee'));
        $this->assertNull($this->user('p.shah'), 'rows with problems are skipped');

        // The preview is single-use.
        $this->post(route('operations.bulk.run', $this->tenant))->assertRedirect(route('operations.bulk', $this->tenant))->assertSessionHasErrors('csv');
        $this->post(route('operations.bulk.preview', $this->tenant), ['csv' => "Name\nX", 'domain' => 'x', 'username_format' => 'first.last', 'usage_location' => 'AU', 'password_mode' => 'tap'])
            ->assertSessionHasErrors('csv');
    }

    public function test_user_templates_with_one_default_per_client(): void
    {
        $this->get(route('templates.index'))->assertOk()->assertSee('No templates yet');
        $this->get(route('templates.create', $this->tenant))->assertOk()->assertSee('Username format')->assertSee('Start from an existing user');

        $base = ['username_format' => 'first.last', 'password_mode' => 'tap', 'tap_hours' => 8, 'licences' => [MockGraphClient::DEMO_SKUS[0]['skuId']]];
        $this->post(route('templates.store', $this->tenant), $base + ['name' => 'Nurse', 'is_default' => 1, 'display_suffix' => '(Clinic)', 'company' => 'Harbour'])
            ->assertRedirect(route('templates.index'));
        $this->post(route('templates.store', $this->tenant), $base + ['name' => 'Reception', 'is_default' => 1]);
        $this->post(route('templates.store', $this->tenant), $base + ['name' => 'Reception'])->assertSessionHasErrors('name');

        $nurse = LifecycleTemplate::where('name', 'Nurse')->first();
        $this->assertFalse($nurse->is_default, 'only one default per client');
        $this->assertTrue(LifecycleTemplate::where('name', 'Reception')->first()->is_default);
        $this->assertSame('(Clinic)', $nurse->data['display_suffix']);

        $this->put(route('templates.update', [$this->tenant, $nurse]), array_merge($base, ['name' => 'Dental nurse', 'username_format' => 'flast']))->assertRedirect();
        $this->assertSame('flast', $nurse->fresh()->data['username_format']);

        $this->get(route('templates.index'))->assertSee('Dental nurse')->assertSee('flastname')->assertSee('Default');
        $this->get(route('operations.onboard', $this->tenant))->assertSee('Reception (default)')->assertSee('Postal code');

        // Build a template from an existing person.
        $casey = $this->user('casey');
        $this->get(route('templates.create', [$this->tenant, 'from_user' => $casey['id']]))->assertOk();

        $this->assertSame('a.morgan', LifecycleTemplate::username('Alex', 'Morgan', 'f.last'));
        $this->assertSame('amorgan', LifecycleTemplate::username('Alex', 'Morgan', 'flast'));
        $this->assertSame('jose.m', LifecycleTemplate::username('José', "M'Bala", 'first.l'));
        $this->assertSame('alexmorgan', LifecycleTemplate::username('Alex', 'Morgan', 'firstlast'));
    }
}
