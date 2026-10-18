<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\Role;
use App\Models\ScriptVersion;
use App\Models\Setting;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Access\GrantScript;
use App\Services\Access\TenantAccess;
use App\Services\Demo\DemoTenant;
use App\Services\Demo\MockGraphClient;
use App\Services\Graph\GraphException;
use App\Services\Scanner;
use App\Services\Scripts\ScriptExecutor;
use App\Support\Access;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Permission scope: app plan, per-client access profiles (policy) and the client grant. */
class PermissionScopeTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();
        config(['tenantsguard.admin.password' => 'secret-pass-123']);
        $this->seed();
        $this->tenant = DemoTenant::create('Lakeside Clinic (Demo)');
        app(Scanner::class)->scan($this->tenant);
        $this->actingAs(User::first());
    }

    private function staff(string $role): User
    {
        $user = User::create(['name' => ucfirst($role), 'email' => $role.'@msp.test', 'password' => 'Password123',
            'role_id' => Role::byKey($role)->id, 'all_tenants' => true, 'is_active' => true]);

        return $user;
    }

    public function test_write_paths_map_to_capabilities_and_unknown_paths_are_denied(): void
    {
        $this->assertSame(['security_config'], Access::writeCapabilities('POST', '/identity/conditionalAccess/policies'));
        $this->assertSame(['security_config'], Access::writeCapabilities('PATCH', '/policies/authorizationPolicy'));
        $this->assertSame(['intune_manage'], Access::writeCapabilities('POST', '/beta/deviceManagement/deviceCompliancePolicies'));
        $this->assertSame(['intune_manage'], Access::writeCapabilities('POST', 'beta/deviceAppManagement/iosManagedAppProtections'));
        $this->assertSame(['privileged'], Access::writeCapabilities('POST', '/deviceManagement/managedDevices/abc/wipe'));
        $this->assertSame(['privileged'], Access::writeCapabilities('DELETE', '/directoryRoles/r1/members/u1/$ref'));
        $this->assertSame(['privileged'], Access::writeCapabilities('POST', '/users/u1/authentication/temporaryAccessPassMethods'));
        $this->assertSame(['licensing', 'users'], Access::writeCapabilities('POST', '/users/u1/assignLicense'));
        $this->assertSame(['users'], Access::writeCapabilities('PATCH', '/users/u1/mailboxSettings'));
        $this->assertSame(['groups'], Access::writeCapabilities('POST', '/groups/g1/members/$ref'));
        $this->assertSame(['records'], Access::writeCapabilities('POST', '/security/labels/retentionLabels'));
        $this->assertSame([], Access::writeCapabilities('POST', '/applications'));
        $this->assertSame([], Access::writeCapabilities('POST', '/servicePrincipals/x/appRoleAssignments'));

        $this->expectException(GraphException::class);
        TenantAccess::assertWrite($this->tenant, 'POST', '/servicePrincipals/x/appRoleAssignments');
    }

    public function test_default_profile_follows_the_app_plan_and_presets_apply(): void
    {
        $this->assertNull($this->tenant->access_profile);
        $this->assertEqualsCanonicalizing(Access::appPlan(), TenantAccess::enabledCapabilities($this->tenant));
        $this->assertNotContains('exchange', Access::appPlan(), 'Exchange Online is opt-in');

        $this->post(route('access.update', $this->tenant), ['preset' => 'monitor'])->assertRedirect(route('access.tenant', $this->tenant));
        $this->assertEqualsCanonicalizing(['monitor', 'intune_read'], $this->tenant->fresh()->access_profile);
        $this->assertDatabaseHas('activity_logs', ['action' => 'access.profile', 'tenant_id' => $this->tenant->id]);

        $this->post(route('access.update', $this->tenant), ['preset' => 'custom', 'caps' => ['groups']]);
        $this->assertEqualsCanonicalizing(['monitor', 'groups'], $this->tenant->fresh()->access_profile, 'monitor is always on');

        $this->post(route('access.update', $this->tenant), ['preset' => 'full'])->assertSessionHasErrors('reason');
        $this->post(route('access.update', $this->tenant), ['preset' => 'full', 'reason' => 'Managed service contract covers everything']);
        $this->assertNull($this->tenant->fresh()->access_profile);
    }

    public function test_policy_blocks_writes_outside_the_profile_even_when_granted(): void
    {
        $this->tenant->update(['access_profile' => ['monitor', 'intune_read']]);
        $graph = new MockGraphClient($this->tenant->fresh());
        try {
            $graph->patch('/policies/authorizationPolicy', ['allowedToSignUpEmailBasedSubscriptions' => false]);
            $this->fail('Write was not blocked');
        } catch (GraphException $e) {
            $this->assertSame('AccessPolicy', $e->graphCode);
            $this->assertStringContainsString('Security configuration', $e->friendly());
        }
        $this->assertNotEmpty($graph->get('/users')['value'], 'reads still work');

        // A quick fix in the UI reports the policy, not a Graph error.
        $this->post(route('fix.store', [$this->tenant, 'APP-01']))->assertSessionHasErrors('fix');

        $this->tenant->update(['access_profile' => ['monitor', 'security_config']]);
        $this->post(route('fix.store', [$this->tenant, 'APP-01']))->assertSessionHas('status');
    }

    public function test_privileged_needs_global_admin_and_justification_and_expires(): void
    {
        $admin = $this->staff('administrator');
        $this->actingAs($admin)->post(route('access.update', $this->tenant), ['preset' => 'monitor']);
        $this->actingAs($admin)->post(route('access.update', $this->tenant), ['preset' => 'custom', 'caps' => ['privileged'], 'reason' => 'Leaver with admin roles'])
            ->assertSessionHasErrors('caps');
        $this->assertNotContains('privileged', TenantAccess::enabledCapabilities($this->tenant->fresh()));

        $this->actingAs(User::first())->post(route('access.update', $this->tenant), ['preset' => 'custom', 'caps' => ['users', 'privileged']])
            ->assertSessionHasErrors('reason');
        $this->post(route('access.update', $this->tenant), ['preset' => 'custom', 'caps' => ['users', 'privileged'], 'expiry' => ['privileged' => '1'], 'reason' => 'Ticket 4411: offboard the departing IT admin'])
            ->assertSessionHasNoErrors();
        $t = $this->tenant->fresh();
        $this->assertTrue(TenantAccess::enabled($t, 'privileged'));
        $this->assertNotEmpty($t->access_expiry['privileged']);
        $log = ActivityLog::where('action', 'access.profile')->latest('id')->first();
        $this->assertSame('Ticket 4411: offboard the departing IT admin', $log->detail['reason']);

        $this->travel(2)->days();
        $this->assertFalse(TenantAccess::enabled($t->fresh(), 'privileged'), 'time-limited Tier 0 expires on its own');
        $this->assertTrue(TenantAccess::enabled($t->fresh(), 'users'));
    }

    public function test_grant_status_and_demo_simulation(): void
    {
        $status = TenantAccess::status($this->tenant);
        $this->assertSame('granted', $status['monitor']['grant']);
        $this->assertFalse($status['exchange']['enabled']);
        $this->assertTrue($status['exchange']['extra'], 'demo starts with everything granted, Exchange included');

        $this->post(route('access.update', $this->tenant), ['preset' => 'monitor']);
        $this->assertTrue(TenantAccess::status($this->tenant->fresh())['groups']['extra']);

        $this->post(route('access.simulate', $this->tenant), ['mode' => 'profile'])->assertSessionHas('status');
        $status = TenantAccess::status($this->tenant->fresh());
        $this->assertFalse(collect($status)->contains('extra', true));
        $this->assertSame('missing', $status['groups']['grant']);
        $this->assertSame('granted', $status['intune_read']['grant']);

        $this->post(route('access.update', $this->tenant), ['preset' => 'standard']);
        $this->assertSame('missing', TenantAccess::status($this->tenant->fresh())['security_config']['grant']);
    }

    public function test_pages_render_and_permissions_are_enforced(): void
    {
        $this->get(route('access.tenant', $this->tenant))->assertOk()
            ->assertSeeInOrder(['App plan', 'Access profile', 'Client grant'])
            ->assertSee('Security monitoring')->assertSee('Privileged (Tier 0)')->assertSee('Simulate the grant script');
        $this->get(route('access.index'))->assertOk()->assertSee('Clients × capabilities', false)->assertSee('Lakeside Clinic (Demo)')->assertSee('Save app plan');
        $this->get(route('tenants.dashboard', $this->tenant))->assertSee('Access and permissions');

        $ro = $this->staff('read_only');
        $this->actingAs($ro)->get(route('access.tenant', $this->tenant))->assertOk()->assertDontSee('Save access profile');
        $this->actingAs($ro)->post(route('access.update', $this->tenant), ['preset' => 'monitor'])->assertForbidden();
        $this->actingAs($ro)->get(route('access.script', $this->tenant))->assertForbidden();

        $admin = $this->staff('administrator');
        $this->actingAs($admin)->get(route('access.index'))->assertOk()->assertDontSee('Save app plan');
        $this->actingAs($admin)->post(route('access.plan'), ['caps' => ['monitor']])->assertForbidden();
    }

    public function test_app_plan_limits_every_profile(): void
    {
        $this->post(route('access.plan'), ['caps' => ['monitor', 'intune_read', 'groups']])->assertSessionHas('status');
        $this->assertSame(['monitor', 'intune_read', 'groups'], Access::appPlan());
        $this->assertEqualsCanonicalizing(['monitor', 'intune_read', 'groups'], TenantAccess::enabledCapabilities($this->tenant));

        $this->post(route('access.update', $this->tenant), ['preset' => 'custom', 'caps' => ['groups', 'users']]);
        $this->assertEqualsCanonicalizing(['monitor', 'groups'], $this->tenant->fresh()->access_profile, 'capabilities outside the plan are dropped');
        $this->assertDatabaseHas('activity_logs', ['action' => 'access.plan']);

        Setting::put('app_capabilities', json_encode(['bogus']));
        $this->assertSame(['monitor'], Access::appPlan());
    }

    public function test_change_scripts_and_exchange_respect_the_profile(): void
    {
        $this->tenant->update(['access_profile' => ['monitor', 'intune_read']]);
        $exec = new ScriptExecutor;
        $ctx = new \ReflectionMethod($exec, 'context');
        $version = new ScriptVersion(['risk' => 'change', 'use_exchange' => false, 'use_graph_module' => false]);
        try {
            $ctx->invoke($exec, $this->tenant->fresh(), $version, []);
            $this->fail('Change script was allowed in a read-only client');
        } catch (GraphException $e) {
            $this->assertStringContainsString('read-only', $e->getMessage());
        }
        $version = new ScriptVersion(['risk' => 'read', 'use_exchange' => true, 'use_graph_module' => false]);
        try {
            $ctx->invoke($exec, $this->tenant->fresh(), $version, []);
            $this->fail('Exchange was allowed without the capability');
        } catch (GraphException $e) {
            $this->assertStringContainsString('Exchange Online', $e->getMessage());
        }
        $this->tenant->update(['access_profile' => ['monitor', 'groups']]);
        $this->assertIsArray($ctx->invoke($exec, $this->tenant->fresh(), new ScriptVersion(['risk' => 'change', 'use_exchange' => false, 'use_graph_module' => false]), []));
    }

    public function test_grant_script_is_least_privilege_and_parses(): void
    {
        $ps = GrantScript::generate($this->tenant, ['groups', 'exchange'], 'Alex O\'Neil');
        $this->assertStringContainsString("'Group.ReadWrite.All'", $ps);
        $this->assertStringContainsString("'Exchange.ManageAsApp'", $ps);
        $this->assertStringContainsString("'Directory.Read.All'", $ps, 'monitor is always included');
        $this->assertStringNotContainsString('User.ReadWrite.All', $ps);
        $this->assertStringContainsString('SupportsShouldProcess', $ps);
        $this->assertStringContainsString('Revoke application permission', $ps);
        $this->assertStringContainsString('-AssignExchangeRole', $ps.' -AssignExchangeRole');
        $this->assertStringContainsString('expected $TenantId', $ps);
        $this->assertStringNotContainsString('RoleManagement.ReadWrite.Directory', GrantScript::generate($this->tenant, ['groups']));

        $this->get(route('access.script', $this->tenant))->assertOk()->assertHeader('Content-Disposition', 'attachment; filename="Grant-TenantsGuard-lakeside-clinic-demo.ps1"');
        $this->assertDatabaseHas('activity_logs', ['action' => 'access.script']);

        if (! ScriptExecutor::pwsh()) {
            $this->markTestSkipped('PowerShell 7 not installed.');
        }
        $file = storage_path('app/grant-parse-'.uniqid().'.ps1');
        file_put_contents($file, $ps);
        $cmd = '$e = $null; [void][System.Management.Automation.Language.Parser]::ParseFile(\''.$file.'\', [ref]$null, [ref]$e); $bad = 0; foreach ($x in $e) { $bad++; Write-Output $x.Message }; Write-Output ERRORS=$bad';
        $out = shell_exec('"'.ScriptExecutor::pwsh().'" -NoProfile -NonInteractive -Command '.escapeshellarg($cmd));
        @unlink($file);
        $this->assertStringContainsString('ERRORS=0', (string) $out, (string) $out);
    }
}
