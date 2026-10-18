<?php

namespace App\Http\Controllers;

use App\Models\ActivityLog;
use App\Models\Script;
use App\Models\ScriptRun;
use App\Models\ScriptVersion;
use App\Models\Setting;
use App\Models\Tenant;
use App\Services\Scripts\ScriptExecutor;
use App\Services\Scripts\ScriptService;
use App\Support\LineDiff;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use RuntimeException;

/** PowerShell script library, approvals and runs. */
class ScriptController extends Controller
{
    public function __construct(private readonly ScriptService $service) {}

    // ------------------------------------------------------------------ library

    public function index(Request $request)
    {
        $scripts = Script::with(['approved', 'latest.author'])->where('archived', false)->orderBy('category')->orderBy('name')->get();

        return view('scripts.index', [
            'scripts' => $scripts,
            'pending' => ScriptVersion::with('script', 'submitter')->where('status', 'pending')->orderBy('submitted_at')->get(),
            'enabled' => ScriptService::enabled(),
            'selfApproval' => ScriptService::selfApproval(),
            'pwsh' => ScriptExecutor::pwsh(),
            'recent' => ScriptRun::visibleTo($request->user())->with('version.script', 'user')->withCount('targets')->latest('id')->limit(8)->get(),
        ]);
    }

    /** Kill switch and self-approval: Global Administrators only. */
    public function policy(Request $request)
    {
        abort_unless($request->user()->isGlobalAdmin(), 403);
        $in = $request->validate(['enabled' => 'nullable|boolean', 'self_approval' => 'nullable|boolean']);
        $was = [ScriptService::enabled(), ScriptService::selfApproval()];
        Setting::put('scripts_enabled', ! empty($in['enabled']) ? '1' : '0');
        Setting::put('scripts_self_approval', ! empty($in['self_approval']) ? '1' : '0');
        ActivityLog::record('script.policy', null, 'info', 'PowerShell execution '.(! empty($in['enabled']) ? 'ENABLED' : 'DISABLED').', self-approval '.(! empty($in['self_approval']) ? 'allowed' : 'not allowed')
            .' by '.$request->user()->email, null, ['before' => $was]);

        return back()->with('status', 'Script execution policy saved.');
    }

    public function create()
    {
        return view('scripts.form', ['script' => new Script(['category' => 'General']), 'version' => null, 'types' => ScriptService::PARAM_TYPES]);
    }

    public function store(Request $request)
    {
        $in = $this->validated($request, null);
        $script = Script::create(['name' => $in['name'], 'description' => $in['description'] ?? null, 'category' => $in['category'], 'created_by' => $request->user()->id]);
        $v = $this->service->saveVersion($script, $in, $request->user());

        return redirect()->route('scripts.show', [$script, 'v' => $v->id])->with('status', 'Draft saved. Review the checks, then submit it for approval.');
    }

    public function show(Request $request, Script $script)
    {
        $versions = $script->versions()->with('author', 'submitter', 'reviewer')->get();
        $version = $request->filled('v') ? $versions->firstWhere('id', $request->integer('v')) : ($versions->firstWhere('status', 'pending') ?? $script->approved ?? $versions->first());
        abort_unless($version, 404);
        $approved = $script->approved;
        $diff = $approved && $approved->id !== $version->id ? LineDiff::diff($approved->body, $version->body) : null;

        return view('scripts.show', [
            'script' => $script, 'versions' => $versions, 'version' => $version, 'approved' => $approved, 'diff' => $diff,
            'runs' => ScriptRun::visibleTo($request->user())->whereIn('script_version_id', $versions->pluck('id'))->with('user')->withCount('targets')->latest('id')->limit(15)->get(),
            'canSelfApprove' => ScriptService::selfApproval(),
        ]);
    }

    public function edit(Request $request, Script $script)
    {
        $version = $request->filled('from') ? $script->versions()->findOrFail($request->integer('from')) : $script->latest;

        return view('scripts.form', ['script' => $script, 'version' => $version, 'types' => ScriptService::PARAM_TYPES]);
    }

    public function update(Request $request, Script $script)
    {
        $in = $this->validated($request, $script);
        $script->update(['name' => $in['name'], 'description' => $in['description'] ?? null, 'category' => $in['category']]);
        $v = $this->service->saveVersion($script, $in, $request->user());

        return redirect()->route('scripts.show', [$script, 'v' => $v->id])->with('status', "Version {$v->version} saved as a draft. The approved version keeps running until this one is approved.");
    }

    public function submit(Request $request, Script $script, ScriptVersion $version)
    {
        abort_unless($version->script_id === $script->id, 404);
        try {
            $this->service->submit($version, $request->user());
        } catch (RuntimeException $e) {
            return back()->withErrors(['script' => $e->getMessage()]);
        }

        return back()->with('status', 'Submitted for approval.');
    }

    public function review(Request $request, Script $script, ScriptVersion $version)
    {
        abort_unless($version->script_id === $script->id, 404);
        $in = $request->validate(['decision' => 'required|in:approve,reject', 'note' => 'nullable|string|max:1000|required_if:decision,reject', 'confirm_hash' => 'required_if:decision,approve']);
        if ($in['decision'] === 'approve' && ! hash_equals($version->sha256, strtolower(trim((string) $in['confirm_hash'])))) {
            return back()->withErrors(['confirm_hash' => 'The hash does not match. Approve from the review page so you approve exactly what you read.']);
        }
        try {
            $this->service->review($version, $request->user(), $in['decision'] === 'approve', $in['note'] ?? null);
        } catch (RuntimeException $e) {
            return back()->withErrors(['script' => $e->getMessage()]);
        }

        return back()->with('status', $in['decision'] === 'approve' ? "Version {$version->version} approved. It can now be run." : "Version {$version->version} rejected.");
    }

    public function archive(Request $request, Script $script)
    {
        $script->update(['archived' => true]);
        $script->versions()->where('status', 'approved')->update(['status' => 'superseded']);
        ActivityLog::record('script.archive', null, 'info', "Script \"{$script->name}\" archived by {$request->user()->email}");

        return redirect()->route('scripts.index')->with('status', "\"{$script->name}\" archived. It can no longer run.");
    }

    private function validated(Request $request, ?Script $script): array
    {
        $in = $request->validate([
            'name' => ['required', 'string', 'max:120', Rule::unique('scripts', 'name')->ignore($script?->id)],
            'description' => 'nullable|string|max:1000',
            'category' => ['required', Rule::in(Script::CATEGORIES)],
            'risk' => 'required|in:read,change',
            'use_graph_module' => 'nullable|boolean',
            'use_exchange' => 'nullable|boolean',
            'timeout' => 'required|integer|min:30|max:'.config('tenantsguard.scripts.timeout_max'),
            'body' => 'required|string|max:'.(config('tenantsguard.scripts.max_body_kb') * 1024),
            'change_note' => 'nullable|string|max:500',
            'parameters' => 'array|max:15',
            'parameters.*.name' => ['required', 'regex:/^[A-Za-z][A-Za-z0-9_]{0,40}$/', 'distinct'],
            'parameters.*.label' => 'nullable|string|max:80',
            'parameters.*.type' => ['required', Rule::in(array_keys(ScriptService::PARAM_TYPES))],
            'parameters.*.required' => 'nullable|boolean',
            'parameters.*.default' => 'nullable|string|max:200',
            'parameters.*.options' => 'nullable|string|max:500',
            'parameters.*.help' => 'nullable|string|max:200',
        ], ['parameters.*.name.regex' => 'Parameter names start with a letter and use letters, digits or underscores.']);
        $in['parameters'] = array_values(array_filter($in['parameters'] ?? [], fn ($p) => filled($p['name'] ?? null)));
        if (! empty($in['use_graph_module']) && $in['risk'] === 'read') {
            $in['use_graph_module'] = false; // Mg cmdlets cannot be limited to reads, so read-only scripts use Invoke-TGGraph only
        }

        return $in;
    }

    // ------------------------------------------------------------------ run

    public function runForm(Request $request, Script $script)
    {
        $version = $script->approved;
        abort_if(! $version || $script->archived, 404, 'This script has no approved version.');
        $this->authorizeRisk($request, $version);

        return view('scripts.run', [
            'script' => $script, 'version' => $version, 'enabled' => ScriptService::enabled(),
            'tenants' => $request->user()->visibleTenants()->orderBy('name')->get()->filter->isReady()->values(),
            'max' => config('tenantsguard.scripts.max_tenants'),
        ]);
    }

    public function run(Request $request, Script $script)
    {
        $version = $script->approved;
        abort_if(! $version || $script->archived, 404);
        $this->authorizeRisk($request, $version);
        $max = config('tenantsguard.scripts.max_tenants');
        $in = $request->validate([
            'tenants' => "required|array|min:1|max:{$max}",
            'tenants.*' => 'integer|distinct',
            'params' => 'array',
            'reason' => 'required|string|min:5|max:500',
            'ticket' => 'nullable|string|max:80',
            'version_id' => 'required|integer',
            'confirm' => $version->risk === 'change' ? 'required|string' : 'nullable',
            'mfa_code' => $version->risk === 'change' ? 'required|digits:6' : 'nullable',
        ], ['tenants.required' => 'Choose at least one client.', 'reason.required' => 'Say why you are running this (it is kept in the audit log).']);

        // The form was built for a specific approved version; refuse if approval changed in the meantime.
        if ((int) $in['version_id'] !== $version->id) {
            return back()->withErrors(['script' => 'A different version was approved while you were filling in the form. Check the changes and try again.'])->withInput();
        }
        $tenants = Tenant::whereIn('id', $in['tenants'])->get();
        foreach ($tenants as $t) {
            if (! Gate::allows('access-tenant', $t) || ! $t->isReady()) {
                abort(403, 'You cannot run scripts for '.$t->name.'.');
            }
        }
        if ($tenants->count() !== count($in['tenants'])) {
            abort(403);
        }
        try {
            $params = ScriptService::validateParameters($version, $in['params'] ?? []);
        } catch (RuntimeException $e) {
            return back()->withErrors(['script' => $e->getMessage()])->withInput($request->except('mfa_code'));
        }
        if ($version->risk === 'change') {
            $expected = 'RUN '.$tenants->count();
            if (strcasecmp(trim($in['confirm']), $expected) !== 0) {
                return back()->withErrors(['confirm' => "Type {$expected} to confirm."])->withInput();
            }
            if (! $request->user()->verifyTotp($in['mfa_code'])) {
                ActivityLog::record('script.mfa', null, 'failure', "Wrong authenticator code when running \"{$script->name}\" by {$request->user()->email}");

                return back()->withErrors(['mfa_code' => 'That authenticator code is not valid (or was already used). Enter the current code.'])->withInput($request->except('mfa_code'));
            }
        }

        try {
            $run = $this->service->queue($version, $tenants->all(), $params, [
                'reason' => $in['reason'], 'ticket' => $in['ticket'] ?? null, 'ip' => $request->ip(),
                'user_agent' => Str::limit((string) $request->userAgent(), 250, ''), 'mfa_verified' => $version->risk === 'change',
            ], $request->user());
        } catch (RuntimeException $e) {
            return back()->withErrors(['script' => $e->getMessage()])->withInput($request->except('mfa_code'));
        }
        ScriptService::dispatch();

        return redirect()->route('scripts.runs.show', $run)->with('status', 'Queued for '.$tenants->count().' client(s). Results appear here as each one finishes.');
    }

    private function authorizeRisk(Request $request, ScriptVersion $version): void
    {
        abort_unless($request->user()->can($version->risk === 'change' ? 'scripts.run_change' : 'scripts.run'), 403,
            $version->risk === 'change' ? 'Running scripts that change tenants needs the scripts.run_change permission.' : 'You cannot run scripts.');
    }

    // ------------------------------------------------------------------ history and results

    public function runs(Request $request)
    {
        return view('scripts.runs', [
            'runs' => ScriptRun::visibleTo($request->user())->with('version.script', 'user')->withCount('targets')->latest('id')->paginate(30),
        ]);
    }

    /** Only the tenants the viewer may access are shown; other clients in the same run stay hidden. */
    private function visibleTargets(Request $request, ScriptRun $run)
    {
        $targets = $run->targets()->with('tenant')->orderBy('id')->get()->filter(fn ($t) => Gate::allows('access-tenant', $t->tenant))->values();
        abort_if($targets->isEmpty(), 403);

        return $targets;
    }

    public function showRun(Request $request, ScriptRun $run)
    {
        $targets = $this->visibleTargets($request, $run);

        return view('scripts.run-show', ['run' => $run->load('version.script', 'user'), 'targets' => $targets, 'hidden' => $run->targets()->count() - $targets->count()]);
    }

    public function status(Request $request, ScriptRun $run)
    {
        $targets = $this->visibleTargets($request, $run);

        return response()->json([
            'status' => $run->status, 'active' => $run->isActive(),
            'targets' => $targets->map(fn ($t) => ['id' => $t->id, 'status' => $t->status, 'duration' => $t->duration_ms])->values(),
        ]);
    }

    public function cancel(Request $request, ScriptRun $run)
    {
        $this->visibleTargets($request, $run);
        abort_unless($run->user_id === $request->user()->id || $request->user()->can('scripts.approve'), 403);
        $this->service->cancel($run, $request->user());

        return back()->with('status', 'Cancelling. Clients that already finished keep their results.');
    }

    public function export(Request $request, ScriptRun $run, string $format)
    {
        $targets = $this->visibleTargets($request, $run);
        $rows = [];
        foreach ($targets as $t) {
            foreach ($t->results ?? [] as $r) {
                $rows[] = ['Client' => $t->tenant->name] + (is_array($r) ? array_map(fn ($v) => is_scalar($v) || $v === null ? $v : json_encode($v), $r) : ['Value' => $r]);
            }
        }
        ActivityLog::record('script.export', null, 'info', "Results of script run #{$run->id} exported ({$format}) by {$request->user()->email}");
        $file = Str::slug($run->version->script->name).'-run-'.$run->id;
        if ($format === 'json') {
            return response()->json($rows, 200, ['Content-Disposition' => "attachment; filename=\"{$file}.json\""]);
        }
        $cols = collect($rows)->flatMap(fn ($r) => array_keys($r))->unique()->values()->all();

        return response()->streamDownload(function () use ($rows, $cols) {
            $out = fopen('php://output', 'w');
            fputcsv($out, $cols);
            foreach ($rows as $r) {
                // Neutralise spreadsheet formula injection from tenant data.
                fputcsv($out, array_map(fn ($c) => is_string($r[$c] ?? null) && preg_match('/^[=+\-@\t\r]/', $r[$c]) ? "'".$r[$c] : ($r[$c] ?? ''), $cols));
            }
            fclose($out);
        }, "{$file}.csv", ['Content-Type' => 'text/csv']);
    }
}
