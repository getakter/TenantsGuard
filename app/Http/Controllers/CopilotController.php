<?php

namespace App\Http\Controllers;

use App\Models\ActivityLog;
use App\Models\CopilotProject;
use App\Models\CopilotTask;
use App\Models\PurviewTask;
use App\Models\Setting;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Copilot\Catalog;
use App\Services\Copilot\CopilotService;
use App\Services\Copilot\Scripts;
use App\Services\Graph\GraphException;
use App\Services\Lifecycle\Directory;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use RuntimeException;

/** Microsoft 365 Copilot programme: requirements, readiness, prerequisites, pilot, rollout and adoption. */
class CopilotController extends Controller
{
    public function __construct(private readonly CopilotService $service) {}

    private function authorizeProject(CopilotProject $p): void
    {
        Gate::authorize('access-tenant', $p->tenant);
    }

    public function index(Request $request)
    {
        $tenants = $request->user()->visibleTenants()->orderBy('name')->get();

        return view('copilot.index', ['tenants' => $tenants, 'projects' => CopilotProject::with('tenant', 'tasks')->whereIn('tenant_id', $tenants->pluck('id'))->get()->keyBy('tenant_id')]);
    }

    public function start(Request $request)
    {
        $tenant = Tenant::findOrFail($request->validate(['tenant_id' => 'required|integer'])['tenant_id']);
        Gate::authorize('access-tenant', $tenant);
        abort_unless($tenant->isReady(), 409, 'Connect the tenant first.');
        $p = CopilotProject::firstOrCreate(['tenant_id' => $tenant->id], [
            'name' => $tenant->name.' - Microsoft 365 Copilot', 'created_by' => $request->user()->id,
            'requirements' => Catalog::defaults() + ['sponsor_name' => $tenant->contact_name ?? '', 'sponsor_email' => $tenant->contact_email ?? ''],
        ]);
        if ($p->wasRecentlyCreated) {
            ActivityLog::record('copilot.start', $tenant, 'info', "Copilot programme started by {$request->user()->email}");
        }

        return redirect()->route('copilot.requirements', $p);
    }

    public function show(CopilotProject $project)
    {
        $this->authorizeProject($project);

        return view('copilot.show', ['project' => $project->load('tasks')]);
    }

    public function requirements(CopilotProject $project)
    {
        $this->authorizeProject($project);
        try {
            $users = collect((new Directory($project->tenant))->users())->where('accountEnabled', true)->values();
        } catch (GraphException) {
            $users = collect();
        }

        return view('copilot.requirements', ['project' => $project, 'questionnaire' => Catalog::questionnaire(), 'users' => $users]);
    }

    public function saveRequirements(Request $request, CopilotProject $project)
    {
        $this->authorizeProject($project);
        $rules = ['project_name' => 'required|string|max:160'];
        foreach (Catalog::questionnaire() as [, , $fields]) {
            foreach ($fields as $k => [, $type, $options]) {
                $rules[$k] = match ($type) {
                    'select' => ['required', Rule::in(array_keys($options))],
                    'multi' => ['array'], 'users' => ['array', 'max:300'],
                    'bool' => ['nullable', 'boolean'],
                    'number' => ['required', 'integer', 'min:0', 'max:100000'],
                    'email' => ['nullable', 'email', 'max:190'],
                    'date' => ['required', 'date'],
                    'textarea' => ['nullable', 'string', 'max:20000'],
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
        $in = $request->validate($rules);
        $req = [];
        foreach (Catalog::questionnaire() as [, , $fields]) {
            foreach ($fields as $k => [, $type]) {
                $req[$k] = match ($type) {
                    'bool' => (bool) ($in[$k] ?? false), 'multi', 'users' => array_values($in[$k] ?? []), 'number' => (int) $in[$k], default => $in[$k] ?? null,
                };
            }
        }
        foreach (['sensitive_sites', 'allowed_sites'] as $k) {
            $bad = array_filter(array_map('trim', preg_split('/\r\n|\r|\n/', (string) $req[$k])), fn ($l) => $l !== '' && ! preg_match('#^https://[^\s]+$#i', $l));
            if ($bad) {
                return back()->withErrors([$k => 'Use one full https:// site URL per line. Not valid: '.implode(', ', array_slice($bad, 0, 3))])->withInput();
            }
        }
        $project->update(['requirements' => $req, 'name' => $in['project_name'], 'stage' => $project->stage === 'requirements' ? 'assess' : $project->stage]);
        ActivityLog::record('copilot.requirements', $project->tenant, 'info', "Copilot requirements updated by {$request->user()->email}");
        if ($request->input('next') === 'assess') {
            $this->service->assess($project->fresh(), $request->user());

            return redirect()->route('copilot.show', $project)->with('status', 'Requirements saved and readiness assessed.');
        }
        $this->service->syncTasks($project->fresh());

        return back()->with('status', 'Requirements saved.');
    }

    public function assess(Request $request, CopilotProject $project)
    {
        $this->authorizeProject($project);
        $a = $this->service->assess($project, $request->user());

        return back()->with('status', 'Readiness assessed: '.($a['score'] ?? '?').'%.');
    }

    public function plan(CopilotProject $project)
    {
        $this->authorizeProject($project);

        return view('copilot.plan', ['project' => $project->load('tasks.owner', 'tasks.completer'), 'staff' => User::where('is_active', true)->orderBy('name')->get(['id', 'name'])]);
    }

    public function updateTask(Request $request, CopilotProject $project, CopilotTask $task)
    {
        $this->authorizeProject($project);
        abort_unless($task->copilot_project_id === $project->id && $task->method !== 'gate', 404);
        $in = $request->validate(['status' => ['required', Rule::in(array_keys(PurviewTask::STATUS))], 'owner_id' => 'nullable|exists:users,id', 'note' => 'nullable|string|max:4000']);
        if (in_array($in['status'], ['done', 'in_progress'], true) && ! $project->unlocked($task->phase)) {
            return back()->withErrors(['task' => 'This phase is locked until the previous gate is signed off.']);
        }
        if ($in['status'] === 'skipped' && $task->required && blank($in['note'] ?? null)) {
            return back()->withErrors(['task' => 'Explain in the note why a required task is not needed.']);
        }
        $done = $in['status'] === 'done' && $task->status !== 'done';
        $task->update($in + ($done ? ['completed_by' => $request->user()->id, 'completed_at' => now()] : ($in['status'] !== 'done' ? ['completed_by' => null, 'completed_at' => null] : [])));
        ActivityLog::record('copilot.task', $project->tenant, 'info', "Copilot task \"{$task->title}\" set to {$in['status']} by {$request->user()->email}");

        return redirect()->to(route('copilot.plan', $project).'#task-'.$task->id)->with('status', 'Task updated.');
    }

    public function runTask(Request $request, CopilotProject $project, CopilotTask $task)
    {
        $this->authorizeProject($project);
        abort_unless($task->copilot_project_id === $project->id && $task->method === 'auto', 404);
        if (! $project->unlocked($task->phase)) {
            return back()->withErrors(['task' => 'This phase is locked until the previous gate is signed off.']);
        }
        try {
            $msg = $this->service->runAutomation($task, $request->user());
        } catch (GraphException $e) {
            return back()->withErrors(['task' => $e->friendly().($e->status === 403 ? ' Add the missing permission in Settings > Update app permissions and re-grant consent.' : '')]);
        } catch (RuntimeException $e) {
            return back()->withErrors(['task' => $e->getMessage()]);
        }

        return redirect()->to(route('copilot.plan', $project).'#task-'.$task->id)->with('status', $msg);
    }

    public function signoff(Request $request, CopilotProject $project, string $gate)
    {
        $this->authorizeProject($project);
        $in = $request->validate(['note' => 'nullable|string|max:1000', 'confirm' => 'accepted']);
        try {
            $this->service->signoff($project, $gate, $request->user(), $in['note'] ?? null);
        } catch (RuntimeException $e) {
            return back()->withErrors(['gate' => $e->getMessage()]);
        }

        return back()->with('status', CopilotProject::GATES[$gate][0].' signed off.');
    }

    // ------------------------------------------------------------------ implement: licences, groups, usage, scripts

    public function implement(CopilotProject $project)
    {
        $this->authorizeProject($project);

        return view('copilot.implement', ['project' => $project]);
    }

    public function licences(Request $request, CopilotProject $project)
    {
        $this->authorizeProject($project);
        $in = $request->validate(['users' => 'required|array|min:1|max:300', 'users.*' => 'string|max:64', 'action' => 'required|in:assign,remove', 'confirm' => 'required|string']);
        $expected = strtoupper($in['action']).' '.count($in['users']);
        if (strcasecmp(trim($in['confirm']), $expected) !== 0) {
            return back()->withErrors(['licences' => "Type {$expected} to confirm."])->withInput();
        }
        try {
            $msg = $in['action'] === 'assign' ? $this->service->assign($project, $in['users'], $request->user())['message'] : $this->service->remove($project, $in['users'], $request->user());
        } catch (GraphException $e) {
            return back()->withErrors(['licences' => $e->friendly()]);
        } catch (RuntimeException $e) {
            return back()->withErrors(['licences' => $e->getMessage()]);
        }

        return back()->with('status', $msg);
    }

    public function groups(Request $request, CopilotProject $project)
    {
        $this->authorizeProject($project);
        $in = $request->validate(['groups' => 'required|array|min:1', 'groups.*' => 'string|max:64']);
        if (! $project->unlocked('prepare')) {
            return back()->withErrors(['groups' => 'Run the assessment first.']);
        }
        try {
            $msg = $this->service->privatise($project, $in['groups'], $request->user());
        } catch (GraphException $e) {
            return back()->withErrors(['groups' => $e->friendly()]);
        }

        return back()->with('status', $msg);
    }

    public function usage(Request $request, CopilotProject $project)
    {
        $this->authorizeProject($project);
        try {
            $u = $this->service->usage($project);
        } catch (GraphException $e) {
            return back()->withErrors(['usage' => $e->friendly().($e->status === 403 ? ' Needs Reports.Read.All: Settings > Update app permissions, then re-grant consent.' : '')]);
        }

        return back()->with('status', "Usage refreshed: {$u['active7']} of {$u['licensed']} licensed users active in the last 7 days.");
    }

    public function script(Request $request, CopilotProject $project, string $phase)
    {
        $this->authorizeProject($project);
        abort_unless(in_array($phase, ['prepare', 'rollout', 'adopt'], true), 404);
        if (! $project->unlocked($phase)) {
            return back()->withErrors(['script' => 'That phase is locked.']);
        }
        ActivityLog::record('copilot.script', $project->tenant, 'info', "Copilot {$phase} script downloaded by {$request->user()->email}");

        return response(Scripts::build($project, $phase), 200, ['Content-Type' => 'text/plain; charset=utf-8',
            'Content-Disposition' => 'attachment; filename="'.Scripts::filename($project, $phase).'"', 'Cache-Control' => 'no-store']);
    }

    public function document(CopilotProject $project, string $doc)
    {
        $this->authorizeProject($project);
        abort_unless(in_array($doc, ['readiness', 'charter', 'announcement'], true), 404);

        return view('copilot.doc-'.$doc, ['project' => $project->load('tenant', 'tasks'), 'branding' => Setting::branding()]);
    }
}
