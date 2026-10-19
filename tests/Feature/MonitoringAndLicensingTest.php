<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\SecurityAlert;
use App\Models\Setting;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Demo\DemoTenant;
use App\Services\Licensing\LicenseInsights;
use App\Services\Monitoring\SecurityMonitor;
use App\Services\Scanner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Security monitoring (alerts) and licence optimisation. */
class MonitoringAndLicensingTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private string $domain;

    protected function setUp(): void
    {
        parent::setUp();
        config(['tenantsguard.admin.password' => 'secret-pass-123']);
        $this->seed();
        $this->tenant = DemoTenant::create('Harbour Physio (Demo)');
        $this->domain = $this->tenant->demo_state['domains'][0]['id'];
        $this->actingAs(User::first());
    }

    private function staff(string $role, array $tenants = []): User
    {
        $u = User::create(['name' => ucfirst($role), 'email' => $role.'@msp.test', 'password' => 'Password123',
            'role_id' => Role::byKey($role)->id, 'all_tenants' => ! $tenants, 'is_active' => true]);
        $u->tenants()->sync($tenants);

        return $u;
    }

    public function test_monitor_raises_deduplicated_alerts_and_marks_unavailable_rules(): void
    {
        $r = (new SecurityMonitor($this->tenant))->run();
        $this->assertSame(5, $r['new'], 'role, consent, CA change, Defender alert, service incident');
        $this->assertSame('ok', $r['status']['role_assignment']);
        $this->assertStringStartsWith('Not available', $r['status']['risky_user'], 'demo has no Entra ID P2');

        $role = SecurityAlert::where('rule', 'role_assignment')->firstOrFail();
        $this->assertSame('critical', $role->severity, 'Global Administrator is Tier 0');
        $this->assertStringContainsString('Global Administrator', $role->title);
        $this->assertFalse(SecurityAlert::where('title', 'like', 'Informational%')->exists(), 'informational Defender alerts are skipped');
        $this->assertFalse(SecurityAlert::where('title', 'like', '%reports delayed%')->exists(), 'advisories are not incidents');
        $this->assertSame(0, SecurityAlert::where('rule', 'break_glass_signin')->count(), 'no break-glass accounts configured yet');

        // Second pass: nothing new (dedupe), audit window moves on.
        $this->assertSame(0, (new SecurityMonitor($this->tenant->fresh()))->run()['new']);
        $this->assertNotNull($this->tenant->fresh()->monitored_at);
    }

    public function test_break_glass_and_simulated_attack(): void
    {
        $this->post(route('alerts.settings', $this->tenant), ['break_glass' => "breakglass01@{$this->domain}, not-an-email", 'rules' => array_keys(SecurityMonitor::RULES)])
            ->assertSessionHasErrors('break_glass');
        $this->post(route('alerts.settings', $this->tenant), ['break_glass' => "BreakGlass01@{$this->domain}", 'rules' => array_keys(SecurityMonitor::RULES)])
            ->assertSessionHas('status');
        $this->assertSame(["breakglass01@{$this->domain}"], SecurityMonitor::breakGlass($this->tenant->fresh()));

        $this->post(route('alerts.run', $this->tenant))->assertSessionHas('status');
        $bg = SecurityAlert::where('rule', 'break_glass_signin')->firstOrFail();
        $this->assertSame('critical', $bg->severity);
        $this->assertSame('NG', explode(', ', $bg->evidence['location'])[1]);

        $this->post(route('alerts.simulate', $this->tenant))->assertSessionHas('status');
        foreach (['federation_change' => 'critical', 'app_credential' => 'high', 'mfa_removed' => 'medium', 'ca_change' => 'high'] as $rule => $sev) {
            $this->assertTrue(SecurityAlert::where('rule', $rule)->where('severity', $sev)->exists(), "{$rule} {$sev}");
        }
        $grant = SecurityAlert::where('rule', 'app_consent')->where('title', 'like', '%Invoice Reader%')->get();
        $this->assertCount(1, $grant, 'one alert per consent, not per permission');
        $this->assertSame('high', $grant[0]->severity);
        $this->assertStringContainsString('Mail.ReadWrite', $grant[0]->evidence['sensitive']);
        $this->assertSame(3, $grant[0]->evidence['permissions']);
        $this->assertDatabaseHas('activity_logs', ['action' => 'alert.raised']);
    }

    public function test_disabled_rules_and_own_changes_are_ignored(): void
    {
        $this->post(route('alerts.settings', $this->tenant), ['rules' => ['service_incident']]);
        (new SecurityMonitor($this->tenant->fresh()))->run();
        $this->assertSame(['service_incident'], SecurityAlert::pluck('rule')->unique()->values()->all());

        // Changes made by TenantsGuard's own app are audited elsewhere and never alert.
        config(['tenantsguard.microsoft.client_id' => 'tg-app-id']);
        Setting::put('ms_client_id', 'tg-app-id');
        $s = $this->tenant->fresh()->demo_state;
        $s['audits'][] = ['id' => 'own-1', 'activityDisplayName' => 'Add conditional access policy', 'activityDateTime' => now()->toIso8601ZuluString(), 'result' => 'success',
            'initiatedBy' => ['app' => ['appId' => 'tg-app-id', 'displayName' => 'TenantsGuard']], 'targetResources' => [['displayName' => 'TG - Require MFA']]];
        $this->tenant->update(['demo_state' => $s]);
        $this->post(route('alerts.settings', $this->tenant), ['rules' => array_keys(SecurityMonitor::RULES)]);
        (new SecurityMonitor($this->tenant->fresh()))->run();
        $this->assertFalse(SecurityAlert::where('dedupe_key', 'own-1')->exists());
    }

    public function test_state_alerts_clear_at_source_and_triage_workflow(): void
    {
        (new SecurityMonitor($this->tenant))->run();
        $incident = SecurityAlert::where('rule', 'service_incident')->firstOrFail();

        $s = $this->tenant->fresh()->demo_state;
        $s['serviceIssues'][0]['isResolved'] = true;
        $s['defenderAlerts'][0]['status'] = 'resolved';
        $this->tenant->update(['demo_state' => $s]);
        $r = (new SecurityMonitor($this->tenant->fresh()))->run();
        $this->assertSame(2, $r['cleared']);
        $this->assertSame('cleared_at_source', $incident->fresh()->resolution);

        $role = SecurityAlert::where('rule', 'role_assignment')->firstOrFail();
        $this->post(route('alerts.update', $role), ['action' => 'acknowledge', 'note' => 'Calling the client'])->assertSessionHas('status');
        $role->refresh();
        $this->assertSame('acknowledged', $role->status);
        $this->assertSame(User::first()->id, $role->assigned_to);
        $this->assertStringContainsString('Calling the client', $role->note);
        $this->post(route('alerts.update', $role), ['action' => 'resolve', 'resolution' => 'benign']);
        $this->assertSame('benign', $role->fresh()->resolution);

        $open = SecurityAlert::open()->pluck('id')->all();
        $this->post(route('alerts.bulk'), ['ids' => $open, 'action' => 'resolve', 'resolution' => 'true_positive'])->assertSessionHas('status');
        $this->assertSame(0, SecurityAlert::open()->count());
        $this->assertDatabaseHas('activity_logs', ['action' => 'alert.resolve']);
    }

    public function test_alert_pages_portfolio_and_access_control(): void
    {
        (new SecurityMonitor($this->tenant))->run();
        $this->get(route('alerts.index'))->assertOk()->assertSee('Security alerts')->assertSee('Global Administrator')->assertSee('Harbour Physio (Demo)');
        $this->get(route('alerts.index', ['severity' => 'critical']))->assertOk()->assertSee('Global Administrator')->assertDontSee('PDF Converter Pro');
        $this->get(route('alerts.tenant', $this->tenant))->assertOk()->assertSee('No break-glass accounts set')->assertSee('Simulate suspicious activity')->assertSee('Unavailable');
        $this->get(route('dashboard'))->assertOk()->assertSee('critical or high security alert(s) open');

        $other = DemoTenant::create('Other Clinic (Demo)');
        $tech = $this->staff('technician', [$other->id]);
        $this->actingAs($tech)->get(route('alerts.index'))->assertOk()->assertDontSee('Global Administrator');
        $this->actingAs($tech)->get(route('alerts.tenant', $this->tenant))->assertForbidden();
        $alert = SecurityAlert::firstOrFail();
        $this->actingAs($tech)->post(route('alerts.update', $alert), ['action' => 'resolve'])->assertForbidden();
        $this->actingAs($tech)->post(route('alerts.bulk'), ['ids' => [$alert->id], 'action' => 'resolve']);
        $this->assertNotSame('resolved', $alert->fresh()->status, 'bulk only touches visible tenants');

        $ro = $this->staff('read_only');
        $this->actingAs($ro)->get(route('alerts.tenant', $this->tenant))->assertOk()->assertDontSee('Check now');
        $this->actingAs($ro)->post(route('alerts.run', $this->tenant))->assertForbidden();
    }

    public function test_licence_insights_find_waste(): void
    {
        $r = (new LicenseInsights($this->tenant))->analyse(90);
        $this->assertTrue($r['signins']);
        $this->assertEqualsCanonicalizing(['Drew Kim', 'Reese Morgan', 'Old Scanner'], array_column($r['inactive'], 'name'));
        $this->assertCount(1, $r['overlaps']);
        $this->assertSame('Exchange Online (Plan 1)', $r['overlaps'][0]['redundant']);
        $this->assertSame('Microsoft 365 Business Premium', $r['overlaps'][0]['covered_by']);
        $copilot = collect($r['skus'])->firstWhere('part', 'Microsoft_365_Copilot');
        $this->assertSame(5, $copilot['unused']);
        $this->assertSame(150.0, $copilot['waste']);
        $this->assertGreaterThan(0, $r['totals']['savings']);
        $this->assertEqualsWithDelta($r['totals']['unused_cost'] + $r['totals']['inactive_cost'] + $r['totals']['overlap_cost'] + $r['totals']['disabled_cost'], $r['totals']['savings'], 0.01);

        // Disabled accounts are reported as disabled, not as idle.
        $s = $this->tenant->fresh()->demo_state;
        $i = collect($s['users'])->search(fn ($u) => $u['displayName'] === 'Reese Morgan');
        $s['users'][$i]['accountEnabled'] = false;
        $this->tenant->update(['demo_state' => $s]);
        $r = (new LicenseInsights($this->tenant->fresh()))->analyse(90);
        $this->assertSame(['Reese Morgan'], array_column($r['disabled'], 'name'));
        $this->assertNotContains('Reese Morgan', array_column($r['inactive'], 'name'));

        $this->assertCount(1, (new LicenseInsights($this->tenant->fresh()))->analyse(180)['inactive'], 'threshold changes the result');
    }

    public function test_licence_pages_export_and_prices(): void
    {
        $this->get(route('licensing.index'))->assertOk()->assertSee('Licence optimisation')->assertSee('Harbour Physio (Demo)')->assertSee('Potential savings');
        $this->get(route('licensing.tenant', $this->tenant))->assertOk()->assertSee('Duplicate licences')->assertSee('Old Scanner')->assertSee('Offboard');
        $csv = $this->get(route('licensing.export', $this->tenant))->assertOk()->assertHeader('Content-Type', 'text/csv; charset=utf-8')->getContent();
        $this->assertStringContainsString('Duplicate licence', $csv);
        $this->assertStringContainsString('Unused seats', $csv);

        $this->post(route('licensing.prices'), ['currency' => 'aud', 'prices' => ['SPB' => 33], 'new_sku' => 'POWERAPPS_PER_USER', 'new_price' => 20])->assertSessionHas('status');
        $this->assertSame('AUD', LicenseInsights::currency());
        $this->assertSame(33.0, LicenseInsights::prices()['SPB']);
        $this->assertSame(20.0, LicenseInsights::prices()['POWERAPPS_PER_USER']);
        $this->assertSame('AUD', (new LicenseInsights($this->tenant))->analyse()['currency']);

        $tech = $this->staff('technician');
        $this->actingAs($tech)->get(route('licensing.index'))->assertOk();
        $this->actingAs($tech)->post(route('licensing.prices'), ['currency' => 'USD'])->assertForbidden();
    }

    public function test_onboarding_checklist_includes_access_and_monitoring(): void
    {
        app(Scanner::class)->scan($this->tenant);
        $this->get(route('tenants.show', $this->tenant))->assertOk()->assertSee('Next: Set up security monitoring');
        $this->post(route('alerts.settings', $this->tenant), ['break_glass' => "bg@{$this->domain}", 'rules' => array_keys(SecurityMonitor::RULES)]);
        $this->get(route('tenants.show', $this->tenant))->assertDontSee('Next: Set up security monitoring')->assertSee('Next: Choose a control plan');
    }

    public function test_scheduled_commands_run(): void
    {
        $this->artisan('tenants:monitor')->assertSuccessful();
        $this->assertGreaterThan(0, SecurityAlert::count());
        $this->artisan('licensing:refresh')->assertSuccessful();
        $this->assertNotNull(LicenseInsights::cached($this->tenant));
    }
}
