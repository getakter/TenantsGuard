<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\Annotation;
use App\Models\Baseline;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Checks\Catalog;
use App\Services\Demo\DemoTenant;
use App\Services\Scanner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TenantsGuardTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        config(['tenantsguard.admin.password' => 'secret-pass-123']);
        $this->seed();
        $this->user = User::first();
    }

    private function demo(): Tenant
    {
        $this->actingAs($this->user)->post(route('tenants.demo'))->assertRedirect();

        return Tenant::where('mode', 'demo')->latest('id')->first();
    }

    private function checkStatus(Tenant $t, string $id): string
    {
        return $t->scans()->first()->results()->where('check_id', $id)->value('status');
    }

    public function test_guests_are_redirected_and_admin_can_log_in(): void
    {
        $this->get('/')->assertRedirect(route('login'));
        $this->get('/login')->assertOk()->assertSee('Sign in');
        $this->post('/login', ['email' => $this->user->email, 'password' => 'wrong'])->assertSessionHasErrors('email');
        $this->post('/login', ['email' => $this->user->email, 'password' => 'secret-pass-123'])->assertRedirect(route('mfa.setup'));
        $this->assertGuest(); // signed in only after the authenticator code
    }

    public function test_demo_tenant_is_scanned_on_creation_and_pages_render(): void
    {
        $t = $this->demo();
        $scan = $t->scans()->first();

        $this->assertNotNull($scan);
        $this->assertCount(count(Catalog::all()), $scan->results);
        $this->assertLessThan(30, $scan->score);
        $this->assertSame('fail', $this->checkStatus($t, 'APP-01'));
        $this->assertSame('na', $this->checkStatus($t, 'ID-06'), 'P2-only control should be not applicable on Business Premium');
        $this->assertSame('pass', $this->checkStatus($t, 'EML-01'));
        $this->assertSame('manual', $this->checkStatus($t, 'TEN-02'));
        $this->assertSame(0, $scan->results()->where('status', 'error')->count());

        $this->get(route('dashboard'))->assertOk()->assertSee($t->name);
        $this->get(route('tenants.show', $t))->assertOk()->assertSee('Users cannot register applications')->assertSee('Quick fix', false);
        $this->get(route('controls.index'))->assertOk()->assertSee('5.1.5.1');
        $this->get(route('baselines.index'))->assertOk()->assertSee('Identity essentials');
        $this->get(route('activity.index'))->assertOk()->assertSee('demo tenant');
        $this->get(route('settings.index'))->assertOk()->assertSee('Policy.ReadWrite.ConditionalAccess');
        $this->get(route('tenants.create'))->assertOk();
    }

    public function test_quick_fix_changes_tenant_rescans_and_is_audited(): void
    {
        $t = $this->demo();
        $before = $t->fresh()->last_score;

        $this->post(route('fix.store', [$t, 'APP-01']))->assertRedirect()->assertSessionHas('status');

        $t->refresh();
        $this->assertFalse($t->demo_state['authorizationPolicy']['defaultUserRolePermissions']['allowedToCreateApps']);
        $this->assertSame('pass', $this->checkStatus($t, 'APP-01'));
        $this->assertGreaterThan($before, $t->last_score);
        $this->assertTrue(ActivityLog::where(['check_id' => 'APP-01', 'outcome' => 'success'])->exists());

        // A second attempt has nothing to fix.
        $this->post(route('fix.store', [$t, 'APP-01']))->assertSessionHasErrors('fix');
    }

    public function test_conditional_access_guided_workflow_is_two_steps(): void
    {
        $t = $this->demo();

        $this->post(route('fix.store', [$t, 'ID-02']))->assertSessionHas('status');
        $policy = collect($t->fresh()->demo_state['conditionalAccess'])->firstWhere('displayName', 'TenantsGuard: Require MFA for all users');
        $this->assertSame('enabledForReportingButNotEnforced', $policy['state']);
        $this->assertSame('fail', $this->checkStatus($t, 'ID-02'));
        $this->assertSame(2, $t->scans()->first()->results()->where('check_id', 'ID-02')->first()->fix['step']);

        $this->post(route('fix.store', [$t, 'ID-02']))->assertSessionHas('status');
        $this->assertSame('pass', $this->checkStatus($t->fresh(), 'ID-02'));
    }

    public function test_baseline_push_fixes_multiple_tenants_without_enforcing_ca(): void
    {
        $a = $this->demo();
        $b = $this->demo();
        $baseline = Baseline::where('name', 'CIS Level 1 quick wins')->first();

        $this->post(route('baselines.apply', $baseline), ['tenant_ids' => [$a->id, $b->id]])
            ->assertRedirect(route('baselines.index').'#baseline-'.$baseline->id)->assertSessionHas('baseline_report');

        foreach ([$a, $b] as $t) {
            foreach ($baseline->check_ids as $id) {
                $this->assertSame('pass', $this->checkStatus($t->fresh(), $id), "{$id} should pass after baseline on {$t->name}");
            }
        }

        $identity = Baseline::where('name', 'Identity essentials')->first();
        $this->post(route('baselines.apply', $identity), ['tenant_ids' => [$a->id]]);
        $states = collect($a->fresh()->demo_state['conditionalAccess'])->where('displayName', '!=', 'Old pilot: MFA for IT')->pluck('state')->unique()->all();
        $this->assertSame(['enabledForReportingButNotEnforced'], array_values($states), 'Baselines must only create report-only CA policies');
        $this->get(route('baselines.index'))->assertOk();
    }

    public function test_drift_is_detected_after_out_of_band_change(): void
    {
        $t = $this->demo();
        $this->post(route('fix.store', [$t, 'APP-01']));
        $this->post(route('fix.store', [$t, 'COL-03']));

        $this->post(route('tenants.drift', $t))->assertSessionHas('status');
        $this->post(route('tenants.scan', $t))->assertSessionHas('status');

        $scan = $t->scans()->first();
        $this->assertNotEmpty($scan->drifted);
        $this->assertTrue(ActivityLog::where(['tenant_id' => $t->id, 'action' => 'drift'])->exists());
        $this->get(route('tenants.show', $t))->assertSee('Drift detected');
    }

    public function test_accepted_risk_is_excluded_from_score(): void
    {
        $t = $this->demo();
        // Raise the baseline score first so a single exclusion visibly moves the rounded score.
        $this->post(route('baselines.apply', Baseline::where('name', 'CIS Level 1 quick wins')->first()), ['tenant_ids' => [$t->id]]);
        $before = $t->fresh()->last_score;
        $highBefore = $t->scans()->first()->counts['fail_high'];

        $this->post(route('annotations.store', [$t, 'ID-10']), ['type' => 'accepted', 'note' => 'Five GAs approved by CEO until restructure'])
            ->assertSessionHas('status');
        $this->assertGreaterThan($before, $t->fresh()->last_score);
        $this->assertSame($highBefore - 1, $t->scans()->first()->counts['fail_high']);
        $this->assertTrue(Annotation::where('check_id', 'ID-10')->exists());

        $this->delete(route('annotations.destroy', [$t, 'ID-10']));
        $this->assertSame($before, $t->fresh()->last_score);
    }

    public function test_reports_render_and_excel_downloads(): void
    {
        $t = $this->demo();
        $first = $t->scans()->first();
        $this->post(route('fix.store', [$t, 'COL-02']));
        $latest = $t->scans()->first();

        $this->get(route('reports.show', $t))->assertOk()->assertSee('Executive summary')->assertSee('Framework alignment');
        $this->get(route('reports.show', [$t, 'scan' => $latest->id, 'compare' => $first->id]))
            ->assertOk()->assertSee('What changed')->assertSee('Only admins and Guest Inviters can invite guests');

        $res = $this->get(route('reports.excel', [$t, 'compare' => $first->id]));
        $res->assertOk();
        $this->assertStringContainsString('spreadsheetml', $res->headers->get('Content-Type'));
        $this->assertStringStartsWith('PK', $res->streamedContent());
    }

    public function test_scheduled_scan_command_and_live_tenant_without_consent(): void
    {
        $t = DemoTenant::create();
        $live = Tenant::create(['name' => 'Pending Co', 'ms_tenant_id' => '11111111-2222-3333-4444-555555555555', 'mode' => 'live']);

        $this->artisan('tenants:scan')->assertSuccessful();
        $this->assertSame(1, $t->scans()->count());
        $this->assertSame(0, $live->scans()->count());

        $this->actingAs($this->user)->get(route('tenants.show', $live))->assertOk()->assertSee('Grant admin consent');
        $this->artisan('tenants:prune')->assertSuccessful();
    }

    public function test_score_weights_by_severity(): void
    {
        $this->assertSame(100, Scanner::score([], []));
        $this->assertSame(0, Scanner::score(['ID-01' => 'fail'], []));
        // critical pass (10) vs low fail (1)
        $this->assertSame(91, Scanner::score(['ID-01' => 'pass', 'APP-04' => 'fail'], []));
        $this->assertSame(100, Scanner::score(['ID-01' => 'pass', 'APP-04' => 'fail'], ['APP-04']));
    }
}
