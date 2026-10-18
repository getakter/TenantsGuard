<?php

namespace App\Http\Controllers;

use App\Models\ActivityLog;
use App\Models\PurviewProject;
use App\Models\PurviewTask;
use App\Models\Setting;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Graph\GraphException;
use App\Services\Lifecycle\Directory;
use App\Services\Purview\Catalog;
use App\Services\Purview\Designer;
use App\Services\Purview\PurviewService;
use App\Services\Purview\Scripts;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use RuntimeException;
use Throwable;

/** Microsoft Purview programme: requirements, design, plan, implementation, verification and documents. */
class PurviewController extends Controller
{
    public function __construct(private readonly PurviewService $service) {}

    private function authorizeProject(PurviewProject $project): void
    {
        Gate::authorize('access-tenant', $project->tenant);
    }

    public function index(Request $request)
    {
        $tenants = $request->user()->visibleTenants()->orderBy('name')->get();
        $projects = PurviewProject::with('tenant', 'tasks')->whereIn('tenant_id', $tenants->pluck('id'))->get()->keyBy('tenant_id');

        return view('purview.index', ['tenants' => $tenants, 'projects' => $projects]);
    }

    public function start(Request $request)
    {
        $in = $request->validate(['tenant_id' => 'required|integer']);
        $tenant = Tenant::findOrFail($in['tenant_id']);
        Gate::authorize('access-tenant', $tenant);
        abort_unless($tenant->isReady(), 409, 'Connect the tenant first.');
        $project = PurviewProject::firstOrCreate(['tenant_id' => $tenant->id], [
            'name' => $tenant->name.' - Data security programme', 'requirements' => Catalog::defaults() + ['owner_name' => $tenant->contact_name ?? '', 'owner_email' => $tenant->contact_email ?? ''],
            'created_by' => $request->user()->id,
        ]);
        if ($project->wasRecentlyCreated) {
            $this->service->readiness($project);
            ActivityLog::record('purview.start', $tenant, 'info', "Purview programme started by {$request->user()->email}");
        }

        return redirect()->route('purview.requirements', $project);
    }

    public function show(PurviewProject $project)
    {
        $this->authorizeProject($project);
        $project->load('tasks.owner', 'approvedBy');
        $caps = $project->capabilities ?? $this->service->readiness($project);

        return view('purview.show', [
            'project' => $project, 'caps' => $caps, 'gaps' => Designer::gaps($project->requirements, $caps),
            'next' => $project->tasks->where('stale', false)->whereNotIn('status', ['done', 'skipped'])->filter(fn ($t) => $project->unlocked($t->phase))->sortBy('due_date')->take(6),
        ]);
    }

    // ------------------------------------------------------------------ requirements

    public function requirements(PurviewProject $project)
    {
        $this->authorizeProject($project);
        try {
            $users = (new Directory($project->tenant))->users();
        } catch (GraphException) {
            $users = [];
        }

        return view('purview.requirements', ['project' => $project, 'questionnaire' => Catalog::questionnaire(), 'users' => collect($users)->where('accountEnabled', true)->values()]);
    }

    public function saveRequirements(Request $request, PurviewProject $project)
    {
        $this->authorizeProject($project);
        $rules = [];
        foreach (Catalog::questionnaire() as [, , $fields]) {
            foreach ($fields as $k => [$label, $type, $options]) {
                $rules[$k] = match ($type) {
                    'select' => ['required', Rule::in(array_keys($options))],
                    'multi' => ['array'],
                    'bool' => ['nullable', 'boolean'],
                    'number' => ['required', 'integer', 'min:0', 'max:100000'],
                    'email' => ['nullable', 'email', 'max:190'],
                    'date' => ['required', 'date'],
                    'users' => ['array', 'max:50'],
                    default => ['nullable', 'string', 'max:500'],
                };
                if ($type === 'multi') {
                    $rules[$k.'.*'] = [Rule::in(array_keys($options))];
                }
                if ($type === 'users') {
                    $rules[$k.'.*'] = ['string', 'max:64'];
                }
            }
        }
        $rules['project_name'] = 'required|string|max:160';
        $in = $request->validate($rules);
        $req = [];
        foreach (Catalog::questionnaire() as [, , $fields]) {
            foreach ($fields as $k => [, $type]) {
                $req[$k] = match ($type) {
                    'bool' => (bool) ($in[$k] ?? false),
                    'multi', 'users' => array_values($in[$k] ?? []),
                    'number' => (int) $in[$k],
                    default => $in[$k] ?? null,
                };
            }
        }
        $project->update(['requirements' => $req, 'name' => $in['project_name']]);
        ActivityLog::record('purview.requirements', $project->tenant, 'info', "Purview requirements updated by {$request->user()->email}");

        if ($request->input('next') === 'design') {
            $this->service->generate($project->fresh(), $request->user());

            return redirect()->route('purview.design', $project)->with('status', $project->wasChanged() || $project->design_approved_at === null
                ? 'Design generated from the requirements. Review it, adjust it and ask the compliance owner to approve it.' : 'Design regenerated.');
        }

        return back()->with('status', 'Requirements saved.');
    }

    // ------------------------------------------------------------------ design

    public function design(PurviewProject $project)
    {
        $this->authorizeProject($project);
        if (! $project->design) {
            return redirect()->route('purview.requirements', $project)->withErrors(['design' => 'Complete the requirements and generate the design first.']);
        }

        return view('purview.design', ['project' => $project->load('approvedBy'), 'caps' => $project->capabilities ?? []]);
    }

    public function updateDesign(Request $request, PurviewProject $project)
    {
        $this->authorizeProject($project);
        $d = $project->design;
        abort_unless($d, 409);
        $in = $request->validate([
            'labels' => 'array', 'labels.*.display' => 'required|string|max:64', 'labels.*.tooltip' => 'required|string|max:500',
            'labels.*.encryption' => 'required|in:none,org,user', 'labels.*.header' => 'nullable|string|max:80', 'labels.*.watermark' => 'nullable|string|max:80',
            'labels.*.include' => 'nullable|boolean',
            'policy.default_document' => 'required|string', 'policy.mandatory' => 'nullable|boolean', 'policy.justification' => 'nullable|boolean',
            'dlp' => 'array', 'dlp.*.include' => 'nullable|boolean', 'dlp.*.high_threshold' => 'required|integer|min:1|max:500',
            'dlp.*.style' => 'required|in:notify,block_override,block', 'dlp.*.locations' => 'array', 'dlp.*.locations.*' => 'in:Exchange,SharePoint,OneDrive,Teams,Devices',
            'autolabel' => 'array', 'autolabel.*.include' => 'nullable|boolean',
            'baseline.years' => 'required|integer|min:0|max:100', 'baseline.delete' => 'nullable|boolean',
            'teams.years' => 'required|integer|min:0|max:100',
            'retention' => 'array', 'retention.*.include' => 'nullable|boolean', 'retention.*.years' => 'required|integer|min:1|max:100',
            'retention.*.action' => 'required|in:delete,startDispositionReview,none', 'retention.*.name' => 'required|string|max:64',
            'irm' => 'array', 'irm.*.include' => 'nullable|boolean',
            'pilot.group_name' => 'required|string|max:64', 'pilot.mail_nickname' => ['required', 'regex:/^[a-z0-9][a-z0-9-]{2,39}$/'],
        ], ['pilot.mail_nickname.regex' => 'Use 3 to 40 lowercase letters, digits or dashes.']);

        $caps = $project->capabilities ?? [];
        foreach ($d['labels'] as $i => &$l) {
            $x = $in['labels'][$i] ?? null;
            if (! $x) {
                continue;
            }
            $enc = $x['encryption'];
            if ($enc !== 'none' && ($caps['encryption'] ?? null) === false) {
                return back()->withErrors(['design' => 'Encryption is not licensed in this tenant (Azure Rights Management).'])->withInput();
            }
            $l = array_merge($l, ['display' => $x['display'], 'tooltip' => $x['tooltip'], 'encryption' => $enc, 'header' => $x['header'] ?: null,
                'watermark' => $x['watermark'] ?: null, 'include' => in_array($l['key'], ['General'], true) || ! empty($x['include'])]);
        }
        unset($l);
        $keys = collect($d['labels'])->where('include', true)->pluck('key')->all();
        if (! in_array($in['policy']['default_document'], $keys, true)) {
            return back()->withErrors(['design' => 'The default label must be one of the included labels.'])->withInput();
        }
        $d['label_policy'] = array_merge($d['label_policy'], ['default_document' => $in['policy']['default_document'], 'default_email' => $in['policy']['default_document'],
            'mandatory' => ! empty($in['policy']['mandatory']), 'justification' => ! empty($in['policy']['justification'])]);
        foreach ($d['dlp'] as $i => &$x) {
            $v = $in['dlp'][$i] ?? null;
            if ($v) {
                $locs = array_values(array_intersect($v['locations'] ?? [], ['Exchange', 'SharePoint', 'OneDrive', 'Teams', 'Devices']));
                if (in_array('Teams', $locs, true) && ($caps['dlp_teams'] ?? null) === false || in_array('Devices', $locs, true) && ($caps['dlp_endpoint'] ?? null) === false) {
                    return back()->withErrors(['design' => 'Teams or device DLP is not licensed in this tenant.'])->withInput();
                }
                $x = array_merge($x, ['include' => ! empty($v['include']), 'high_threshold' => (int) $v['high_threshold'], 'style' => $v['style'], 'override' => $v['style'] !== 'block', 'locations' => $locs]);
            }
        }
        unset($x);
        foreach ($d['autolabel'] as $i => &$a) {
            $a['include'] = ! empty($in['autolabel'][$i]['include']);
        }
        unset($a);
        $d['retention']['baseline'] = ['years' => (int) $in['baseline']['years'], 'delete' => ! empty($in['baseline']['delete']), 'include' => (int) $in['baseline']['years'] > 0];
        $d['retention']['teams']['years'] = (int) $in['teams']['years'];
        $d['retention']['teams']['include'] = $d['retention']['teams']['include'] && (int) $in['teams']['years'] > 0 || ((int) $in['teams']['years'] > 0 && ($caps['retention_teams'] ?? true) !== false && in_array('teams', $project->requirements['workloads'] ?? [], true));
        foreach ($d['retention']['labels'] as $i => &$r) {
            $v = $in['retention'][$i] ?? null;
            if ($v) {
                $action = $v['action'] === 'startDispositionReview' && ($caps['records'] ?? null) === false ? 'delete' : $v['action'];
                $r = array_merge($r, ['include' => ! empty($v['include']), 'years' => (int) $v['years'], 'action' => $action, 'name' => $v['name']]);
            }
        }
        unset($r);
        foreach ($d['irm']['policies'] as $i => &$p) {
            $p['include'] = $d['irm']['licensed'] && ! empty($in['irm'][$i]['include']);
        }
        unset($p);
        $d['pilot']['group_name'] = $in['pilot']['group_name'];
        $d['pilot']['mail_nickname'] = $in['pilot']['mail_nickname'];

        $was = $project->isApproved();
        $this->service->saveDesign($project, $d, $request->user(), 'Design edited');

        return back()->with('status', $was && ! $project->fresh()->isApproved() ? 'Design saved. It changed after approval, so it needs to be approved again.' : 'Design saved.');
    }

    public function approve(Request $request, PurviewProject $project)
    {
        $this->authorizeProject($project);
        $in = $request->validate(['note' => 'nullable|string|max:1000', 'confirm' => 'accepted']);
        try {
            $this->service->approveDesign($project, $request->user(), $in['note'] ?? null);
        } catch (RuntimeException $e) {
            return back()->withErrors(['design' => $e->getMessage()]);
        }

        return redirect()->route('purview.plan', $project)->with('status', 'Design approved. The implementation plan is unlocked.');
    }

    // ------------------------------------------------------------------ plan and tasks

    public function plan(PurviewProject $project)
    {
        $this->authorizeProject($project);
        $project->load('tasks.owner', 'tasks.completer');

        return view('purview.plan', ['project' => $project, 'staff' => User::where('is_active', true)->orderBy('name')->get(['id', 'name'])]);
    }

    public function updateTask(Request $request, PurviewProject $project, PurviewTask $task)
    {
        $this->authorizeProject($project);
        abort_unless($task->purview_project_id === $project->id, 404);
        abort_if($task->method === 'gate', 422, 'Gates are signed off, not edited.');
        $in = $request->validate(['status' => ['required', Rule::in(array_keys(PurviewTask::STATUS))], 'owner_id' => 'nullable|exists:users,id', 'note' => 'nullable|string|max:4000']);
        if (in_array($in['status'], ['done', 'in_progress'], true) && ! $project->unlocked($task->phase)) {
            return back()->withErrors(['task' => 'This phase is locked until the previous gate is signed off.']);
        }
        if ($in['status'] === 'skipped' && $task->required && blank($in['note'] ?? null)) {
            return back()->withErrors(['task' => 'Explain in the note why a required task is not needed (kept as evidence).']);
        }
        $done = $in['status'] === 'done' && $task->status !== 'done';
        $task->update($in + ($done ? ['completed_by' => $request->user()->id, 'completed_at' => now()] : ($in['status'] !== 'done' ? ['completed_by' => null, 'completed_at' => null] : [])));
        ActivityLog::record('purview.task', $project->tenant, 'info', "Purview task \"{$task->title}\" set to ".PurviewTask::STATUS[$in['status']][0]." by {$request->user()->email}");

        return back()->with('status', 'Task updated.')->withFragment('task-'.$task->id);
    }

    public function runTask(Request $request, PurviewProject $project, PurviewTask $task)
    {
        $this->authorizeProject($project);
        abort_unless($task->purview_project_id === $project->id && $task->method === 'auto', 404);
        if (! $project->unlocked($task->phase) || ($task->key !== 'licences' && ! $project->isApproved())) {
            return back()->withErrors(['task' => 'Approve the design (and sign earlier gates) before TenantsGuard changes the tenant.']);
        }
        try {
            $msg = $this->service->runAutomation($task, $request->user());
        } catch (GraphException $e) {
            ActivityLog::record('purview.automation', $project->tenant, 'failure', "Purview: {$task->title} failed: ".$e->friendly());

            return back()->withErrors(['task' => $e->friendly().($e->status === 403 ? ' Add the permission in Settings > Update app permissions and re-grant consent.' : '')]);
        } catch (RuntimeException $e) {
            return back()->withErrors(['task' => $e->getMessage()]);
        }

        return back()->with('status', $msg)->withFragment('task-'.$task->id);
    }

    public function signoff(Request $request, PurviewProject $project, string $gate)
    {
        $this->authorizeProject($project);
        $in = $request->validate(['note' => 'nullable|string|max:1000', 'confirm' => 'accepted']);
        try {
            $this->service->signoff($project, $gate, $request->user(), $in['note'] ?? null);
        } catch (RuntimeException $e) {
            return back()->withErrors(['gate' => $e->getMessage()]);
        }

        return back()->with('status', PurviewProject::GATES[$gate][0].' signed off.');
    }

    // ------------------------------------------------------------------ implementation

    public function implement(PurviewProject $project)
    {
        $this->authorizeProject($project);

        return view('purview.implement', ['project' => $project->load('tasks'), 'existing' => rescue(fn () => $this->service->existing($project), ['sensitivity' => null, 'retention' => null], false)]);
    }

    public function script(Request $request, PurviewProject $project, string $phase)
    {
        $this->authorizeProject($project);
        abort_unless(in_array($phase, ['foundations', 'discover', 'pilot', 'production', 'verify'], true), 404);
        if ($phase !== 'verify' && (! $project->isApproved() || ! $project->unlocked($phase))) {
            return back()->withErrors(['script' => 'Scripts are only generated from an approved design, for unlocked phases.']);
        }
        abort_unless($project->design, 409);
        try {
            $domains = (new Directory($project->tenant))->domains();
        } catch (Throwable) {
            $domains = array_filter([$project->tenant->domain]);
        }
        ActivityLog::record('purview.script', $project->tenant, 'info', "Purview {$phase} script downloaded by {$request->user()->email}");

        return response(Scripts::build($project, $phase, $domains), 200, [
            'Content-Type' => 'text/plain; charset=utf-8',
            'Content-Disposition' => 'attachment; filename="'.Scripts::filename($project, $phase).'"',
            'Cache-Control' => 'no-store',
        ]);
    }

    public function uploadVerification(Request $request, PurviewProject $project)
    {
        $this->authorizeProject($project);
        $request->validate(['export' => 'required|file|max:2048|mimes:json,txt']);
        try {
            $results = $this->service->importVerification($project, (string) file_get_contents($request->file('export')->getRealPath()), $request->user());
        } catch (RuntimeException $e) {
            return back()->withErrors(['export' => $e->getMessage()]);
        }
        $ok = collect($results)->where('status', 'ok')->count();

        return back()->with('status', "Verification imported: {$ok} of ".count($results).' checks match the design.');
    }

    public function reverify(Request $request, PurviewProject $project)
    {
        $this->authorizeProject($project);
        $results = $this->service->verify($project);

        return back()->with('status', 'Re-checked '.count($results).' item(s) against the tenant.');
    }

    // ------------------------------------------------------------------ documents

    public function document(PurviewProject $project, string $doc)
    {
        $this->authorizeProject($project);
        abort_unless(in_array($doc, ['design', 'announcement', 'guide'], true) && $project->design, 404);

        return view('purview.doc-'.$doc, ['project' => $project->load('tenant', 'tasks', 'approvedBy'), 'branding' => Setting::branding()]);
    }
}
