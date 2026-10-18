<?php

namespace Tests\Feature;

use App\Models\ComplianceProfile;
use App\Models\ControlPlanRun;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Compliance\FrameworkRegistry;
use App\Services\Demo\DemoTenant;
use App\Services\Scanner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ControlPlanTest extends TestCase
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
        $this->tenant = DemoTenant::create('Contoso (Demo)');
        app(Scanner::class)->scan($this->tenant);
        $this->actingAs($this->admin);
    }

    private function createPlan(): ComplianceProfile
    {
        $this->get(route('compliance.profiles.create', ['tenant' => $this->tenant->id]))->assertOk()
            ->assertSee('Contoso (Demo) - ', false)->assertSee('Step 1: Name and frameworks');

        $this->post(route('compliance.profiles.store'), [
            'name' => 'Contoso - CIS and HIPAA', 'frameworks' => ['cisv8', 'hipaa'], 'tenant_id' => $this->tenant->id, 'exclude_from_score' => 1,
        ])->assertRedirect();

        $plan = ComplianceProfile::where('name', 'Contoso - CIS and HIPAA')->firstOrFail();
        $this->assertSame($plan->id, $this->tenant->fresh()->compliance_profile_id);

        return $plan;
    }

    public function test_merged_controls_show_framework_tags_and_overlap(): void
    {
        $plan = $this->createPlan();
        $ids = $plan->candidateControls();

        $cis = collect(FrameworkRegistry::find('cisv8')['requirements'])->pluck('controls')->flatten()->unique();
        $hipaa = collect(FrameworkRegistry::find('hipaa')['requirements'])->pluck('controls')->flatten()->unique();
        $this->assertEqualsCanonicalizing($cis->merge($hipaa)->unique()->values()->all(), $ids, 'union of both frameworks, de-duplicated');

        $shared = array_values(array_filter($ids, fn ($id) => count($plan->controlFrameworks($id)) === 2));
        $this->assertContains('WIN-SEC-02', $shared, 'BitLocker satisfies CIS v8.1 3.6 and HIPAA 164.312(a)(2)(iv)');
        $this->assertArrayHasKey('hipaa', $plan->controlFrameworks('TEN-01'));
        $this->assertArrayNotHasKey('cisv8', $plan->controlFrameworks('TEN-01'), 'HIPAA-only control (risk analysis evidence)');

        $this->get(route('compliance.profiles.edit', $plan))->assertOk()
            ->assertSee('Step 2: Controls, scope and phases')->assertSee('Shared')->assertSee('Only HIPAA')->assertSee('Move selected to');
    }

    public function test_phased_rollout_preview_apply_and_monitoring(): void
    {
        $plan = $this->createPlan();
        $offered = $plan->candidateControls();

        // Scope: exclude ID-10, move APP-01 to phase 3, deploy BitLocker to all devices.
        $this->put(route('compliance.profiles.update', $plan), [
            'name' => $plan->name, 'frameworks' => ['cisv8', 'hipaa'], 'exclude_from_score' => 1,
            'offered' => $offered, 'in_scope' => array_values(array_diff($offered, ['ID-10'])),
            'reason' => ['ID-10' => 'Six GAs approved, PIM in place'],
            'phase' => ['APP-01' => 3, 'WIN-SEC-02' => 3], 'assignment' => ['WIN-SEC-02' => 'all_devices'],
            'phase_names' => [1 => 'Week 1: quick wins', 2 => 'Week 2: identity', 3 => 'Month 2: devices', 4 => 'Month 3: enforce'],
            'tenant_ids' => [$this->tenant->id],
        ])->assertRedirect();
        $plan->refresh();
        $this->assertSame(3, $plan->setting('APP-01')['phase']);
        $this->assertSame('all_devices', $plan->setting('WIN-SEC-02')['assignment']);
        $this->assertNotContains('ID-10', $plan->inScopeControls());
        $this->assertSame('Week 1: quick wins', $plan->phaseList()[1]['name']);

        $this->get(route('plans.rollout', $this->tenant))->assertOk()->assertSee('Week 1: quick wins')->assertSee('Apply phase')->assertSee('Apply all at once');

        // Preview phase 1: nothing changes.
        $before = $this->tenant->fresh()->demo_state;
        $this->post(route('plans.apply', $this->tenant), ['cmd' => 'preview|phase|1'])->assertRedirect();
        $preview = ControlPlanRun::latest('id')->first();
        $this->assertTrue($preview->dry_run);
        $this->assertSame('Week 1: quick wins', $preview->scope);
        $this->assertGreaterThan(0, $preview->summary['would_fix'] ?? 0);
        $this->assertSame($before['authorizationPolicy'], $this->tenant->fresh()->demo_state['authorizationPolicy']);

        // Apply phase 1 only: APP-01 (moved to phase 3) is untouched; a phase-1 control is fixed.
        $this->post(route('plans.apply', $this->tenant), ['cmd' => 'apply|phase|1'])->assertRedirect();
        $s = $this->tenant->fresh()->demo_state;
        $this->assertTrue($s['authorizationPolicy']['defaultUserRolePermissions']['allowedToCreateApps'], 'phase 3 control not applied yet');
        $this->assertSame('adminsAndGuestInviters', $s['authorizationPolicy']['allowInvitesFrom'], 'COL-02 (phase 1) applied');

        // Apply selected: BitLocker (Intune) with the plan's assignment.
        $this->post(route('plans.apply', $this->tenant), ['cmd' => 'apply|selected|', 'control_ids' => ['WIN-SEC-02']])->assertRedirect();
        $bl = collect($this->tenant->fresh()->demo_state['deviceConfigurations'])->firstWhere('displayName', 'TG - BitLocker: XTS-AES 256 with TPM, keys escrowed to Entra');
        $this->assertSame('#microsoft.graph.allDevicesAssignmentTarget', $bl['assignments'][0]['target']['@odata.type']);

        // Apply all at once: the rest, including APP-01. Conditional Access stays report-only.
        $this->post(route('plans.apply', $this->tenant), ['cmd' => 'apply|all|'])->assertRedirect();
        $all = ControlPlanRun::latest('id')->first();
        $this->assertSame('All phases', $all->scope);
        $s = $this->tenant->fresh()->demo_state;
        $this->assertFalse($s['authorizationPolicy']['defaultUserRolePermissions']['allowedToCreateApps']);
        $this->assertTrue(collect($s['conditionalAccess'])->where('state', 'enabled')->isEmpty() || collect($s['conditionalAccess'])->where('state', 'enabled')->every(fn ($p) => ! str_starts_with($p['displayName'], 'TenantsGuard')));
        $this->assertArrayNotHasKey('ID-10', $all->results, 'out-of-scope control never applied');
        $this->assertSame('manual', $all->results['TEN-02']['result']);

        // Monitoring: drift on an applied control.
        $state = $this->tenant->fresh()->demo_state;
        $state['authorizationPolicy']['allowInvitesFrom'] = 'everyone';
        $this->tenant->update(['demo_state' => $state]);
        app(Scanner::class)->scan($this->tenant->fresh());
        $this->get(route('plans.rollout', $this->tenant))->assertOk()->assertSee('Drifted')->assertSee('Run history');
    }

    public function test_permissions_on_plans_and_rollout(): void
    {
        $plan = $this->createPlan();

        $ro = User::create(['name' => 'RO', 'email' => 'ro@msp.test', 'password' => 'Password123', 'role_id' => Role::byKey('read_only')->id, 'all_tenants' => true, 'is_active' => true]);
        $this->actingAs($ro);
        $this->get(route('plans.rollout', $this->tenant))->assertOk()->assertDontSee('Apply all at once');
        $this->post(route('plans.apply', $this->tenant), ['cmd' => 'apply|all|'])->assertForbidden();
        $this->get(route('compliance.profiles.edit', $plan))->assertForbidden();

        // A role that may fix Microsoft 365 settings but not deploy Intune: Intune items are skipped.
        $role = Role::create(['key' => 'm365_only', 'name' => 'M365 only', 'permissions' => ['tenants.view', 'fixes.apply'], 'is_system' => false]);
        $m365 = User::create(['name' => 'M', 'email' => 'm@msp.test', 'password' => 'Password123', 'role_id' => $role->id, 'all_tenants' => true, 'is_active' => true]);
        $this->actingAs($m365);
        $this->post(route('plans.apply', $this->tenant), ['cmd' => 'apply|selected|', 'control_ids' => ['WIN-SEC-02', 'COL-02']])->assertRedirect();
        $run = ControlPlanRun::latest('id')->first();
        $this->assertSame('skipped', $run->results['WIN-SEC-02']['result']);
        $this->assertSame('fixed', $run->results['COL-02']['result']);
    }
}
