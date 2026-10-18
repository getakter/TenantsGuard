<?php

namespace Tests\Feature;

use App\Models\AssessmentReport;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Dashboard\TenantOverview;
use App\Services\Dashboard\TestLibrary;
use App\Services\Demo\DemoTenant;
use App\Services\Lifecycle\Directory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** CIPP-style client dashboard: overview, identity and device tests, custom reports, executive report, search. */
class DashboardV2Test extends TestCase
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
        $this->post(route('tenants.dashboard.refresh', $this->tenant)); // first scan
    }

    private function snap(string $name, $response): void
    {
        if (getenv('TG_SNAPSHOTS')) {
            @mkdir(getenv('TG_SNAPSHOTS'), 0777, true);
            file_put_contents(getenv('TG_SNAPSHOTS').'/'.$name.'.html', str_replace('href="'.url('/'), 'href="http://127.0.0.1:8000', $response->getContent()));
        }
    }

    public function test_overview_shows_tenant_metrics_assessment_and_charts(): void
    {
        $res = $this->get(route('tenants.dashboard', $this->tenant))->assertOk()
            ->assertSee('Portals')->assertSee('Executive Summary')->assertSee('Select a report')->assertSee('Create custom report')->assertSee('Update report')
            ->assertSee('Tenant ID')->assertSee('Primary domain')->assertSee('Assessment')->assertSee('Zero Trust assessment')
            ->assertSee('Secure Score')->assertSee('User authentication')->assertSee('All users auth methods')->assertSee('License overview')
            ->assertSee('Microsoft 365 Business Premium')->assertSee('Intune admin center');
        $this->snap('overview', $res);

        $o = TenantOverview::for($this->tenant);
        $this->assertGreaterThan(5, $o['metrics']['users']);
        $this->assertSame(2, $o['metrics']['guests']);
        $this->assertSame($o['auth']['registered'] + $o['auth']['unregistered'], $o['auth']['total']);
        $this->assertSame($o['methods']['total'], $o['methods']['single'] + $o['methods']['phishable'] + $o['methods']['resistant']);

        // Shell: client switcher, search, alerts, grouped navigation.
        $res->assertSee('All clients')->assertSee('Search people in all clients')->assertSee('Identity Management')->assertSee('Security &amp; Compliance', false);
    }

    public function test_identity_and_device_tabs_list_tests_with_details(): void
    {
        $res = $this->get(route('tenants.dashboard', [$this->tenant, 'tab' => 'identity', 'report' => 'all']))->assertOk()
            ->assertSee('Identity tests')->assertSee('MFA is required for all users')->assertSee('User impact')->assertSee('Standard available');
        $this->snap('identity', $res);
        $res = $this->get(route('tenants.dashboard', [$this->tenant, 'tab' => 'devices']))->assertOk()->assertSee('Device tests');
        $this->snap('devices', $res);

        $tests = TestLibrary::tests($this->tenant);
        $this->assertSame('fail', $tests['ID-02']['status']);
        $this->assertSame('High', $tests['ID-02']['risk']);
        $this->assertTrue($tests['ID-02']['standard']);
        $this->assertNotEmpty(array_filter($tests, fn ($t) => $t['source'] === 'intune' && $t['area'] === 'devices'));
        $e8 = TestLibrary::filter('fw:e8', $tests);
        $this->assertNotEmpty($e8);
        $this->assertLessThan(count($tests), count($e8));
    }

    public function test_custom_reports_can_be_created_selected_and_deleted(): void
    {
        $this->post(route('tenants.dashboard.reports.store', $this->tenant), ['name' => 'Email only', 'tests' => []])->assertSessionHasErrors('tests');
        $this->post(route('tenants.dashboard.reports.store', $this->tenant), ['name' => 'Email only', 'tests' => ['EML-01', 'EML-03', 'NOPE']])->assertSessionHasErrors('tests.2');
        $this->post(route('tenants.dashboard.reports.store', $this->tenant), ['name' => 'Email and MFA', 'description' => 'Demo', 'tests' => ['EML-01', 'ID-02', 'DEV-01']])
            ->assertRedirect();
        $report = AssessmentReport::firstWhere('name', 'Email and MFA');
        $this->assertSame(['EML-01', 'ID-02', 'DEV-01'], $report->test_ids);

        $page = $this->get(route('tenants.dashboard', [$this->tenant, 'report' => 'custom:'.$report->id, 'tab' => 'identity']));
        $page->assertOk()
            ->assertSee('Email and MFA')->assertSee('Delete');
        $this->assertSame(2, substr_count($page->getContent(), 'onclick="openTest('), 'two identity tests in the custom report');

        $this->delete(route('tenants.dashboard.reports.destroy', [$this->tenant, $report]))->assertRedirect();
        $this->assertNull(AssessmentReport::find($report->id));
    }

    public function test_executive_report_sections_can_be_toggled_and_refresh_reruns_tests(): void
    {
        $this->post(route('tenants.dashboard.refresh', $this->tenant))->assertRedirect()->assertSessionHas('status');

        $this->get(route('reports.show', $this->tenant))->assertOk()->assertSee('Executive summary')->assertSee('Identity and MFA')->assertSee('Licence management');
        $this->get(route('reports.show', [$this->tenant, 'sections' => ['summary', 'licences'], 'embed' => 1]))->assertOk()
            ->assertSee('Executive summary')->assertSee('Licence management')->assertDontSee('Identity and MFA')->assertDontSee('All controls');
    }

    public function test_existing_pages_render_in_the_new_shell(): void
    {
        foreach ([
            'portfolio' => route('dashboard'), 'posture' => route('tenants.show', $this->tenant), 'people' => route('operations.tenant', $this->tenant),
            'controls' => route('controls.index'), 'settings' => route('settings.index'), 'plans' => route('compliance.index'),
        ] as $name => $url) {
            $res = $this->get($url)->assertOk()->assertSee('class="topbar"', false)->assertSee('Identity Management');
            $this->snap($name, $res);
        }
        // Portfolio opens the new dashboard.
        $this->get(route('dashboard'))->assertSee(route('tenants.dashboard', $this->tenant));
    }

    public function test_people_search_and_user_page(): void
    {
        $this->getJson(route('search.users', ['q' => 'x']))->assertOk()->assertJson(['results' => []]);
        $res = $this->getJson(route('search.users', ['q' => 'casey']))->assertOk();
        $this->assertSame('Harbour Dental (Demo)', $res->json('results.0.tenant'));
        $url = $res->json('results.0.url');

        $this->get($url)->assertOk()->assertSee('Casey Nguyen')->assertSee('Groups and Teams')->assertSee('Offboard')->assertSee('Make a template from this person');
        $this->getJson(route('notifications'))->assertOk()->assertJsonStructure(['items']);
    }
}
