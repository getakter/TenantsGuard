<?php

namespace App\Http\Controllers;

use App\Exceptions\TenantBusyException;
use App\Models\ComplianceProfile;
use App\Models\ControlPlanRun;
use App\Models\Tenant;
use App\Services\Compliance\ComplianceEngine;
use App\Services\Compliance\PlanRunner;
use Illuminate\Http\Request;

/** Phased rollout of a tenant's control plan: preview, apply by phase / selection / all, monitor. */
class ControlPlanController extends Controller
{
    public function rollout(Request $request, Tenant $tenant)
    {
        $plan = $tenant->complianceProfile;
        $statuses = $plan ? ComplianceEngine::controlStatuses($tenant, $plan) : [];

        // Controls that were applied by a plan run and fail again now = drift.
        $runs = ControlPlanRun::where('tenant_id', $tenant->id)->with('user')->latest('id')->limit(20)->get();
        $applied = [];
        foreach ($runs->where('dry_run', false)->reverse() as $run) {
            foreach ($run->results as $id => $r) {
                if (in_array($r['result'], ['fixed', 'deployed'], true)) {
                    $applied[$id] = $run->created_at;
                }
            }
        }

        return view('compliance.rollout', [
            'tenant' => $tenant,
            'plan' => $plan,
            'plans' => ComplianceProfile::orderBy('name')->get(),
            'statuses' => $statuses,
            'applied' => $applied,
            'runs' => $runs,
            'lastRun' => $request->filled('run') ? ControlPlanRun::where('tenant_id', $tenant->id)->find($request->integer('run')) : null,
        ]);
    }

    /** All clients that follow a plan: progress per phase, and bulk preview/apply. */
    public function portfolio(Request $request, ComplianceProfile $profile)
    {
        $tenants = $request->user()->visibleTenants()->where('compliance_profile_id', $profile->id)->orderBy('name')->get();
        $byPhase = $profile->controlsByPhase();
        $matrix = [];
        foreach ($tenants as $t) {
            $st = $t->isReady() ? ComplianceEngine::controlStatuses($t, $profile) : [];
            foreach ($byPhase as $n => $ids) {
                $done = count(array_filter($ids, fn ($id) => in_array($st[$id] ?? '', ['pass', 'deployed', 'compensating'], true)));
                $matrix[$t->id][$n] = ['done' => $done, 'total' => count($ids)];
            }
        }
        $batch = $request->filled('runs') ? ControlPlanRun::with('tenant')->whereIn('id', explode(',', (string) $request->input('runs')))
            ->whereIn('tenant_id', $tenants->pluck('id'))->get() : collect();

        return view('compliance.portfolio', ['plan' => $profile, 'tenants' => $tenants, 'matrix' => $matrix, 'byPhase' => $byPhase, 'batch' => $batch]);
    }

    public function portfolioApply(Request $request, ComplianceProfile $profile, PlanRunner $runner)
    {
        $data = $request->validate([
            'tenant_ids' => 'required|array|min:1',
            'tenant_ids.*' => 'integer',
            'scope' => 'required|string',
            'mode' => 'required|in:preview,apply',
        ], ['tenant_ids.required' => 'Select at least one client.']);
        @set_time_limit(0);
        ignore_user_abort(true);

        $tenants = $request->user()->visibleTenants()->where('compliance_profile_id', $profile->id)->whereIn('id', $data['tenant_ids'])->get();
        $byPhase = $profile->controlsByPhase();
        [$ids, $label] = $data['scope'] === 'all'
            ? [$profile->inScopeControls(), 'All phases']
            : [$byPhase[(int) $data['scope']] ?? [], $profile->phaseList()[(int) $data['scope']]['name'] ?? 'Phase '.$data['scope']];

        $runIds = [];
        $busy = [];
        foreach ($tenants as $t) {
            if (! $t->isReady()) {
                continue;
            }
            try {
                $runIds[] = $runner->run($t, $profile, $ids, $data['mode'] === 'preview', $request->user(), $label)->id;
            } catch (TenantBusyException $e) {
                $busy[] = $t->name;
            }
        }

        return redirect()->route('compliance.portfolio', [$profile, 'runs' => implode(',', $runIds)])
            ->with('status', ($data['mode'] === 'preview' ? 'Preview of ' : '')."{$label} for ".count($runIds).' client(s).'.($busy ? ' Busy, try again: '.implode(', ', $busy).'.' : ''));
    }

    public function apply(Request $request, Tenant $tenant, PlanRunner $runner)
    {
        $plan = $tenant->complianceProfile ?? abort(404);
        // Buttons send "mode|scope|phase", e.g. "preview|phase|2" or "apply|selected|".
        if ($request->filled('cmd')) {
            [$mode, $scope, $phase] = array_pad(explode('|', (string) $request->input('cmd')), 3, null);
            $request->merge(['mode' => $mode, 'scope' => $scope, 'phase' => $phase ?: null]);
        }
        $data = $request->validate([
            'mode' => 'required|in:preview,apply',
            'scope' => 'required|in:phase,selected,all',
            'phase' => 'required_if:scope,phase|nullable|integer',
            'control_ids' => 'required_if:scope,selected|array',
            'control_ids.*' => 'string',
        ], ['control_ids.required_if' => 'Select at least one control.']);

        $byPhase = $plan->controlsByPhase();
        [$ids, $label] = match ($data['scope']) {
            'phase' => [$byPhase[(int) $data['phase']] ?? [], $plan->phaseList()[(int) $data['phase']]['name'] ?? 'Phase '.$data['phase']],
            'selected' => [$data['control_ids'], count($data['control_ids']).' selected control(s)'],
            default => [$plan->inScopeControls(), 'All phases'],
        };
        if (! $ids) {
            return back()->withErrors(['scope' => 'There are no controls in that scope.']);
        }

        $run = $runner->run($tenant, $plan, $ids, $data['mode'] === 'preview', $request->user(), $label);

        return redirect()->route('plans.rollout', [$tenant, 'run' => $run->id])
            ->with('status', $data['mode'] === 'preview' ? "Preview of {$label}: nothing was changed." : "{$label} applied to {$tenant->name}.");
    }
}
