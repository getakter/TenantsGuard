<?php

namespace Tests\Feature;

use App\Models\ComplianceProfile;
use App\Models\FrameworkAttestation;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Compliance\ComplianceEngine;
use App\Services\Compliance\FrameworkRegistry;
use App\Services\Demo\DemoTenant;
use App\Services\Scanner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ComplianceTest extends TestCase
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
        $this->tenant = DemoTenant::create('Sydney Dental (Demo)');
        app(Scanner::class)->scan($this->tenant);
        $this->actingAs($this->admin);
    }

    private function profile(string $prefix): ComplianceProfile
    {
        return ComplianceProfile::where('name', 'like', $prefix.'%')->firstOrFail();
    }

    public function test_registry_has_essential_eight_asd_hipaa_and_valid_mappings(): void
    {
        $e8 = FrameworkRegistry::find('e8');
        $this->assertSame('Australian Signals Directorate (ASD)', $e8['publisher']);
        $this->assertSame([1, 2, 3], $e8['levels']);
        $this->assertCount(8, collect($e8['requirements'])->pluck('group')->unique());
        $this->assertNotNull(FrameworkRegistry::find('asd_ism'));
        $this->assertNotNull(FrameworkRegistry::find('hipaa'));
        foreach (['cis_m365', 'cisv8', 'nistcsf', 'nist80053', 'iso27001', 'pci', 'ce'] as $k) {
            $this->assertNotEmpty(FrameworkRegistry::find($k)['requirements'], $k);
        }
        foreach (FrameworkRegistry::all() as $key => $fw) {
            foreach ($fw['requirements'] as $r) {
                foreach ($r['controls'] as $c) {
                    $this->assertNotNull(FrameworkRegistry::control($c), "{$key} {$r['id']} references unknown control {$c}");
                }
            }
        }
        $this->assertContains('e8', FrameworkRegistry::frameworksForControl('ID-02'));
        $this->assertContains('hipaa', FrameworkRegistry::frameworksForControl('WIN-SEC-02'));
    }

    public function test_essential_eight_maturity_follows_requirements_and_attestations(): void
    {
        $this->tenant->update(['compliance_profile_id' => $this->profile('Australia: Essential Eight ML2')->id]);
        $res = ComplianceEngine::evaluate($this->tenant->fresh());

        $this->assertSame(2, $res['e8']['target']);
        $this->assertSame(0, $res['e8']['maturity']['Multi-factor authentication']['achieved']);
        $this->assertArrayNotHasKey('E8-MFA-3.1', collect($res['e8']['requirements'])->keyBy('id')->all(), 'ML3 requirements hidden for ML2 target');
        $this->assertSame('manual', collect($res['e8']['requirements'])->firstWhere('id', 'E8-RB-1.1')['status']);

        foreach (['E8-MFA-1.1', 'E8-MFA-1.2'] as $req) {
            $this->post(route('compliance.attest', [$this->tenant, 'e8', $req]), ['status' => 'implemented', 'note' => 'Verified CA policies with client IT lead'])->assertRedirect();
        }
        $res = ComplianceEngine::evaluate($this->tenant->fresh());
        $this->assertSame(1, $res['e8']['maturity']['Multi-factor authentication']['achieved']);
        $this->assertArrayHasKey('asd_ism', $res);
    }

    public function test_profile_scope_excludes_controls_from_frameworks_and_score(): void
    {
        $p = $this->profile('US healthcare');
        $before = $this->tenant->fresh()->last_score;
        $highBefore = $this->tenant->scans()->first()->counts['fail_high'];

        $this->get(route('compliance.profiles.edit', $p))->assertOk()->assertSee('Controls, scope and phases')->assertSee('HIPAA');
        $offered = $p->candidateControls();
        $inScope = array_values(array_diff($offered, ['ID-10', 'ID-11']));
        $this->put(route('compliance.profiles.update', $p), [
            'name' => $p->name, 'frameworks' => ['hipaa', 'nistcsf', 'cisv8'], 'exclude_from_score' => 1,
            'offered' => $offered, 'in_scope' => $inScope, 'reason' => ['ID-10' => 'Client uses PIM with 6 GAs approved by board'],
            'tenant_ids' => [$this->tenant->id],
        ])->assertRedirect(route('compliance.profiles.edit', $p));

        $p->refresh();
        $this->assertSame('Client uses PIM with 6 GAs approved by board', $p->excluded_controls['ID-10']);
        $this->assertSame('Out of scope for this customer', $p->excluded_controls['ID-11']);
        $this->assertSame($p->id, $this->tenant->fresh()->compliance_profile_id);
        $this->assertGreaterThanOrEqual($before, $this->tenant->fresh()->last_score);
        $this->assertSame($highBefore - 2, $this->tenant->scans()->first()->counts['fail_high'], 'ID-10 and ID-11 (high) no longer count');

        $statuses = ComplianceEngine::controlStatuses($this->tenant->fresh(), $p);
        $this->assertSame('out_of_scope', $statuses['ID-10']);
        $this->get(route('tenants.show', $this->tenant))->assertSee('Out of scope: '.$p->name);

        // Re-including restores it.
        $this->put(route('compliance.profiles.update', $p), ['name' => $p->name, 'frameworks' => ['hipaa'], 'offered' => ['ID-10'], 'in_scope' => ['ID-10'], 'tenant_ids' => [$this->tenant->id]]);
        $this->assertArrayNotHasKey('ID-10', $p->fresh()->excluded_controls);
    }

    public function test_custom_profile_with_chosen_frameworks(): void
    {
        $this->get(route('compliance.profiles.create'))->assertOk()->assertSee('Essential Eight')->assertSee('Maturity Level 3');
        $this->post(route('compliance.profiles.store'), ['name' => 'NDIS provider (AU)', 'frameworks' => ['e8', 'hipaa'], 'e8_level' => 3])->assertRedirect();
        $p = ComplianceProfile::where('name', 'NDIS provider (AU)')->first();
        $this->assertSame(3, $p->targetLevel('e8'));
        $this->assertContains('ID-14', $p->candidateControls(), 'ML3 phishing-resistant MFA included at target ML3');

        $this->post(route('compliance.profiles.store'), ['name' => 'Empty'])->assertSessionHasErrors('frameworks');
    }

    public function test_scorecard_reports_and_rbac(): void
    {
        $this->tenant->update(['compliance_profile_id' => $this->profile('Australia: Essential Eight ML2')->id]);

        $this->get(route('compliance.index'))->assertOk()->assertSee('Essential Eight')->assertSee('ML0 of ML2');
        $this->get(route('compliance.framework', 'e8'))->assertOk()->assertSee('Restrict Microsoft Office macros');
        $this->get(route('compliance.framework', 'hipaa'))->assertOk()->assertSee('Automatic logoff');
        $this->get(route('compliance.tenant', $this->tenant))->assertOk()->assertSee('Mitigation strategy')->assertSee('Attest this requirement');
        $this->get(route('reports.show', $this->tenant))->assertOk()->assertSee('Compliance: Australia');
        $this->assertStringStartsWith('PK', $this->get(route('reports.excel', $this->tenant))->streamedContent());

        $ro = User::create(['name' => 'RO', 'email' => 'ro@msp.test', 'password' => 'Password123', 'role_id' => Role::byKey('read_only')->id, 'all_tenants' => true, 'is_active' => true]);
        $this->actingAs($ro);
        $this->get(route('compliance.tenant', $this->tenant))->assertOk()->assertDontSee('Attest this requirement');
        $this->post(route('compliance.attest', [$this->tenant, 'e8', 'E8-RB-1.1']), ['status' => 'implemented', 'note' => 'x'])->assertForbidden();
        $this->get(route('compliance.profiles.create'))->assertForbidden();

        $tech = User::create(['name' => 'T', 'email' => 't@msp.test', 'password' => 'Password123', 'role_id' => Role::byKey('technician')->id, 'all_tenants' => true, 'is_active' => true]);
        $this->actingAs($tech);
        $this->post(route('compliance.attest', [$this->tenant, 'e8', 'E8-RB-1.1']), ['status' => 'implemented', 'note' => 'Datto SaaS Protection, daily, 1 year retention', 'evidence_url' => 'https://contoso.sharepoint.com/backup.pdf'])->assertRedirect();
        $this->assertSame('implemented', FrameworkAttestation::where('requirement', 'E8-RB-1.1')->value('status'));
        $this->post(route('compliance.assign', $this->tenant), ['compliance_profile_id' => null])->assertForbidden();
        $this->delete(route('compliance.unattest', [$this->tenant, 'e8', 'E8-RB-1.1']))->assertRedirect();
        $this->assertSame(0, FrameworkAttestation::count());
    }
}
