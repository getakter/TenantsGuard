<?php

namespace Tests\Feature;

use App\Models\PurviewProject;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Demo\DemoTenant;
use App\Services\Lifecycle\Directory;
use App\Services\Purview\Catalog;
use App\Services\Purview\Designer;
use App\Services\Purview\Scripts;
use App\Services\Scripts\ScriptExecutor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

/** Microsoft Purview programme: requirements, design, gates, automation, scripts, verification, documents. */
class PurviewTest extends TestCase
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
        (new Directory($this->tenant))->users();
        $this->actingAs($this->admin);
    }

    private function requirements(array $over = []): array
    {
        $users = collect($this->tenant->fresh()->demo_state['users'])->where('userType', 'Member')->take(3)->pluck('id')->all();

        return array_merge(Catalog::defaults(), [
            'project_name' => 'Harbour Dental - Data security', 'industry' => 'healthcare', 'region' => 'AU', 'users' => 15,
            'owner_name' => 'Dr Avery Patel', 'owner_email' => 'avery@brightwaterdental.example', 'start_date' => now()->toDateString(),
            'regulations' => ['au_privacy', 'pci'], 'data_types' => ['health', 'credentials', 'national_id'],
            'workloads' => ['exchange', 'sharepoint', 'onedrive', 'teams'], 'encryption' => 1, 'partners_need_access' => 1, 'container_labels' => 1,
            'dlp_style' => 'block_override', 'labelling' => 'recommend', 'baseline_years' => 7, 'teams_years' => 3,
            'record_classes' => ['health', 'finance', 'hr'], 'disposition_review' => 1, 'irm_concerns' => ['departing', 'leaks'],
            'pilot_users' => $users, 'pilot_weeks' => 4, 'next' => 'design',
        ], $over);
    }

    private function started(): PurviewProject
    {
        $this->post(route('purview.start'), ['tenant_id' => $this->tenant->id])->assertRedirect();
        $p = PurviewProject::firstOrFail();
        $this->post(route('purview.requirements.save', $p), $this->requirements())->assertRedirect(route('purview.design', $p));

        return $p->fresh();
    }

    public function test_design_follows_requirements_licences_and_region(): void
    {
        $p = $this->started();
        $d = $p->design;
        $caps = $p->capabilities;

        // Business Premium: labels and encryption yes; auto-labelling, Teams DLP, insider risk, records no.
        $this->assertTrue($caps['labels']);
        $this->assertTrue($caps['encryption']);
        $this->assertFalse($caps['auto_label']);
        $this->assertFalse($caps['irm']);

        $this->assertContains('HighlyConfidential-SpecifiedPeople', array_column($d['labels'], 'key'));
        $this->assertContains('Confidential-Anyone', array_column($d['labels'], 'key'), 'partners need unencrypted sharing');
        $names = array_column($d['dlp'], 'name');
        $this->assertContains('TG - Payment card data', $names, 'PCI regulation implies payment cards');
        $this->assertContains('TG - Health information', $names);
        $ids = collect($d['dlp'])->firstWhere('name', 'TG - Personal identifiers');
        $this->assertContains('Australia Tax File Number', $ids['sits']);
        $this->assertNotContains('U.S. Social Security Number (SSN)', $ids['sits'], 'region drives identifiers');
        $this->assertNotContains('Teams', $ids['locations'], 'Teams DLP is not licensed');
        $this->assertSame([], $d['autolabel'], 'auto-labelling not licensed');
        $this->assertFalse(collect($d['irm']['policies'])->contains('include', true));
        $this->assertSame(7, collect($d['retention']['labels'])->firstWhere('key', 'rl-health')['years']);
        $this->assertSame('delete', collect($d['retention']['labels'])->firstWhere('key', 'rl-health')['action'], 'no records management licence: no disposition review');
        $gaps = array_column(Designer::gaps($p->requirements, $caps), 'capability');
        $this->assertContains('irm', $gaps);
        $this->assertContains('dlp_teams', $gaps);

        $this->get(route('purview.show', $p))->assertOk()->assertSee('Licence readiness')->assertSee('Licence gaps affect the design');
        $this->get(route('purview.design', $p))->assertOk()->assertSee('Architecture decisions')->assertSee('Australia Tax File Number')->assertSee('Approve the design');
        $this->assertGreaterThan(15, $p->tasks()->count());
    }

    public function test_gates_lock_phases_and_approval_is_invalidated_by_changes(): void
    {
        $p = $this->started();
        $task = $p->tasks()->where('key', 'labels_create')->first();
        $this->post(route('purview.task.update', [$p, $task]), ['status' => 'done'])->assertSessionHasErrors('task');
        $this->get(route('purview.script', [$p, 'discover']))->assertSessionHasErrors('script');

        $this->post(route('purview.approve', $p), ['confirm' => 1, 'note' => 'Steering meeting'])->assertRedirect(route('purview.plan', $p));
        $p->refresh();
        $this->assertTrue($p->isApproved());
        $this->assertSame('foundations', $p->stage);
        $this->assertSame('done', $p->tasks()->where('key', 'gate_design')->first()->status);

        // Pilot is locked until the discover gate; the gate needs every discover task.
        $pilotTask = $p->tasks()->where('key', 'retention_labels')->first();
        $this->post(route('purview.task.run', [$p, $pilotTask]))->assertSessionHasErrors('task');
        $this->post(route('purview.signoff', [$p, 'discover']), ['confirm' => 1])->assertSessionHasErrors('gate');
        foreach ($p->tasks()->where('phase', 'discover')->where('method', '!=', 'gate')->get() as $t) {
            $this->post(route('purview.task.update', [$p, $t]), ['status' => 'skipped'])->assertSessionHasErrors('task'); // needs a reason
            $this->post(route('purview.task.update', [$p, $t]), ['status' => 'done', 'note' => 'done in workshop']);
        }
        $this->post(route('purview.signoff', [$p, 'discover']), ['confirm' => 1, 'note' => 'Simulation reviewed'])->assertSessionHas('status');
        $p->refresh();
        $this->assertSame('pilot', $p->stage);
        $this->assertTrue($p->unlocked('pilot'));

        // Retention labels are created through Graph from the approved schedule.
        $this->post(route('purview.task.run', [$p, $pilotTask]))->assertSessionHas('status');
        $created = array_column($this->tenant->fresh()->demo_state['retentionLabels'], 'displayName');
        $this->assertContains('Health records', $created);
        $this->post(route('purview.task.run', [$p, $pilotTask->fresh()]))->assertStatus(302);

        // Editing the design afterwards needs a new approval.
        $d = $p->design;
        $form = $this->designForm($d);
        $form['baseline']['years'] = 10;
        $this->post(route('purview.design.update', $p), $form)->assertSessionHas('status');
        $p->refresh();
        $this->assertFalse($p->isApproved());
        $this->assertSame(10, $p->design['retention']['baseline']['years']);
        $this->assertFalse($p->unlocked('discover'));
    }

    private function designForm(array $d): array
    {
        return [
            'labels' => array_map(fn ($l) => ['display' => $l['display'], 'tooltip' => $l['tooltip'], 'encryption' => $l['encryption'], 'header' => $l['header'], 'watermark' => $l['watermark'], 'include' => $l['include'] ? 1 : null], $d['labels']),
            'policy' => ['default_document' => 'General', 'justification' => 1],
            'dlp' => array_map(fn ($x) => ['include' => 1, 'high_threshold' => $x['high_threshold'], 'style' => $x['style'], 'locations' => $x['locations']], $d['dlp']),
            'baseline' => ['years' => $d['retention']['baseline']['years']], 'teams' => ['years' => $d['retention']['teams']['years']],
            'retention' => array_map(fn ($r) => ['include' => 1, 'years' => $r['years'], 'action' => $r['action'], 'name' => $r['name']], $d['retention']['labels']),
            'pilot' => ['group_name' => 'Purview Pilot', 'mail_nickname' => 'purviewpilot'],
        ];
    }

    public function test_automation_creates_the_pilot_group_with_members(): void
    {
        $p = $this->started();
        $this->post(route('purview.approve', $p), ['confirm' => 1]);
        $this->post(route('purview.task.run', [$p, $p->tasks()->where('key', 'licences')->first()]))->assertSessionHas('status');
        $this->post(route('purview.task.run', [$p, $p->tasks()->where('key', 'pilot_group')->first()]))->assertSessionHas('status');
        $group = collect($this->tenant->fresh()->demo_state['groups'])->firstWhere('mailNickname', 'purviewpilot');
        $this->assertNotNull($group);
        $this->assertSame(['Unified'], $group['groupTypes']);
        $this->assertCount(3, $group['members']);
        $this->assertSame('ok', $p->tasks()->where('key', 'pilot_group')->first()->verify_status);
    }

    public function test_scripts_are_generated_from_the_approved_design_and_parse(): void
    {
        $p = $this->started();
        $this->post(route('purview.approve', $p), ['confirm' => 1]);
        $res = $this->get(route('purview.script', [$p, 'discover']))->assertOk();
        $ps = $res->getContent();
        $this->assertStringContainsString('SupportsShouldProcess', $ps);
        $this->assertStringContainsString('Wrong tenant', $ps);
        $this->assertStringContainsString("New-Label -Name 'HighlyConfidential'", $ps);
        $this->assertStringContainsString('-Mode TestWithoutNotifications', $ps);
        $this->assertStringContainsString("'Australia Tax File Number'", $ps);
        $this->assertStringContainsString("EncryptionProtectionType = 'UserDefined'", $ps);
        $this->get(route('purview.script', [$p, 'pilot']))->assertSessionHasErrors('script');
        $this->get(route('purview.script', [$p, 'verify']))->assertOk()->assertSee('tenantsguard-purview-verification', false);

        // Quote escaping: a label tooltip with an apostrophe stays a valid string.
        $d = $p->design;
        $d['labels'][0]['tooltip'] = "Don't share; it's public anyway";
        $p->update(['design' => $d]);
        $this->assertStringContainsString("'Don''t share; it''s public anyway'", Scripts::build($p->fresh(), 'discover', ['brightwaterdental.example']));

        if (! ScriptExecutor::pwsh()) {
            $this->markTestSkipped('PowerShell 7 not installed; parse check skipped.');
        }
        $p->update(['signoffs' => [['gate' => 'discover', 'by' => 1, 'name' => 'x', 'at' => now()->toIso8601String()], ['gate' => 'pilot', 'by' => 1, 'name' => 'x', 'at' => now()->toIso8601String()]]]);
        $dir = storage_path('app/purview-parse-'.uniqid());
        @mkdir($dir, 0777, true);
        foreach (['foundations', 'discover', 'pilot', 'production', 'verify'] as $phase) {
            file_put_contents("{$dir}/{$phase}.ps1", Scripts::build($p->fresh(), $phase, ['brightwaterdental.example', 'brightwaterdental.onmicrosoft.com']));
        }
        $cmd = '$bad = 0; Get-ChildItem '.escapeshellarg($dir).' -Filter *.ps1 | ForEach-Object { $e = $null; [void][System.Management.Automation.Language.Parser]::ParseFile($_.FullName, [ref]$null, [ref]$e); foreach ($x in $e) { $bad++; Write-Output ($_.Name + \': \' + $x.Message + \' line \' + $x.Extent.StartLineNumber) } }; Write-Output "ERRORS=$bad"';
        $out = shell_exec('"'.ScriptExecutor::pwsh().'" -NoProfile -NonInteractive -Command '.escapeshellarg($cmd));
        array_map('unlink', glob("{$dir}/*.ps1"));
        @rmdir($dir);
        $this->assertStringContainsString('ERRORS=0', (string) $out, (string) $out);
    }

    public function test_verification_export_marks_tasks_and_rejects_other_tenants(): void
    {
        $p = $this->started();
        $this->post(route('purview.approve', $p), ['confirm' => 1]);
        $d = $p->fresh()->design;
        $export = [
            'kind' => 'tenantsguard-purview-verification', 'version' => 1, 'tenantId' => $this->tenant->ms_tenant_id, 'generatedAt' => now()->toIso8601String(),
            'labels' => array_map(fn ($l) => ['Name' => $l['key']], $d['labels']),
            'labelPolicies' => [], 'autoLabelPolicies' => [], 'retentionPolicies' => [], 'retentionLabels' => [],
            'dlpPolicies' => array_map(fn ($x) => ['Name' => $x['name'], 'Mode' => 'TestWithoutNotifications'], array_slice($d['dlp'], 0, 1)),
            'labelCoauth' => true, 'auditEnabled' => true,
        ];
        $upload = fn (array $data) => $this->post(route('purview.verify', $p), ['export' => UploadedFile::fake()->createWithContent('v.json', json_encode($data))]);

        $upload(['kind' => 'something-else'])->assertSessionHasErrors('export');
        $upload(['tenantId' => '11111111-2222-3333-4444-555555555555'] + $export)->assertSessionHasErrors('export');
        $this->assertNull($p->fresh()->verification);

        $upload($export)->assertSessionHas('status');
        $tasks = $p->tasks()->get()->keyBy('key');
        $this->assertSame('ok', $tasks['audit']->verify_status);
        $this->assertSame('done', $tasks['audit']->status);
        $this->assertSame('ok', $tasks['labels_create']->verify_status);
        $this->assertSame('partial', $tasks['dlp_simulation']->verify_status, 'only one of the DLP policies exists');
        $this->get(route('purview.implement', $p))->assertOk()->assertSee('Verification results')->assertSee('Partly in place');
    }

    public function test_documents_and_permissions(): void
    {
        $p = $this->started();
        $this->get(route('purview.document', [$p, 'design']))->assertOk()->assertSee('Architecture decisions')->assertSee('RACI')->assertSee('DRAFT')->assertSee('Australia Tax File Number');
        $this->get(route('purview.document', [$p, 'announcement']))->assertOk()->assertSee('What you need to do');
        $this->get(route('purview.document', [$p, 'guide']))->assertOk()->assertSee('Which sensitivity label do I use?');
        $this->get(route('purview.plan', $p))->assertOk()->assertSee('Foundations')->assertSee('Turn on unified audit logging');
        $this->get(route('purview.index'))->assertOk()->assertSee('Harbour Dental (Demo)');

        $tech = User::create(['name' => 'T', 'email' => 't@msp.test', 'password' => 'Password123', 'role_id' => Role::byKey('technician')->id, 'all_tenants' => true, 'is_active' => true]);
        $this->actingAs($tech);
        $this->get(route('purview.show', $p))->assertOk();
        $this->post(route('purview.approve', $p), ['confirm' => 1])->assertForbidden();
        $this->post(route('purview.requirements.save', $p), $this->requirements())->assertForbidden();

        $scoped = User::create(['name' => 'S', 'email' => 's@msp.test', 'password' => 'Password123', 'role_id' => Role::byKey('administrator')->id, 'all_tenants' => false, 'is_active' => true]);
        $this->actingAs($scoped);
        $this->get(route('purview.show', $p))->assertForbidden();
        $this->get(route('purview.index'))->assertOk()->assertDontSee('Harbour Dental (Demo)');
    }
}
