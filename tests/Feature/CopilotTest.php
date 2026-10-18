<?php

namespace Tests\Feature;

use App\Models\CopilotProject;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Copilot\Catalog;
use App\Services\Copilot\Scripts;
use App\Services\Demo\DemoTenant;
use App\Services\Lifecycle\Directory;
use App\Services\Scanner;
use App\Services\Scripts\ScriptExecutor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Microsoft 365 Copilot programme: assessment, prerequisites, gates, licences, groups, usage, scripts, documents. */
class CopilotTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Tenant $tenant;

    private array $members;

    protected function setUp(): void
    {
        parent::setUp();
        config(['tenantsguard.admin.password' => 'secret-pass-123']);
        $this->seed();
        $this->admin = User::first();
        $this->tenant = DemoTenant::create('Harbour Dental (Demo)');
        (new Directory($this->tenant))->users();
        app(Scanner::class)->scan($this->tenant->fresh());
        $this->members = collect($this->tenant->fresh()->demo_state['users'])->where('userType', 'Member')->pluck('id')->values()->all();
        $this->actingAs($this->admin);
    }

    private function started(array $over = []): CopilotProject
    {
        $this->post(route('copilot.start'), ['tenant_id' => $this->tenant->id])->assertRedirect();
        $p = CopilotProject::firstOrFail();
        $this->post(route('copilot.requirements.save', $p), array_merge(Catalog::defaults(), [
            'project_name' => 'Harbour Dental Copilot', 'sponsor_name' => 'Dr Avery Patel', 'sponsor_email' => 'avery@brightwaterdental.example',
            'start_date' => now()->toDateString(), 'seats_planned' => 5, 'goals' => ['meetings', 'documents'], 'target_active' => 70, 'target_hours' => 2, 'target_satisfaction' => 80,
            'data_approach' => 'balanced', 'sensitive_sites' => "https://brightwater.sharepoint.com/sites/HR\nhttps://brightwater.sharepoint.com/sites/Finance",
            'allowed_sites' => '', 'fix_links' => 1, 'web_search' => 1, 'agents' => 'approved', 'meetings' => 'with_transcript', 'copilot_chat' => 1,
            'champions' => 2, 'training' => 'both', 'pilot_users' => array_slice($this->members, 0, 3), 'pilot_weeks' => 6, 'wave_size' => 1, 'wave_weeks' => 2, 'next' => 'assess',
        ], $over))->assertRedirect(route('copilot.show', $p));

        return $p->fresh();
    }

    private function finishPhase(CopilotProject $p, string $phase): void
    {
        foreach ($p->tasks()->where('phase', $phase)->where('method', '!=', 'gate')->where('stale', false)->get() as $t) {
            $this->post(route('copilot.task.update', [$p, $t]), ['status' => 'done', 'note' => 'done']);
        }
    }

    public function test_assessment_reads_licences_users_security_and_oversharing(): void
    {
        $p = $this->started();
        $a = $p->assessment;
        $this->assertSame(5, $a['copilot']['total']);
        $this->assertSame(0, $a['copilot']['used']);
        $this->assertSame('pass', $a['checks']['licences_copilot']['status']);
        $this->assertSame('pass', $a['checks']['pilot_services']['status'], 'Business Premium gives mailbox, OneDrive, Teams and apps');
        $this->assertSame('warn', $a['checks']['public_groups']['status']);
        $this->assertEqualsCanonicalizing(['All Staff', 'Clinic Team'], array_column($a['public_groups'], 'name'));
        $this->assertSame('warn', $a['checks']['sharing']['status'], 'anyone links allowed in the demo');
        $this->assertSame('fail', $a['checks']['mfa']['status'], 'demo tenant does not enforce MFA');
        $this->assertNotNull($a['score']);
        $this->assertSame('prepare', $p->stage);

        $this->get(route('copilot.show', $p))->assertOk()->assertSee('Readiness')->assertSee('Public Teams and Microsoft 365 groups')->assertSee('Blocker');
        $this->get(route('copilot.plan', $p))->assertOk()->assertSee('Restricted Content Discovery')->assertSee('Teams meeting policy for Copilot');
        $this->get(route('copilot.index'))->assertOk()->assertSee('Harbour Dental (Demo)');
    }

    public function test_gates_licences_groups_and_usage(): void
    {
        $p = $this->started();

        // Nothing is licensed before "Ready for pilot".
        $this->post(route('copilot.licences', $p), ['users' => [$this->members[0]], 'action' => 'assign', 'confirm' => 'ASSIGN 1'])->assertSessionHasErrors('licences');

        // Fix oversharing: make public groups private.
        $ids = array_column($p->assessment['public_groups'], 'id');
        $this->post(route('copilot.groups', $p), ['groups' => $ids])->assertSessionHas('status');
        $this->assertSame('Private', collect($this->tenant->fresh()->demo_state['groups'])->firstWhere('displayName', 'All Staff')['visibility']);
        $this->assertSame('pass', $p->fresh()->assessment['checks']['public_groups']['status']);
        $this->assertSame('done', $p->tasks()->where('key', 'public_groups')->first()->status, 'verified automatically');

        $this->post(route('copilot.signoff', [$p, 'readiness']), ['confirm' => 1])->assertSessionHasErrors('gate');
        $this->finishPhase($p, 'prepare');
        $this->post(route('copilot.signoff', [$p, 'readiness']), ['confirm' => 1, 'note' => 'Go'])->assertSessionHasErrors('gate');
        $this->post(route('copilot.signoff', [$p, 'readiness']), ['confirm' => 1, 'note' => 'MFA rollout accepted: pilot users already use MFA'])->assertSessionHas('status');
        $p->refresh();
        $this->assertSame('pilot', $p->stage);

        // Pilot: only pilot members, confirmation required.
        $outsider = $this->members[4];
        $this->post(route('copilot.licences', $p), ['users' => [$outsider], 'action' => 'assign', 'confirm' => 'ASSIGN 1'])->assertSessionHasErrors('licences');
        $this->post(route('copilot.licences', $p), ['users' => [$this->members[0]], 'action' => 'assign', 'confirm' => 'nope'])->assertSessionHasErrors('licences');
        $this->post(route('copilot.task.run', [$p, $p->tasks()->where('key', 'pilot_group')->first()]))->assertSessionHas('status');
        $this->assertNotNull(collect($this->tenant->fresh()->demo_state['groups'])->firstWhere('mailNickname', 'copilotpilot'));
        $this->post(route('copilot.task.run', [$p, $p->tasks()->where('key', 'pilot_licences')->first()]))->assertSessionHas('status');
        $p->refresh();
        $this->assertSame(3, $p->assessment['copilot']['used']);
        $this->assertSame('ok', $p->tasks()->where('key', 'pilot_licences')->first()->verify_status);

        $this->post(route('copilot.usage', $p))->assertSessionHas('status');
        $p->refresh();
        $this->assertSame(3, $p->usage['licensed']);
        $this->assertNotNull($p->tasks()->where('key', 'pilot_usage')->first()->verify_status);
        $this->get(route('copilot.implement', $p))->assertOk()->assertSee('Licence assignment')->assertSee('3 of 5 seats assigned');

        // Rollout: seats are enforced.
        $this->finishPhase($p, 'pilot');
        $this->post(route('copilot.signoff', [$p, 'pilot']), ['confirm' => 1])->assertSessionHas('status');
        $three = array_slice($this->members, 3, 3);
        $this->post(route('copilot.licences', $p), ['users' => $three, 'action' => 'assign', 'confirm' => 'ASSIGN 3'])->assertSessionHasErrors('licences');
        $this->post(route('copilot.licences', $p), ['users' => array_slice($three, 0, 2), 'action' => 'assign', 'confirm' => 'ASSIGN 2'])->assertSessionHas('status');
        $this->assertSame(5, $p->fresh()->assessment['copilot']['used']);
        $this->post(route('copilot.licences', $p), ['users' => [$this->members[0]], 'action' => 'remove', 'confirm' => 'REMOVE 1'])->assertSessionHas('status');
        $this->assertSame(4, $p->fresh()->assessment['copilot']['used']);
    }

    public function test_works_council_blocks_the_pilot_until_consulted(): void
    {
        $p = $this->started(['works_council' => 1, 'consulted' => null]);
        $this->assertSame('fail', $p->assessment['checks']['consultation']['status']);
        $this->finishPhase($p, 'prepare');
        $this->post(route('copilot.signoff', [$p, 'readiness']), ['confirm' => 1])->assertSessionHasErrors('gate');
    }

    public function test_scripts_follow_the_requirements_and_parse(): void
    {
        $p = $this->started(['data_approach' => 'strict', 'allowed_sites' => 'https://brightwater.sharepoint.com/sites/Intranet']);
        $ps = $this->get(route('copilot.script', [$p, 'prepare']))->assertOk()->getContent();
        $this->assertStringContainsString('Start-SPODataAccessGovernanceInsight -ReportEntity EveryoneExceptExternalUsersAtSite', $ps);
        $this->assertStringContainsString("Set-SPOSite -Identity 'https://brightwater.sharepoint.com/sites/HR' -RestrictContentOrgWideSearch", $ps);
        $this->assertStringContainsString('Set-SPOTenantRestrictedSearchMode -Mode Enabled', $ps);
        $this->assertStringContainsString("Add-SPOTenantRestrictedSearchAllowedList -SitesList @('https://brightwater.sharepoint.com/sites/Intranet')", $ps);
        $this->assertStringContainsString('-Copilot EnabledWithTranscript', $ps);
        $this->assertStringContainsString('Wrong tenant', $ps);
        $this->get(route('copilot.script', [$p, 'rollout']))->assertSessionHasErrors('script');
        $this->post(route('copilot.requirements.save', $p), array_merge($p->requirements, ['project_name' => 'x', 'sensitive_sites' => 'not a url', 'next' => 'save']))->assertSessionHasErrors('sensitive_sites');

        if (! ScriptExecutor::pwsh()) {
            $this->markTestSkipped('PowerShell 7 not installed.');
        }
        $p->update(['signoffs' => [['gate' => 'readiness', 'by' => 1, 'name' => 'x', 'at' => now()->toIso8601String()], ['gate' => 'pilot', 'by' => 1, 'name' => 'x', 'at' => now()->toIso8601String()], ['gate' => 'rollout', 'by' => 1, 'name' => 'x', 'at' => now()->toIso8601String()]]]);
        $dir = storage_path('app/copilot-parse-'.uniqid());
        @mkdir($dir, 0777, true);
        foreach (['prepare', 'rollout', 'adopt'] as $phase) {
            file_put_contents("{$dir}/{$phase}.ps1", Scripts::build($p->fresh(), $phase));
        }
        $cmd = '$bad = 0; Get-ChildItem '.escapeshellarg($dir).' -Filter *.ps1 | ForEach-Object { $e = $null; [void][System.Management.Automation.Language.Parser]::ParseFile($_.FullName, [ref]$null, [ref]$e); foreach ($x in $e) { $bad++; Write-Output ($_.Name + \': \' + $x.Message) } }; Write-Output "ERRORS=$bad"';
        $out = shell_exec('"'.ScriptExecutor::pwsh().'" -NoProfile -NonInteractive -Command '.escapeshellarg($cmd));
        array_map('unlink', glob("{$dir}/*.ps1"));
        @rmdir($dir);
        $this->assertStringContainsString('ERRORS=0', (string) $out, (string) $out);
    }

    public function test_documents_and_permissions(): void
    {
        $p = $this->started();
        $this->get(route('copilot.document', [$p, 'readiness']))->assertOk()->assertSee('Readiness score')->assertSee('Public Teams and Microsoft 365 groups');
        $this->get(route('copilot.document', [$p, 'charter']))->assertOk()->assertSee('Success criteria')->assertSee('Summarise this meeting');
        $this->get(route('copilot.document', [$p, 'announcement']))->assertOk()->assertSee('What Copilot can see');

        $tech = User::create(['name' => 'T', 'email' => 't@msp.test', 'password' => 'Password123', 'role_id' => Role::byKey('technician')->id, 'all_tenants' => true, 'is_active' => true]);
        $this->actingAs($tech);
        $this->get(route('copilot.show', $p))->assertOk();
        $this->post(route('copilot.assess', $p))->assertForbidden();
        $this->post(route('copilot.licences', $p), ['users' => ['x'], 'action' => 'assign', 'confirm' => 'ASSIGN 1'])->assertForbidden();
        $this->post(route('copilot.signoff', [$p, 'readiness']), ['confirm' => 1])->assertForbidden();

        $scoped = User::create(['name' => 'S', 'email' => 's@msp.test', 'password' => 'Password123', 'role_id' => Role::byKey('administrator')->id, 'all_tenants' => false, 'is_active' => true]);
        $this->actingAs($scoped);
        $this->get(route('copilot.show', $p))->assertForbidden();
    }
}
