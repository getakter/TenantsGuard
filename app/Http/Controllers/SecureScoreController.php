<?php

namespace App\Http\Controllers;

use App\Models\Tenant;
use App\Services\Compliance\ComplianceEngine;
use App\Services\Compliance\FrameworkRegistry;

class SecureScoreController extends Controller
{
    public function show(Tenant $tenant)
    {
        [$snap, $prev] = [$tenant->secureScores()->first(), $tenant->secureScores()->whereNull('error')->skip(1)->first()];
        $statuses = ComplianceEngine::controlStatuses($tenant, $tenant->complianceProfile);
        $plan = $tenant->complianceProfile;
        $inPlan = $plan ? $plan->inScopeControls() : [];

        // Enrich each action with what TenantsGuard knows: control status, fixability, plan, frameworks.
        $actions = collect($snap?->controls ?? [])->map(function ($a) use ($statuses, $inPlan, $plan) {
            $a['gain'] = round(max(0, $a['max'] - $a['score']), 2);
            $a['links'] = array_map(fn ($cid) => [
                'id' => $cid,
                'title' => FrameworkRegistry::control($cid)['title'] ?? $cid,
                'source' => FrameworkRegistry::control($cid)['source'] ?? 'posture',
                'status' => $statuses[$cid] ?? 'unknown',
                'in_plan' => in_array($cid, $inPlan, true),
                'phase' => $plan && in_array($cid, $inPlan, true) ? $plan->setting($cid)['phase'] : null,
            ], $a['tg_controls'] ?? []);
            $fw = [];
            foreach ($a['tg_controls'] ?? [] as $cid) {
                foreach (FrameworkRegistry::frameworksForControl($cid) as $k) {
                    $fw[$k] = true;
                }
            }
            $a['frameworks'] = array_keys($fw);

            return $a;
        })->sortByDesc('gain')->values();

        return view('securescore.show', [
            'tenant' => $tenant,
            'snap' => $snap,
            'prev' => $prev,
            'actions' => $actions,
            'plan' => $plan,
            'compliance' => $plan ? ComplianceEngine::evaluate($tenant, $plan) : [],
        ]);
    }
}
