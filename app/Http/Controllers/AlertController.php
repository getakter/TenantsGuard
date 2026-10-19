<?php

namespace App\Http\Controllers;

use App\Models\ActivityLog;
use App\Models\SecurityAlert;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Demo\DemoActivity;
use App\Services\Monitoring\SecurityMonitor;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Security alerts: portfolio triage queue, per-client view and monitoring settings. */
class AlertController extends Controller
{
    public function index(Request $request)
    {
        return view('alerts.index', $this->listing($request, null));
    }

    public function tenant(Request $request, Tenant $tenant)
    {
        return view('alerts.tenant', $this->listing($request, $tenant) + [
            'tenant' => $tenant,
            'rules' => SecurityMonitor::RULES,
            'enabledRules' => SecurityMonitor::enabledRules($tenant),
            'breakGlass' => SecurityMonitor::breakGlass($tenant),
        ]);
    }

    private function listing(Request $request, ?Tenant $tenant): array
    {
        $f = $request->validate([
            'status' => 'nullable|in:open,new,acknowledged,resolved,all',
            'severity' => 'nullable|in:critical,high,medium,low',
            'rule' => ['nullable', Rule::in(array_keys(SecurityMonitor::RULES))],
            'tenant' => 'nullable|integer',
            'q' => 'nullable|string|max:100',
        ]);
        $status = $f['status'] ?? 'open';
        $base = SecurityAlert::visibleTo($request->user())->when($tenant, fn ($q) => $q->where('tenant_id', $tenant->id));
        $q = (clone $base)->with('tenant:id,name', 'assignee:id,name', 'resolver:id,name')
            ->when($status === 'open', fn ($q) => $q->open())
            ->when(in_array($status, ['new', 'acknowledged', 'resolved'], true), fn ($q) => $q->where('status', $status))
            ->when($f['severity'] ?? null, fn ($q, $v) => $q->where('severity', $v))
            ->when($f['rule'] ?? null, fn ($q, $v) => $q->where('rule', $v))
            ->when(! $tenant && ($f['tenant'] ?? null), fn ($q) => $q->where('tenant_id', $f['tenant']))
            ->when($f['q'] ?? null, fn ($q, $v) => $q->where(fn ($w) => $w->where('title', 'like', "%{$v}%")->orWhere('detail', 'like', "%{$v}%")))
            ->triage();
        $open = (clone $base)->open();

        return [
            'alerts' => $q->paginate(50)->withQueryString(),
            'filters' => $f + ['status' => $status],
            'counts' => [
                'new' => (clone $base)->where('status', 'new')->count(),
                'critical' => (clone $open)->where('severity', 'critical')->count(),
                'high' => (clone $open)->where('severity', 'high')->count(),
                'open' => (clone $open)->count(),
                'resolved_7d' => (clone $base)->where('status', 'resolved')->where('resolved_at', '>=', now()->subDays(7))->count(),
            ],
            'tenants' => $tenant ? collect() : $request->user()->visibleTenants()->orderBy('name')->get(['tenants.id', 'tenants.name']),
            'staff' => User::where('is_active', true)->orderBy('name')->get(['id', 'name']),
            'lastRun' => $tenant ? $tenant->monitored_at : Tenant::max('monitored_at'),
        ];
    }

    /** Triage one alert: status, assignment, resolution and note. */
    public function update(Request $request, SecurityAlert $alert)
    {
        abort_unless($request->user()->canAccessTenant($alert->tenant), 403);
        $data = $request->validate([
            'action' => 'required|in:acknowledge,resolve,reopen,assign,note',
            'resolution' => ['nullable', Rule::in(array_keys(SecurityAlert::RESOLUTIONS))],
            'assigned_to' => 'nullable|integer|exists:users,id',
            'note' => 'nullable|string|max:2000',
        ]);
        $this->apply($alert, $data, $request->user());

        return back()->with('status', 'Alert updated.');
    }

    /** Bulk triage from the queue. */
    public function bulk(Request $request)
    {
        $data = $request->validate([
            'ids' => 'required|array|min:1|max:200',
            'ids.*' => 'integer',
            'action' => 'required|in:acknowledge,resolve,assign',
            'resolution' => ['nullable', Rule::in(array_keys(SecurityAlert::RESOLUTIONS))],
            'assigned_to' => 'nullable|integer|exists:users,id',
            'note' => 'nullable|string|max:2000',
        ], ['ids.required' => 'Select at least one alert.']);
        $alerts = SecurityAlert::visibleTo($request->user())->whereIn('id', $data['ids'])->get();
        foreach ($alerts as $a) {
            $this->apply($a, $data, $request->user());
        }

        return back()->with('status', $alerts->count().' alert(s) updated.');
    }

    private function apply(SecurityAlert $alert, array $data, User $user): void
    {
        $note = trim((string) ($data['note'] ?? ''));
        $stamp = $note !== '' ? trim(($alert->note ? $alert->note."\n" : '').now()->format('Y-m-d H:i').' '.$user->name.': '.$note) : $alert->note;
        match ($data['action']) {
            'acknowledge' => $alert->fill(['status' => 'acknowledged', 'assigned_to' => $alert->assigned_to ?? $user->id]),
            'resolve' => $alert->fill(['status' => 'resolved', 'resolved_at' => now(), 'resolved_by' => $user->id, 'resolution' => $data['resolution'] ?? 'true_positive']),
            'reopen' => $alert->fill(['status' => 'acknowledged', 'resolved_at' => null, 'resolved_by' => null, 'resolution' => null]),
            'assign' => $alert->fill(['assigned_to' => $data['assigned_to'] ?? $user->id, 'status' => $alert->status === 'new' ? 'acknowledged' : $alert->status]),
            'note' => null,
        };
        $alert->note = $stamp;
        $alert->save();
        ActivityLog::record('alert.'.$data['action'], $alert->tenant, 'info', ucfirst($data['action']).' alert: '.$alert->title.($note !== '' ? " ({$note})" : ''), null, ['alert' => $alert->id]);
    }

    /** Run monitoring for one client now. */
    public function run(Tenant $tenant)
    {
        $r = (new SecurityMonitor($tenant))->run();
        $unavailable = collect($r['status'])->reject(fn ($s) => $s === 'ok')->count();

        return back()->with('status', "Monitoring finished: {$r['new']} new alert(s), {$r['cleared']} cleared."
            .($unavailable ? " {$unavailable} check(s) are unavailable for this client (see Monitoring settings)." : ''));
    }

    public function settings(Request $request, Tenant $tenant)
    {
        $data = $request->validate([
            'break_glass' => 'nullable|string|max:1000',
            'rules' => 'array',
            'rules.*' => [Rule::in(array_keys(SecurityMonitor::RULES))],
        ]);
        $accounts = collect(preg_split('/[\s,;]+/', strtolower((string) ($data['break_glass'] ?? ''))))->filter()->unique()->values();
        $bad = $accounts->reject(fn ($a) => filter_var($a, FILTER_VALIDATE_EMAIL));
        if ($bad->isNotEmpty()) {
            return back()->withErrors(['break_glass' => 'Not a valid sign-in name: '.$bad->implode(', ')])->withInput();
        }
        $tenant->forceFill(['monitoring' => [
            'break_glass' => $accounts->take(10)->all(),
            'disabled_rules' => array_values(array_diff(array_keys(SecurityMonitor::RULES), $data['rules'] ?? [])),
            'configured_at' => now()->toIso8601String(),
        ]])->save();
        ActivityLog::record('alert.settings', $tenant, 'info', 'Monitoring settings updated', null, ['break_glass' => $accounts->all(), 'rules' => $data['rules'] ?? []]);

        return back()->with('status', 'Monitoring settings saved.');
    }

    /** Demo tenants: generate an attack-like sequence of events and monitor it. */
    public function simulate(Tenant $tenant)
    {
        abort_unless($tenant->isDemo(), 404);
        DemoActivity::simulate($tenant);
        $r = (new SecurityMonitor($tenant->fresh()))->run();

        return back()->with('status', "Demo: suspicious activity simulated. {$r['new']} new alert(s).");
    }
}
