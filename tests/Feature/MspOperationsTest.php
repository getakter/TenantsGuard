<?php

namespace Tests\Feature;

use App\Models\ComplianceProfile;
use App\Models\ControlPlanRun;
use App\Models\IntuneBundle;
use App\Models\Setting;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Demo\DemoTenant;
use App\Services\Scanner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class MspOperationsTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Tenant $a;

    private Tenant $b;

    protected function setUp(): void
    {
        parent::setUp();
        config(['tenantsguard.admin.password' => 'secret-pass-123']);
        $this->seed();
        $this->admin = User::first();
        $this->a = DemoTenant::create('Alpha Dental (Demo)');
        $this->b = DemoTenant::create('Bravo Logistics (Demo)');
        app(Scanner::class)->scan($this->a);
        app(Scanner::class)->scan($this->b);
        $this->actingAs($this->admin);
    }

    public function test_portfolio_lists_clients_with_tags_and_attention_queue(): void
    {
        $this->put(route('tenants.update', $this->a), ['name' => 'Alpha Dental (Demo)', 'tags' => 'Healthcare, Tier 1, healthcare', 'contact_name' => 'Dr Lee', 'contact_email' => 'lee@alpha.example', 'notes' => 'Change window Sat 9pm'])->assertSessionHas('status');
        $this->assertSame(['Healthcare', 'Tier 1'], $this->a->fresh()->tags);

        Tenant::create(['name' => 'Charlie Law', 'ms_tenant_id' => '99999999-0000-0000-0000-000000000001', 'mode' => 'live']);
        DemoTenant::simulateDrift($this->a); // nothing hardened yet: no drift
        $this->post(route('fix.store', [$this->a, 'APP-01']));
        DemoTenant::simulateDrift($this->a->fresh());
        app(Scanner::class)->scan($this->a->fresh());

        $html = $this->get(route('dashboard'))->assertOk()->assertSee('Client portfolio')->assertSee('Needs attention')->getContent();
        $this->assertStringContainsString('Waiting for admin consent', $html);
        $this->assertStringContainsString('drifted since the previous scan', $html);
        $this->assertStringContainsString('data-tags="healthcare|tier 1"', $html);
        $this->assertStringContainsString('Automatic rescans are not running', $html);

        $this->get(route('tenants.show', $this->a))->assertOk()->assertSee('Client onboarding')->assertSee('Choose a control plan')->assertSee('Dr Lee')->assertSee('Change window');
        $this->get(route('tenants.edit', $this->a))->assertOk()->assertSee('Offboard client');
    }

    public function test_offboarding_requires_typing_the_client_name(): void
    {
        $this->delete(route('tenants.destroy', $this->b), ['confirm_name' => 'wrong'])->assertSessionHasErrors('confirm_name');
        $this->assertNotNull($this->b->fresh());
        $this->delete(route('tenants.destroy', $this->b), ['confirm_name' => 'bravo logistics (demo)'])->assertRedirect(route('dashboard'));
        $this->assertNull(Tenant::find($this->b->id));
    }

    public function test_concurrent_operations_on_a_tenant_are_blocked(): void
    {
        $lock = Cache::lock('tenant-op-'.$this->a->id, 60);
        $this->assertTrue($lock->get());

        $this->post(route('tenants.scan', $this->a))->assertSessionHasErrors();
        $this->post(route('fix.store', [$this->a, 'APP-01']))->assertSessionHasErrors();
        $this->assertTrue($this->a->fresh()->demo_state['authorizationPolicy']['defaultUserRolePermissions']['allowedToCreateApps'], 'nothing changed while locked');

        $lock->release();
        $this->post(route('fix.store', [$this->a, 'APP-01']))->assertSessionHas('status');
    }

    public function test_webhook_alerts_on_drift_and_test_message(): void
    {
        Http::fake(['hooks.example.com/*' => Http::response('', 202)]);
        $this->post(route('settings.notifications'), [
            'notify_webhook_url' => 'https://hooks.example.com/teams', 'notify_format' => 'teams', 'notify_events' => ['drift', 'change_failed'], 'test' => 1,
        ])->assertSessionHas('status', 'Saved. Test message sent.');

        $this->post(route('fix.store', [$this->a, 'COL-02']));
        DemoTenant::simulateDrift($this->a->fresh());
        app(Scanner::class)->scan($this->a->fresh());

        Http::assertSent(fn ($r) => $r->url() === 'https://hooks.example.com/teams'
            && str_contains(json_encode($r->data()), 'drifted')
            && ($r['attachments'][0]['contentType'] ?? null) === 'application/vnd.microsoft.card.adaptive');

        $this->post(route('settings.notifications'), ['notify_webhook_url' => 'http://insecure.example.com', 'notify_format' => 'teams'])->assertSessionHasErrors('notify_webhook_url');
    }

    public function test_roll_out_a_plan_across_several_clients(): void
    {
        $plan = ComplianceProfile::where('name', 'like', 'General:%')->first();
        $this->a->update(['compliance_profile_id' => $plan->id]);
        $this->b->update(['compliance_profile_id' => $plan->id]);

        $this->get(route('compliance.portfolio', $plan))->assertOk()->assertSee('Alpha Dental')->assertSee('Bravo Logistics')->assertSee('Apply to selected clients');
        $this->post(route('compliance.portfolio.apply', $plan), ['tenant_ids' => [$this->a->id, $this->b->id], 'scope' => '1', 'mode' => 'preview'])->assertRedirect();
        $this->assertSame(2, ControlPlanRun::where('dry_run', true)->count());

        $this->post(route('compliance.portfolio.apply', $plan), ['tenant_ids' => [$this->a->id, $this->b->id], 'scope' => '1', 'mode' => 'apply'])->assertRedirect();
        $this->assertSame(2, ControlPlanRun::where('dry_run', false)->count());
        foreach ([$this->a, $this->b] as $t) {
            $this->assertFalse($t->fresh()->demo_state['authorizationPolicy']['defaultUserRolePermissions']['allowedToCreateApps']);
        }
        $this->get(route('compliance.portfolio', [$plan, 'runs' => ControlPlanRun::where('dry_run', false)->pluck('id')->implode(',')]))->assertOk()->assertSee('Result');
    }

    public function test_plan_uses_tuned_intune_settings_from_linked_bundle(): void
    {
        $bundle = IntuneBundle::where('name', 'like', 'Healthcare%')->first(); // WIN-CFG-01 lock 600 s
        $plan = ComplianceProfile::create(['name' => 'Alpha - HIPAA', 'frameworks' => [['key' => 'hipaa']], 'intune_bundle_id' => $bundle->id, 'excluded_controls' => []]);
        $this->a->update(['compliance_profile_id' => $plan->id]);
        $this->assertTrue($plan->setting('WIN-CFG-01')['from_bundle']);

        $this->post(route('plans.apply', $this->a), ['cmd' => 'apply|selected|', 'control_ids' => ['WIN-CFG-01']])->assertRedirect();
        $cfg = collect($this->a->fresh()->demo_state['deviceConfigurations'])->firstWhere('displayName', 'TG - Windows hardening: screen lock, SmartScreen, consumer features');
        $this->assertSame(600, collect($cfg['omaSettings'])->firstWhere('displayName', 'Machine inactivity limit')['value']);
    }

    public function test_export_and_import_plans_and_bundles(): void
    {
        $plan = ComplianceProfile::where('name', 'like', 'US healthcare%')->first();
        $plan->update(['excluded_controls' => ['ID-10' => 'PIM'], 'control_settings' => ['APP-01' => ['phase' => 3]]]);
        $json = $this->get(route('compliance.profiles.export', $plan))->assertOk()->streamedContent();
        $this->assertStringContainsString('tenantsguard.control-plan', $json);

        $this->post(route('compliance.profiles.import'), ['file' => UploadedFile::fake()->createWithContent('plan.json', $json)])->assertRedirect();
        $copy = ComplianceProfile::where('name', $plan->name.' (imported)')->first();
        $this->assertNotNull($copy);
        $this->assertSame('PIM', $copy->excluded_controls['ID-10']);
        $this->assertSame(3, $copy->setting('APP-01')['phase']);

        $bundle = IntuneBundle::first();
        $bjson = $this->get(route('intune.bundles.export', $bundle))->assertOk()->streamedContent();
        $this->post(route('intune.bundles.import'), ['file' => UploadedFile::fake()->createWithContent('b.json', $bjson)])->assertRedirect();
        $this->assertSame(count($bundle->items), count(IntuneBundle::where('name', $bundle->name.' (imported)')->first()->items));

        $this->post(route('compliance.profiles.import'), ['file' => UploadedFile::fake()->createWithContent('x.json', '{"type":"other"}')])->assertSessionHasErrors('file');
    }

    public function test_activity_csv_and_settings_health(): void
    {
        $this->post(route('fix.store', [$this->a, 'APP-01']));
        $csv = $this->get(route('activity.export'))->assertOk()->streamedContent();
        $this->assertStringStartsWith('"When (UTC)",Tenant,User,Action', $csv);
        $this->assertStringContainsString('Alpha Dental', $csv);

        Setting::put('ms_secret_expires', now()->addDays(10)->toIso8601String());
        $this->get(route('settings.index'))->assertOk()->assertSee('System health')->assertSee('Alert notifications')->assertSee('schedule:run');
        $this->get(route('dashboard'))->assertSee('app secret expires');

        $this->artisan('tenants:scan')->assertSuccessful();
        $this->assertNotNull(Setting::find('scheduler_last_run'));
    }
}
