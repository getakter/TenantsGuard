<?php

namespace App\Http\Controllers;

use App\Models\ActivityLog;
use App\Models\AssessmentReport;
use App\Models\Scan;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Dashboard\TenantOverview;
use App\Services\Dashboard\TestLibrary;
use App\Services\Graph\GraphException;
use App\Services\Intune\IntuneInventory;
use App\Services\Lifecycle\Directory;
use App\Services\Scanner;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\Rule;
use Throwable;

/** Client dashboard (Overview / Identity / Devices), custom reports, global user search. */
class TenantDashboardController extends Controller
{
    public const TABS = ['overview' => 'Overview', 'identity' => 'Identity', 'devices' => 'Devices'];

    /** Microsoft admin portals, opened in the context of the client tenant. {t} = tenant id, {d} = domain. */
    public const PORTALS = [
        'Microsoft 365 admin center' => 'https://admin.microsoft.com/Partner/BeginClientSession.aspx?CTID={t}&CSDEST=o365admincenter',
        'Entra admin center' => 'https://entra.microsoft.com/{d}',
        'Exchange admin center' => 'https://admin.exchange.microsoft.com/?landingpage=homepage&form=mac_sidebar&delegatedOrg={d}',
        'Intune admin center' => 'https://intune.microsoft.com/{d}',
        'Defender portal' => 'https://security.microsoft.com/?tid={t}',
        'Purview portal' => 'https://purview.microsoft.com/?tid={t}',
        'Teams admin center' => 'https://admin.teams.microsoft.com/?delegatedOrg={d}',
        'SharePoint admin center' => 'https://admin.microsoft.com/Partner/beginclientsession.aspx?CTID={t}&CSDEST=SharePoint',
        'Azure portal' => 'https://portal.azure.com/{d}',
    ];

    public const REPORT_SECTIONS = [
        'summary' => ['Executive summary', 'Score, counts and the headline message'],
        'identity' => ['Identity and MFA', 'MFA registration, enforcement and sign-in methods'],
        'securescore' => ['Microsoft Secure Score', 'Score, trend and top improvement actions'],
        'compliance' => ['Compliance frameworks', 'Control plan scorecard per framework'],
        'areas' => ['Posture by area', 'Pass rate per category'],
        'frameworks' => ['Framework alignment', 'CIS, NIST, Essential Eight mapping'],
        'licences' => ['Licence management', 'Seats bought, used and free'],
        'devices' => ['Device management', 'Managed devices and compliance'],
        'changes' => ['What changed', 'Fixed and regressed since the comparison scan'],
        'trend' => ['Score trend', 'Score over the retention period'],
        'findings' => ['Priority findings', 'Failed controls with remediation'],
        'controls' => ['All controls', 'Full control list (appendix)'],
    ];

    public function show(Request $request, Tenant $tenant)
    {
        $tab = array_key_exists($request->input('tab'), self::TABS) ? $request->input('tab') : 'overview';
        $reports = TestLibrary::reports();
        $reportKey = array_key_exists((string) $request->input('report'), $reports) ? $request->input('report') : $request->session()->get('dash_report', 'zero_trust');
        $reportKey = array_key_exists($reportKey, $reports) ? $reportKey : 'zero_trust';
        $request->session()->put('dash_report', $reportKey);

        $scan = $tenant->scans()->first();
        $all = TestLibrary::tests($tenant, $scan);
        $tests = TestLibrary::filter($reportKey, $all);

        return view('tenants.dashboard', [
            'tenant' => $tenant,
            'tab' => $tab,
            'scan' => $scan,
            'overview' => $tab === 'overview' ? TenantOverview::for($tenant) : null,
            'secure' => $tenant->latestSecureScore,
            'reports' => $reports,
            'reportKey' => $reportKey,
            'report' => $reports[$reportKey],
            'tests' => $tests,
            'library' => $all,
            'summary' => TestLibrary::summary($tests),
            'portals' => collect(self::PORTALS)->map(fn ($u) => strtr($u, ['{t}' => $tenant->ms_tenant_id ?? '', '{d}' => $tenant->domain ?? '']))->all(),
            'sections' => self::REPORT_SECTIONS,
        ]);
    }

    /** "Update report": fresh scan plus fresh overview and device data. */
    public function refresh(Request $request, Tenant $tenant, Scanner $scanner)
    {
        TenantOverview::forget($tenant);
        IntuneInventory::forget($tenant);
        try {
            $scan = $scanner->scan($tenant);
        } catch (Throwable $e) {
            return back()->withErrors(['scan' => $e instanceof GraphException ? $e->friendly() : $e->getMessage()]);
        }
        ActivityLog::record('scan', $tenant, 'info', "Dashboard data refreshed. Score {$scan->score}%.");

        return back()->with('status', 'Data refreshed. All tests were re-run.');
    }

    public function storeReport(Request $request, Tenant $tenant)
    {
        $ids = array_keys(TestLibrary::tests($tenant));
        $in = $request->validate([
            'name' => 'required|string|max:120|unique:assessment_reports,name',
            'description' => 'nullable|string|max:500',
            'tests' => 'required|array|min:1',
            'tests.*' => [Rule::in($ids)],
        ], ['tests.required' => 'Select at least one test.']);
        $report = AssessmentReport::create(['name' => $in['name'], 'description' => $in['description'] ?? null, 'test_ids' => array_values(array_unique($in['tests'])), 'created_by' => $request->user()->id]);
        ActivityLog::record('report', $tenant, 'info', "Custom report \"{$report->name}\" created with ".count($report->test_ids).' tests.');

        return redirect()->route('tenants.dashboard', [$tenant, 'report' => 'custom:'.$report->id])->with('status', "Report \"{$report->name}\" created.");
    }

    public function destroyReport(Request $request, Tenant $tenant, AssessmentReport $report)
    {
        $report->delete();
        $request->session()->forget('dash_report');
        ActivityLog::record('report', $tenant, 'info', "Custom report \"{$report->name}\" deleted.");

        return redirect()->route('tenants.dashboard', $tenant)->with('status', "Report \"{$report->name}\" deleted.");
    }

    // ------------------------------------------------------------------ universal search

    /** Search people across every client the signed-in user can see (JSON for the top bar). */
    public function search(Request $request)
    {
        $q = strtolower(trim((string) $request->input('q')));
        if (mb_strlen($q) < 2) {
            return response()->json(['results' => []]);
        }
        $results = [];
        foreach ($request->user()->visibleTenants()->orderBy('name')->get()->filter->isReady() as $t) {
            $users = Cache::remember('tg:people:'.$t->id, now()->addMinutes(10), function () use ($t) {
                try {
                    return array_map(fn ($u) => array_intersect_key($u, array_flip(['id', 'displayName', 'userPrincipalName', 'jobTitle', 'accountEnabled'])), (new Directory($t))->users());
                } catch (Throwable) {
                    return [];
                }
            });
            foreach ($users as $u) {
                if (str_contains(strtolower($u['displayName'].' '.$u['userPrincipalName'].' '.($u['jobTitle'] ?? '')), $q)) {
                    $results[] = ['name' => $u['displayName'], 'upn' => $u['userPrincipalName'], 'title' => $u['jobTitle'] ?? null, 'tenant' => $t->name,
                        'disabled' => ! ($u['accountEnabled'] ?? true), 'url' => route('operations.user', [$t, $u['id']])];
                }
                if (count($results) >= 25) {
                    break 2;
                }
            }
        }

        return response()->json(['results' => $results]);
    }

    /** One person: everything they have, with lifecycle actions. */
    public function user(Tenant $tenant, string $person)
    {
        abort_unless($tenant->isReady(), 409);
        $dir = new Directory($tenant);
        try {
            $snapshot = $dir->snapshot($person);
            $profile = $dir->graph->get('/users/'.rawurlencode($snapshot['user']['id']).'?$select=id,displayName,userPrincipalName,mail,jobTitle,department,officeLocation,companyName,mobilePhone,businessPhones,city,country,usageLocation,accountEnabled,createdDateTime');
            $manager = null;
            try {
                $manager = $dir->graph->get('/users/'.$snapshot['user']['id'].'/manager');
            } catch (GraphException) {
            }
        } catch (GraphException $e) {
            return redirect()->route('operations.tenant', $tenant)->withErrors(['graph' => $e->friendly()]);
        }

        return view('operations.user', compact('tenant', 'snapshot', 'profile', 'manager'));
    }

    // ------------------------------------------------------------------ notifications

    public function notifications(Request $request)
    {
        $ids = $request->user()->visibleTenantIds();
        $items = ActivityLog::with('tenant')->whereIn('outcome', ['failure', 'warning'])
            ->when($ids !== null, fn ($q) => $q->whereIn('tenant_id', $ids))
            ->where('created_at', '>=', now()->subDays(7))->latest('id')->limit(12)->get();

        return response()->json(['items' => $items->map(fn ($a) => [
            'message' => $a->message, 'tenant' => $a->tenant?->name, 'when' => $a->created_at->diffForHumans(), 'outcome' => $a->outcome,
            'url' => $a->tenant ? route('tenants.dashboard', $a->tenant) : route('activity.index'),
        ])]);
    }

    public static function unreadCount(User $user): int
    {
        $ids = $user->visibleTenantIds();

        return ActivityLog::whereIn('outcome', ['failure', 'warning'])->when($ids !== null, fn ($q) => $q->whereIn('tenant_id', $ids))
            ->where('created_at', '>=', now()->subDay())->count();
    }

    public static function lastScan(Tenant $tenant): ?Scan
    {
        return $tenant->scans()->first();
    }
}
