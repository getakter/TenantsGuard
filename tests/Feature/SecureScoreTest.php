<?php

namespace Tests\Feature;

use App\Models\ComplianceProfile;
use App\Models\SecureScoreSnapshot;
use App\Models\Setting;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Demo\DemoTenant;
use App\Services\Graph\GraphClientInterface;
use App\Services\Graph\GraphException;
use App\Services\Scanner;
use App\Services\SecureScore\SecureScoreMap;
use App\Services\SecureScore\SecureScoreService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SecureScoreTest extends TestCase
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
        $this->tenant = DemoTenant::create('Contoso Clinic (Demo)');
        app(Scanner::class)->scan($this->tenant);
        $this->actingAs($this->admin);
    }

    public function test_scan_captures_secure_score_with_history_categories_comparatives_and_mapping(): void
    {
        $s = $this->tenant->secureScores()->first();
        $this->assertTrue($s->ok());
        $this->assertGreaterThan(100, $s->max_score);
        $this->assertSame(round(100 * $s->current_score / $s->max_score, 2), $s->percent);
        $this->assertCount(90, $s->history);
        $this->assertEqualsCanonicalizing(['Identity', 'Data', 'Device', 'Apps'], array_keys($s->categories));
        $this->assertNotNull($s->comparativePercent('TotalSeats'));
        $this->assertSame('risk_accepted', collect($s->controls)->firstWhere('name', 'SelfServicePasswordReset')['status']);
        $this->assertSame(['ID-03', 'ID-09'], collect($s->controls)->firstWhere('name', 'AdminMFAV2')['tg_controls']);
        $this->assertSame(['EML-03'], collect($s->controls)->firstWhere('name', 'exo_dmarc')['tg_controls'], 'keyword mapping');
        $this->assertArrayHasKey('WIN-SEC-02', $s->pointsByControl());
    }

    public function test_mapping_by_name_and_by_title_keywords(): void
    {
        $this->assertSame(['ID-04'], SecureScoreMap::controlsFor('BlockLegacyAuthentication', 'x'));
        $this->assertSame(['WIN-SEC-05'], SecureScoreMap::controlsFor('unknown_new', 'Enable Local Administrator Password Solution (LAPS)'));
        $this->assertSame(['ID-14'], SecureScoreMap::controlsFor('unknown', 'Require phishing-resistant MFA for admins'));
        $this->assertSame([], SecureScoreMap::controlsFor('mdo_safeattachments', 'Ensure Safe Attachments policy is enabled'));
        // Real Microsoft actions that must NOT be confused with SharePoint "Anyone" links.
        $this->assertSame([], SecureScoreMap::controlsFor('meeting_restrictanonymousjoin_v1', 'Restrict anonymous users from joining meetings'));
        $this->assertSame([], SecureScoreMap::controlsFor('exo_individualsharing', "Ensure 'External sharing' of calendars is not available"));
        $this->assertSame(['COL-03'], SecureScoreMap::controlsFor('x', "Do not allow anonymous 'Anyone' sharing links in SharePoint and OneDrive"));
    }

    public function test_fixing_in_tenantsguard_raises_secure_score(): void
    {
        $before = $this->tenant->secureScores()->first()->current_score;
        $this->post(route('fix.store', [$this->tenant, 'APP-02']))->assertSessionHas('status');
        $after = $this->tenant->secureScores()->first()->current_score;
        $this->assertEqualsWithDelta($before + 4, $after, 0.01, 'IntegratedApps (4 pts) completed');
    }

    public function test_secure_score_appears_across_the_product(): void
    {
        $this->tenant->update(['compliance_profile_id' => ComplianceProfile::where('name', 'like', 'US healthcare%')->value('id')]);

        $this->get(route('tenants.securescore', $this->tenant))->assertOk()
            ->assertSee('Microsoft Secure Score')->assertSee('Improvement actions, prioritised by points')
            ->assertSee('TenantsGuard can help')->assertSee('Similar-size tenants')->assertSee('HIPAA');
        $this->get(route('tenants.show', $this->tenant))->assertOk()->assertSee('TenantsGuard posture')->assertSee('Compliance frameworks')->assertSee('See prioritised actions');
        $this->get(route('dashboard'))->assertOk()->assertSee('Microsoft Secure Score')->assertSee(route('tenants.securescore', $this->tenant));
        $this->get(route('compliance.tenant', $this->tenant))->assertOk()->assertSee('Microsoft Secure Score');
        $this->get(route('plans.rollout', $this->tenant))->assertOk()->assertSee('Secure Score</a>', false);
        $this->get(route('reports.show', $this->tenant))->assertOk()->assertSee('Top improvement actions');
        $this->assertStringStartsWith('PK', $this->get(route('reports.excel', $this->tenant))->streamedContent());
    }

    public function test_permission_error_is_recorded_and_surfaced(): void
    {
        $graph = new class implements GraphClientInterface
        {
            public function get(string $p): array
            {
                throw new GraphException('Forbidden', 403, 'Forbidden');
            }

            public function getAll(string $p): array
            {
                throw new GraphException('Forbidden', 403, 'Forbidden');
            }

            public function post(string $p, array $b): array
            {
                return [];
            }

            public function patch(string $p, array $b): array
            {
                return [];
            }

            public function put(string $p, array $b): array
            {
                return [];
            }

            public function delete(string $p): array
            {
                return [];
            }
        };
        $snap = app(SecureScoreService::class)->capture($this->tenant, $graph);
        $this->assertFalse($snap->ok());
        $this->assertStringContainsString('SecurityEvents.Read.All', $snap->error);
        $this->get(route('tenants.securescore', $this->tenant))->assertOk()->assertSee('SecurityEvents.Read.All');
        $this->get(route('dashboard'))->assertSee('Secure Score unavailable');
    }

    public function test_drop_of_five_points_alerts(): void
    {
        Http::fake(['hooks.example.com/*' => Http::response('', 200)]);
        Setting::put('notify_webhook_url', 'https://hooks.example.com/x');
        Setting::put('notify_format', 'generic');
        Setting::put('notify_events', json_encode(['drift']));

        SecureScoreSnapshot::create(['tenant_id' => $this->tenant->id, 'current_score' => 140, 'max_score' => 146, 'percent' => 95.89]);
        app(Scanner::class)->scan($this->tenant->fresh());

        Http::assertSent(fn ($r) => ($r['title'] ?? '') === 'Microsoft Secure Score dropped');
        $this->get(route('dashboard'))->assertSee('Microsoft Secure Score dropped');
    }
}
