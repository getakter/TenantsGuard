<?php

namespace Tests\Feature;

use App\Models\IntuneBundle;
use App\Models\IntuneDeployment;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Demo\DemoTenant;
use App\Services\Intune\IntuneCatalog;
use App\Services\Scanner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class IntuneModuleTest extends TestCase
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
        $this->tenant = DemoTenant::create('Clinic (Demo)');
        app(Scanner::class)->scan($this->tenant);
        $this->actingAs($this->admin);
    }

    private function bundle(string $prefix): IntuneBundle
    {
        return IntuneBundle::where('name', 'like', $prefix.'%')->firstOrFail();
    }

    private function state(): array
    {
        return $this->tenant->fresh()->demo_state;
    }

    public function test_catalog_is_complete_and_every_template_builds(): void
    {
        $all = IntuneCatalog::all();
        $this->assertGreaterThanOrEqual(25, count($all));
        foreach (['windows', 'ios', 'android', 'cross'] as $platform) {
            $this->assertNotEmpty(collect($all)->where('platform', $platform), "No {$platform} templates");
        }
        foreach (['Compliance', 'Configuration', 'Endpoint security', 'Update management', 'App protection', 'Conditional Access', 'Prerequisite'] as $cat) {
            $this->assertNotEmpty(collect($all)->where('category', $cat), "No {$cat} templates");
        }

        foreach ($all as $key => $t) {
            $this->assertNotEmpty($t['frameworks'], "{$key} has no framework tags");
            $this->assertNotEmpty($t['zt'], "{$key} has no Zero Trust pillar");
            if ($t['kind'] === 'manual') {
                $this->assertNotEmpty($t['steps']);

                continue;
            }
            $this->assertContains($t['default_target'], $t['targets']);
            $payload = ($t['build'])(IntuneCatalog::params($t), $this->tenant, ['group_id' => 'g1']);
            $this->assertIsArray($payload, $key);
            if (in_array($t['kind'], ['compliance', 'configuration', 'mam_ios', 'mam_android', 'autopilot'], true)) {
                $this->assertSame($t['odata'], $payload['@odata.type'], "{$key} @odata.type");
            }
            if ($t['kind'] === 'compliance') {
                $this->assertNotEmpty($payload['scheduledActionsForRule']);
            }
            if ($t['kind'] === 'ca') {
                $this->assertSame('enabledForReportingButNotEnforced', $payload['state'], "{$key} must be report-only");
            }
        }
    }

    public function test_app_protection_levels_follow_the_framework(): void
    {
        $t = IntuneCatalog::find('IOS-APP-01');
        $l1 = ($t['build'])(IntuneCatalog::params($t, ['level' => '1', 'pin_length' => 4]));
        $l2 = ($t['build'])(IntuneCatalog::params($t, ['level' => '2', 'pin_length' => 4]));
        $l3 = ($t['build'])(IntuneCatalog::params($t, ['level' => '3']));

        $this->assertSame('allApps', $l1['allowedOutboundDataTransferDestinations']);
        $this->assertSame(4, $l1['minimumPinLength']);
        $this->assertSame('managedApps', $l2['allowedOutboundDataTransferDestinations']);
        $this->assertTrue($l2['saveAsBlocked']);
        $this->assertSame(6, $l2['minimumPinLength'], 'Level 2 enforces at least 6 digits');
        $this->assertFalse($l2['printBlocked']);
        $this->assertTrue($l3['printBlocked']);
        $this->assertSame('managedApps', $l3['allowedInboundDataTransferSources']);
    }

    public function test_preview_changes_nothing_then_deploy_creates_and_assigns(): void
    {
        $b = $this->bundle('SMB standard');

        $this->post(route('intune.bundles.deploy.run', $b), ['tenant_ids' => [$this->tenant->id], 'mode' => 'preview'])->assertRedirect();
        $preview = IntuneDeployment::latest('id')->first();
        $this->assertTrue($preview->dry_run);
        $this->assertSame('preview', $preview->status);
        $this->assertGreaterThan(10, $preview->summary['would_create']);
        $this->assertEmpty($this->state()['deviceConfigurations'] ?? []);
        $this->assertNull(collect($this->state()['groups'] ?? [])->firstWhere('displayName', 'TenantsGuard Pilot'), 'preview creates no group');
        $this->get(route('intune.run', $preview->batch))->assertOk()->assertSee('Will create')->assertSee('Deploy this plan');

        $this->post(route('intune.bundles.deploy.run', $b), ['tenant_ids' => [$this->tenant->id], 'mode' => 'deploy'])->assertRedirect();
        $real = IntuneDeployment::latest('id')->first();
        $this->assertSame('success', $real->status, json_encode($real->items->where('status', 'failed')->pluck('message')));

        $s = $this->state();
        $pilot = collect($s['groups'])->where('displayName', 'TenantsGuard Pilot');
        $this->assertCount(1, $pilot, 'pilot group created once');
        $pilot = $pilot->first();
        $win = collect($s['compliancePolicies'])->firstWhere('displayName', 'TG - Windows compliance: device health and security');
        $this->assertSame('#microsoft.graph.groupAssignmentTarget', $win['assignments'][0]['target']['@odata.type']);
        $broad = collect($s['deviceConfigurations'])->firstWhere('displayName', 'TG - Windows Update ring: Broad (everyone else)');
        $this->assertSame('#microsoft.graph.allDevicesAssignmentTarget', $broad['assignments'][0]['target']['@odata.type']);
        $this->assertSame('allCoreMicrosoftApps', $s['iosAppProtections'][0]['appGroupType']);
        $ca = collect($s['conditionalAccess'])->firstWhere('displayName', 'TG - Conditional Access: require compliant device (Windows)');
        $this->assertSame('enabledForReportingButNotEnforced', $ca['state']);
        $this->assertSame([$pilot['id']], $ca['conditions']['users']['includeGroups']);
        $this->assertCount(1, $s['autopilotProfiles']);

        // Posture rescan: the device compliance control now passes.
        $this->assertSame('pass', $this->tenant->scans()->first()->results()->where('check_id', 'DEV-01')->value('status'));
    }

    public function test_redeploy_is_idempotent_and_update_existing_patches(): void
    {
        $b = $this->bundle('Windows endpoint hardening');
        $run = fn (bool $update) => $this->post(route('intune.bundles.deploy.run', $b), ['tenant_ids' => [$this->tenant->id], 'mode' => 'deploy', 'update_existing' => $update ? 1 : 0]);

        $run(false);
        $count = count($this->state()['deviceConfigurations']);
        $run(false);
        $again = IntuneDeployment::latest('id')->first();
        $this->assertSame($count, count($this->state()['deviceConfigurations']), 'No duplicates on re-run');
        $this->assertArrayNotHasKey('created', $again->summary);
        $this->assertGreaterThan(5, $again->summary['exists']);

        $run(true);
        $upd = IntuneDeployment::latest('id')->first();
        $this->assertSame('success', $upd->status, json_encode($upd->items->where('status', 'failed')->pluck('message')));
        $this->assertGreaterThan(5, $upd->summary['updated']);
    }

    public function test_licence_gaps_are_skipped_and_prerequisites_reported(): void
    {
        $s = $this->tenant->demo_state;
        $s['skus'][0]['servicePlans'] = [['servicePlanName' => 'EXCHANGE_S_STANDARD', 'provisioningStatus' => 'Success']];
        $this->tenant->update(['demo_state' => $s]);

        $b = $this->bundle('Healthcare');
        $this->post(route('intune.bundles.deploy.run', $b), ['tenant_ids' => [$this->tenant->id], 'mode' => 'deploy']);
        $d = IntuneDeployment::latest('id')->first();
        $this->assertSame(0, $d->summary['created'] ?? 0);
        $this->assertGreaterThan(15, $d->summary['skipped']);
        $this->assertStringContainsString('Microsoft Intune Plan 1', $d->items->firstWhere('template', 'WIN-CMP-01')->message);
        $this->assertSame('manual', $d->items->firstWhere('template', 'PRE-01')->status);
        $this->assertSame('ready', $d->items->firstWhere('template', 'PRE-03')->status);
    }

    public function test_bundle_builder_creates_custom_bundle_with_params_and_named_group(): void
    {
        $this->get(route('intune.bundles.create'))->assertOk()->assertSee('Windows Autopilot')->assertSee('HIPAA');

        $this->post(route('intune.bundles.store'), [
            'name' => 'Law firm: Windows plus BYOD',
            'description' => 'Custom',
            'plan' => 'business_premium', 'ownership' => 'mixed', 'industry' => 'legal', 'platforms' => ['windows', 'ios'],
            'items' => [
                'WIN-SEC-04' => ['include' => 1, 'assignment' => 'group', 'group' => 'Legal - Laptops', 'params' => ['mode' => '1']],
                'IOS-APP-01' => ['include' => 1, 'assignment' => 'all_users', 'params' => ['level' => '3', 'pin_length' => 8]],
                'WIN-SEC-01' => ['assignment' => 'pilot'], // not included
            ],
        ])->assertRedirect(route('intune.index'));

        $bundle = IntuneBundle::where('name', 'Law firm: Windows plus BYOD')->first();
        $this->assertSame(['WIN-SEC-04', 'IOS-APP-01'], $bundle->templateKeys());
        $this->assertSame('legal', $bundle->context['industry']);
        $this->assertSame(8, collect($bundle->items)->firstWhere('template', 'IOS-APP-01')['params']['pin_length']);

        $this->post(route('intune.bundles.deploy.run', $bundle), ['tenant_ids' => [$this->tenant->id], 'mode' => 'deploy']);
        $s = $this->state();
        $this->assertNotNull(collect($s['groups'])->firstWhere('displayName', 'Legal - Laptops'));
        $asr = collect($s['deviceConfigurations'])->firstWhere('displayName', 'TG - Attack surface reduction (ASR) rules');
        $this->assertStringContainsString('9E6C4E1F-7D60-472F-BA1A-A39EF669E4B2=1', $asr['omaSettings'][0]['value']);
        $this->assertSame('#microsoft.graph.allLicensedUsersAssignmentTarget', $s['iosAppProtections'][0]['assignments'][0]['target']['@odata.type']);
        $this->assertTrue($s['iosAppProtections'][0]['printBlocked']);

        // Named group without a name is rejected.
        $this->post(route('intune.bundles.store'), [
            'name' => 'Bad', 'plan' => 'e3', 'ownership' => 'corporate', 'industry' => 'general',
            'items' => ['WIN-SEC-01' => ['include' => 1, 'assignment' => 'group', 'group' => '']],
        ])->assertSessionHasErrors('items');
    }

    public function test_pages_render_and_rbac_is_enforced(): void
    {
        $this->get(route('intune.index'))->assertOk()->assertSee('Template library')->assertSee('Healthcare / HIPAA');
        $this->get(route('tenants.intune', $this->tenant))->assertOk()->assertSee('Apple MDM push certificate')->assertSee('Managed devices');
        $this->get(route('intune.bundles.deploy', $this->bundle('SMB')))->assertOk()->assertSee('Fit for this bundle');
        $this->get(route('intune.bundles.edit', $this->bundle('SMB')))->assertOk();

        $other = DemoTenant::create('Other (Demo)');
        $ro = User::create(['name' => 'RO', 'email' => 'ro@msp.test', 'password' => 'Password123', 'role_id' => Role::byKey('read_only')->id, 'all_tenants' => false, 'is_active' => true]);
        $ro->tenants()->sync([$this->tenant->id]);
        $this->actingAs($ro);
        $this->get(route('intune.index'))->assertOk()->assertDontSee('New bundle');
        $this->get(route('tenants.intune', $this->tenant))->assertOk()->assertDontSee('Deploy a bundle');
        $this->get(route('tenants.intune', $other))->assertForbidden();
        $this->get(route('intune.bundles.create'))->assertForbidden();
        $this->post(route('intune.bundles.deploy.run', $this->bundle('SMB')), ['tenant_ids' => [$this->tenant->id], 'mode' => 'deploy'])->assertForbidden();

        $tech = User::create(['name' => 'Tech', 'email' => 'tech@msp.test', 'password' => 'Password123', 'role_id' => Role::byKey('technician')->id, 'all_tenants' => false, 'is_active' => true]);
        $tech->tenants()->sync([$this->tenant->id]);
        $this->actingAs($tech);
        $this->post(route('intune.bundles.deploy.run', $this->bundle('BYOD')), ['tenant_ids' => [$this->tenant->id, $other->id], 'mode' => 'deploy'])->assertRedirect();
        $this->assertSame(1, IntuneDeployment::where('bundle_name', 'like', 'BYOD%')->count(), 'Unassigned tenant ignored');
        $this->assertEmpty($other->fresh()->demo_state['iosAppProtections'] ?? []);
        $this->get(route('intune.bundles.create'))->assertForbidden();
    }
}
