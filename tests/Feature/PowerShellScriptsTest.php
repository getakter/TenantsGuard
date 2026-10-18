<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\Role;
use App\Models\Script;
use App\Models\ScriptRun;
use App\Models\ScriptRunTarget;
use App\Models\ScriptVersion;
use App\Models\Setting;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Demo\DemoTenant;
use App\Services\Lifecycle\Directory;
use App\Services\Scripts\ScriptExecutor;
use App\Services\Scripts\ScriptLinter;
use App\Services\Scripts\ScriptService;
use App\Support\Totp;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** PowerShell scripts: approval workflow, permissions, tenant scoping, and the real pwsh sandbox. */
class PowerShellScriptsTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $approver;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();
        config(['tenantsguard.admin.password' => 'secret-pass-123', 'tenantsguard.scripts.dispatch' => 'none']);
        $this->seed();
        $this->admin = User::first();
        $this->approver = User::create(['name' => 'Second Admin', 'email' => 'approver@msp.test', 'password' => 'Password123', 'role_id' => Role::byKey('global_admin')->id, 'all_tenants' => true, 'is_active' => true]);
        $this->tenant = DemoTenant::create('Harbour Dental (Demo)');
        (new Directory($this->tenant))->users();
        Setting::put('scripts_enabled', '1');
        $this->actingAs($this->admin);
    }

    private function pwshOrSkip(): void
    {
        if (! ScriptExecutor::pwsh()) {
            $this->markTestSkipped('PowerShell 7 is not installed.');
        }
    }

    private function approve(string $name): Script
    {
        $script = Script::where('name', $name)->firstOrFail();
        $v = $script->versions()->first();
        app(ScriptService::class)->review($v, $this->approver, true, 'ok');

        return $script->fresh();
    }

    /** An approved version created directly, bypassing the linter, to prove the runtime sandbox holds on its own. */
    private function rawApproved(string $body, string $risk = 'read', int $timeout = 60): ScriptVersion
    {
        $s = Script::create(['name' => 'raw '.uniqid(), 'category' => 'General']);
        $v = $s->versions()->create(['version' => 1, 'body' => $body, 'sha256' => hash('sha256', $body), 'risk' => $risk, 'timeout' => $timeout,
            'parameters' => [], 'lint' => ['blocked' => [], 'warnings' => []], 'status' => 'pending', 'created_by' => $this->admin->id]);
        app(ScriptService::class)->review($v, $this->approver, true, null);

        return $v->fresh();
    }

    private function runNow(ScriptVersion $v, array $params = []): ScriptRunTarget
    {
        $run = app(ScriptService::class)->queue($v, [$this->tenant], $params, ['reason' => 'test run'], $this->admin);
        app(ScriptService::class)->process(120);

        return $run->targets()->first()->fresh();
    }

    public function test_linter_blocks_dangerous_code_but_not_comments(): void
    {
        $bad = ScriptLinter::lint("Invoke-Expression \$x\nAdd-Type -TypeDefinition 'x'\n\$env:APP_KEY\nInvoke-RestMethod https://evil.example\nGet-Content C:\\secrets.txt\n\"\$(iex 'whoami')\"");
        $blocked = collect($bad['blocked'])->pluck('text')->implode(' ');
        foreach (['Invoke-Expression', 'Add-Type', 'Invoke-RestMethod', 'Get-Content', 'iex'] as $cmd) {
            $this->assertStringContainsString($cmd, $blocked);
        }
        $this->assertTrue(collect($bad['blocked'])->contains(fn ($b) => str_contains($b['why'], 'environment')));

        $ok = ScriptLinter::lint("# Do not use Invoke-Expression here\nWrite-Host 'Add-Type is not used'\nInvoke-TGGraph -Uri '/users'");
        $this->assertSame([], $ok['blocked']);

        $ro = ScriptLinter::lint("Invoke-TGGraph -Method PATCH -Uri '/users/x' -Body @{}", [], 'read');
        $this->assertNotEmpty($ro['blocked'], 'read-only scripts cannot contain writes');
        $this->assertSame([], ScriptLinter::lint("Invoke-TGGraph -Method PATCH -Uri '/users/x' -Body @{}", [], 'change')['blocked']);
    }

    public function test_authoring_and_four_eyes_approval(): void
    {
        $this->get(route('scripts.index'))->assertOk()->assertSee('PowerShell scripts')->assertSee('Waiting for approval')->assertSee('Users without MFA');
        $this->post(route('scripts.store'), ['name' => 'Bad', 'category' => 'General', 'risk' => 'read', 'timeout' => 60, 'body' => 'Start-Process notepad'])->assertRedirect();
        $bad = Script::firstWhere('name', 'Bad')->versions()->first();
        $this->assertNotEmpty($bad->blocked());
        $this->post(route('scripts.submit', [$bad->script, $bad]))->assertSessionHasErrors('script');

        $this->post(route('scripts.store'), ['name' => 'Disabled users', 'category' => 'Identity', 'risk' => 'read', 'timeout' => 60,
            'body' => "Invoke-TGGraph -Uri '/users' | Where-Object { -not \$_.accountEnabled } | Select-Object displayName"])->assertRedirect();
        $script = Script::firstWhere('name', 'Disabled users');
        $v = $script->versions()->first();
        $this->get(route('scripts.show', $script))->assertOk()->assertSee('Safety checks passed')->assertSee($v->sha256);
        $this->post(route('scripts.submit', [$script, $v]))->assertSessionHas('status');

        // The author cannot approve their own work...
        $this->post(route('scripts.review', [$script, $v]), ['decision' => 'approve', 'confirm_hash' => $v->sha256])->assertSessionHasErrors('script');
        $this->assertSame('pending', $v->fresh()->status);
        // ...a second person can, but only for exactly what they reviewed.
        $this->actingAs($this->approver);
        $this->post(route('scripts.review', [$script, $v]), ['decision' => 'approve', 'confirm_hash' => str_repeat('0', 64)])->assertSessionHasErrors('confirm_hash');
        $this->post(route('scripts.review', [$script, $v]), ['decision' => 'approve', 'confirm_hash' => $v->sha256])->assertSessionHas('status');
        $this->assertTrue($v->fresh()->intact());

        // Self-approval only when a Global Administrator allows it.
        $this->actingAs($this->admin);
        $this->put(route('scripts.update', $script), ['name' => 'Disabled users', 'category' => 'Identity', 'risk' => 'read', 'timeout' => 60, 'body' => "Invoke-TGGraph -Uri '/users'"])->assertSessionHasNoErrors();
        $v2 = $script->versions()->first();
        $this->assertSame(2, $v2->version);
        $this->assertSame($v->id, $script->fresh()->approved->id, 'the approved version keeps running');
        $this->post(route('scripts.submit', [$script, $v2]));
        $this->post(route('scripts.policy'), ['enabled' => 1, 'self_approval' => 1]);
        $this->post(route('scripts.review', [$script, $v2]), ['decision' => 'approve', 'confirm_hash' => $v2->sha256])->assertSessionHas('status');
        $this->assertSame('superseded', $v->fresh()->status);
        $this->assertTrue(ActivityLog::where('message', 'like', '%self-approved%')->exists());
    }

    public function test_tampering_after_approval_blocks_the_run(): void
    {
        $script = $this->approve('Licence usage');
        $v = $script->approved;
        ScriptVersion::whereKey($v->id)->update(['body' => $v->body."\nWrite-Host 'injected'"]);
        $this->post(route('scripts.run.store', $script), ['tenants' => [$this->tenant->id], 'reason' => 'monthly report', 'version_id' => $v->id])
            ->assertSessionHasErrors('script');
        $this->assertSame(0, ScriptRun::count());
        $this->assertTrue(ActivityLog::where('action', 'script.integrity')->exists());

        // Changing the risk level in the database also breaks the signature.
        $other = $this->approve('Guest accounts')->approved;
        ScriptVersion::whereKey($other->id)->update(['risk' => 'change']);
        $this->assertFalse($other->fresh()->intact());
    }

    public function test_permissions_and_tenant_scoping(): void
    {
        $read = $this->approve('Licence usage');
        $change = $this->approve('Block sign-in and sign out a user');
        $other = DemoTenant::create('Other Client (Demo)');

        $tech = User::create(['name' => 'Tech', 'email' => 'tech@msp.test', 'password' => 'Password123', 'role_id' => Role::byKey('technician')->id, 'all_tenants' => false, 'is_active' => true]);
        $tech->tenants()->attach($this->tenant->id);
        $this->actingAs($tech);
        $this->get(route('scripts.run', $read))->assertOk()->assertSee('Harbour Dental')->assertDontSee('Other Client');
        $this->get(route('scripts.run', $change))->assertForbidden();
        $this->post(route('scripts.run.store', $read), ['tenants' => [$other->id], 'reason' => 'trying another client', 'version_id' => $read->approved->id])->assertForbidden();
        $this->post(route('scripts.store'), ['name' => 'x'])->assertForbidden();
        $this->post(route('scripts.policy'), ['enabled' => 0])->assertForbidden();
        $this->assertSame('1', Setting::find('scripts_enabled')->value);

        $ro = User::create(['name' => 'RO', 'email' => 'ro@msp.test', 'password' => 'Password123', 'role_id' => Role::byKey('read_only')->id, 'all_tenants' => true, 'is_active' => true]);
        $this->actingAs($ro);
        $this->get(route('scripts.index'))->assertForbidden();

        // A run that includes a client the viewer is not assigned to only shows their own clients.
        $this->actingAs($this->admin);
        $run = app(ScriptService::class)->queue($read->approved, [$this->tenant, $other], [], ['reason' => 'both'], $this->admin);
        $this->actingAs($tech);
        $this->get(route('scripts.runs.show', $run))->assertOk()->assertSee('Harbour Dental')->assertDontSee('Other Client')->assertSee('not assigned to you');
    }

    public function test_read_only_script_runs_in_sandbox_and_returns_results(): void
    {
        $this->pwshOrSkip();
        $script = $this->approve('Users without MFA');
        $this->get(route('scripts.run', $script))->assertOk()->assertSee('Run on');
        $this->post(route('scripts.run.store', $script), ['tenants' => [$this->tenant->id], 'reason' => 'MFA review', 'version_id' => $script->approved->id])
            ->assertRedirect();
        $run = ScriptRun::latest('id')->first();
        $this->assertSame('queued', $run->status);

        $this->artisan('scripts:process')->assertSuccessful();
        $t = $run->targets()->first();
        $this->assertSame('succeeded', $t->status, (string) $t->error.' '.$t->output);
        $this->assertNotEmpty($t->results);
        $this->assertArrayHasKey('UPN', $t->results[0]);
        $this->assertSame('completed', $run->fresh()->status);

        $this->get(route('scripts.runs.show', $run))->assertOk()->assertSee('Succeeded')->assertSee($t->results[0]['UPN']);
        $this->get(route('scripts.runs.status', $run))->assertJson(['status' => 'completed', 'active' => false]);
        $csv = $this->get(route('scripts.runs.export', [$run, 'csv']))->assertOk()->streamedContent();
        $this->assertStringContainsString('Client,User,UPN,Admin', $csv);
    }

    public function test_change_script_needs_confirmation_and_authenticator_code(): void
    {
        $this->pwshOrSkip();
        $script = $this->approve('Block sign-in and sign out a user');
        $v = $script->approved;
        $upn = collect($this->tenant->fresh()->demo_state['users'])->firstWhere('userType', 'Member')['userPrincipalName'];
        $base = ['tenants' => [$this->tenant->id], 'reason' => 'compromised account', 'version_id' => $v->id, 'params' => ['UserPrincipalName' => $upn], 'confirm' => 'RUN 1'];

        $this->post(route('scripts.run.store', $script), $base + ['mfa_code' => '000000'])->assertSessionHasErrors('mfa_code');
        $this->post(route('scripts.run.store', $script), ['confirm' => 'RUN 5'] + $base + ['mfa_code' => Totp::code($this->admin->fresh()->mfa_secret, Totp::currentStep())])->assertSessionHasErrors('confirm');
        $this->post(route('scripts.run.store', $script), array_merge($base, ['params' => ['UserPrincipalName' => 'not-an-email'], 'mfa_code' => Totp::code($this->admin->fresh()->mfa_secret, Totp::currentStep())]))
            ->assertSessionHasErrors('script');
        $this->assertSame(0, ScriptRun::count());

        $this->travel(1)->minutes(); // a code can only be used once
        $this->post(route('scripts.run.store', $script), $base + ['mfa_code' => Totp::code($this->admin->fresh()->mfa_secret, Totp::currentStep())])->assertRedirect();
        $run = ScriptRun::latest('id')->first();
        $this->assertTrue($run->mfa_verified);
        app(ScriptService::class)->process(120);
        $t = $run->targets()->first();
        $this->assertSame('succeeded', $t->status, (string) $t->error.' '.$t->output);
        $this->assertTrue($t->results[0]['SignInBlocked']);
        $this->assertStringContainsString('[demo] PATCH', $t->output, 'demo tenants simulate changes');
    }

    public function test_runtime_sandbox_holds_even_if_review_misses_something(): void
    {
        $this->pwshOrSkip();
        $t = $this->runNow($this->rawApproved(<<<'PS'
[ordered]@{
  Mode = $ExecutionContext.SessionState.LanguageMode.ToString()
  Token = $(try { (Get-Command Invoke-TGGraph).Module.SessionState.PSVariable.GetValue('Token') } catch { 'blocked' })
  Scope = $(try { & (Get-Command Invoke-TGGraph).Module { $script:Token } } catch { 'blocked' })
  AddType = $(try { Add-Type -TypeDefinition 'public class Pwn {}'; 'ran' } catch { 'blocked' })
  AppKey = [string](Get-ChildItem env: | Where-Object Name -eq 'APP_KEY').Value
  DbPass = [string](Get-ChildItem env: | Where-Object Name -like 'DB_*').Count
  Jwt = 'eyJhbGciOiJSUzI1NiJ9.eyJzdWIiOiIxMjM0NTY3ODkwIn0.c2lnbmF0dXJlLXZhbHVl'
}
try { Invoke-TGGraph -Method DELETE -Uri '/users/x' } catch { Write-Host "write: $($_.Exception.Message)" }
PS));
        $this->assertSame('succeeded', $t->status, (string) $t->error.' '.$t->output);
        $r = $t->results[0];
        $this->assertSame('ConstrainedLanguage', $r['Mode']);
        $this->assertSame('blocked', $r['Token']);
        $this->assertSame('blocked', $r['Scope']);
        $this->assertSame('blocked', $r['AddType']);
        $this->assertSame('', $r['AppKey'], 'Laravel secrets are not in the child environment');
        $this->assertSame('0', $r['DbPass']);
        $this->assertSame('[REDACTED-TOKEN]', $r['Jwt']);
        $this->assertStringContainsString('read-only, so DELETE requests are blocked', $t->output);

        $env = ScriptExecutor::environment(storage_path('x'));
        $this->assertFalse($env['APP_KEY'] ?? false);
    }

    public function test_timeout_output_errors_and_kill_switch(): void
    {
        $this->pwshOrSkip();
        $t = $this->runNow($this->rawApproved('Start-Sleep -Seconds 20', 'read', 2));
        $this->assertSame('timeout', $t->status);
        $this->assertLessThan(15000, $t->duration_ms);

        $t = $this->runNow($this->rawApproved("throw 'Something went wrong'"));
        $this->assertSame('failed', $t->status);
        $this->assertStringContainsString('Something went wrong', $t->error);

        // Kill switch: nothing can be queued, and queued work is not started.
        $v = $this->rawApproved("'hello'");
        $run = app(ScriptService::class)->queue($v, [$this->tenant], [], ['reason' => 'before switch-off'], $this->admin);
        Setting::put('scripts_enabled', '0');
        app(ScriptService::class)->process(30);
        $this->assertSame('failed', $run->targets()->first()->status);
        $this->expectExceptionMessage('switched off');
        app(ScriptService::class)->queue($v, [$this->tenant], [], ['reason' => 'after'], $this->admin);
    }

    public function test_cancel_and_crashed_worker_recovery(): void
    {
        $v = $this->rawApproved("'hello'");
        $run = app(ScriptService::class)->queue($v, [$this->tenant], [], ['reason' => 'cancel me'], $this->admin);
        $this->post(route('scripts.runs.cancel', $run))->assertSessionHas('status');
        $this->assertSame('cancelled', $run->fresh()->status);

        $run2 = app(ScriptService::class)->queue($v, [$this->tenant], [], ['reason' => 'crash'], $this->admin);
        $run2->targets()->update(['status' => 'running']);
        Setting::put('scripts_enabled', '0'); // nothing new runs; only recovery happens
        app(ScriptService::class)->process(5);
        $this->assertSame('failed', $run2->targets()->first()->status);
        $this->assertStringContainsString('worker stopped unexpectedly', $run2->targets()->first()->error);
    }
}
