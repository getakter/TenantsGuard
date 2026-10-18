<?php

namespace App\Services\Compliance;

use App\Models\ActivityLog;
use App\Models\ComplianceProfile;
use App\Models\ControlPlanRun;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Checks\Catalog;
use App\Services\FixRunner;
use App\Services\Intune\IntuneDeployer;
use App\Services\Notifier;
use App\Services\Scanner;
use App\Support\TenantLock;
use Throwable;

/**
 * Applies (or previews) controls of a control plan to a tenant:
 *  - Microsoft 365 posture controls with an automatic fix: FixRunner in safe mode
 *    (Conditional Access is only ever created in report-only mode here)
 *  - Intune templates: IntuneDeployer with the plan's assignment per control
 *  - everything else: listed as a manual action
 * The user's permissions are respected per control type. One rescan runs at the end.
 */
class PlanRunner
{
    public function __construct(private readonly FixRunner $fixes, private readonly IntuneDeployer $intune, private readonly Scanner $scanner) {}

    public function run(Tenant $tenant, ComplianceProfile $plan, array $controlIds, bool $dryRun, User $user, string $scope): ControlPlanRun
    {
        return TenantLock::run($tenant, fn () => $this->doRun($tenant, $plan, $controlIds, $dryRun, $user, $scope));
    }

    private function doRun(Tenant $tenant, ComplianceProfile $plan, array $controlIds, bool $dryRun, User $user, string $scope): ControlPlanRun
    {
        $inScope = $plan->inScopeControls();
        $controlIds = array_values(array_intersect($inScope, $controlIds));
        $results = [];
        $intuneItems = [];
        $changed = false;

        foreach ($controlIds as $id) {
            $setting = $plan->setting($id);
            $title = FrameworkRegistry::control($id)['title'] ?? $id;

            if ($setting['type'] === 'intune') {
                if (! $user->can('intune.deploy')) {
                    $results[$id] = ['result' => 'skipped', 'message' => 'Your role cannot deploy Intune policies.'];

                    continue;
                }
                $intuneItems[$id] = ['template' => $id, 'assignment' => $setting['assignment'], 'group' => $setting['group'], 'params' => $setting['params']];

                continue;
            }

            if ($setting['type'] === 'manual') {
                $results[$id] = ['result' => 'manual', 'message' => Catalog::find($id)['remediation'] ?? 'Complete in the portal, then attest on the compliance scorecard.'];

                continue;
            }

            // Automatic Microsoft 365 fix
            if (! $user->can('fixes.apply')) {
                $results[$id] = ['result' => 'skipped', 'message' => 'Your role cannot apply fixes.'];

                continue;
            }
            $check = Catalog::find($id);
            $current = Scanner::evaluate($check, Scanner::context($tenant));
            if ($current['status'] === 'pass') {
                $results[$id] = ['result' => 'already', 'message' => $current['summary']];
            } elseif ($current['status'] !== 'fail' || empty($current['fix'])) {
                $results[$id] = ['result' => 'manual', 'message' => $current['summary'].' '.$check['remediation']];
            } elseif ($dryRun) {
                $results[$id] = ['result' => 'would_fix', 'message' => $current['fix']['label'].'. Impact: '.$current['fix']['impact']];
            } else {
                [$ok, $msg] = $this->fixes->apply($tenant, $id, true, null, 'plan:'.$plan->name);
                $skippedCa = $ok && str_starts_with($msg, 'Skipped');
                $results[$id] = ['result' => $ok ? ($skippedCa ? 'manual' : 'fixed') : 'failed', 'message' => $skippedCa ? $msg.' Enforce it from the tenant\'s security posture page after review.' : $msg];
                $changed = $changed || ($ok && ! $skippedCa);
            }
            $results[$id]['title'] = $title;
        }

        if ($intuneItems) {
            $deployment = $this->intune->run($tenant, array_values($intuneItems), $dryRun, false, null, 'Plan: '.$plan->name);
            foreach ($deployment->items as $item) {
                $map = [
                    'created' => 'deployed', 'updated' => 'deployed', 'exists' => 'already', 'ready' => 'already',
                    'would_create' => 'would_deploy', 'would_update' => 'would_deploy', 'skipped' => 'skipped', 'manual' => 'manual', 'failed' => 'failed',
                ];
                $results[$item->template] = ['result' => $map[$item->status] ?? 'failed', 'message' => trim(($item->assignment ? $item->assignment.'. ' : '').$item->message)];
                $changed = $changed || in_array($item->status, ['created', 'updated'], true);
            }
        }

        foreach ($results as $id => &$r) {
            $r['title'] ??= FrameworkRegistry::control($id)['title'] ?? $id;
            $r['phase'] = $plan->setting($id)['phase'];
        }
        unset($r);

        if (! $dryRun && $changed) {
            try {
                $this->scanner->scan($tenant, 'plan');
            } catch (Throwable) {
            }
        }

        $summary = array_count_values(array_column($results, 'result'));
        $run = ControlPlanRun::create([
            'tenant_id' => $tenant->id,
            'profile_id' => $plan->id,
            'plan_name' => $plan->name,
            'user_id' => $user->id,
            'dry_run' => $dryRun,
            'scope' => $scope,
            'summary' => $summary,
            'results' => $results,
        ]);

        $text = collect($summary)->map(fn ($n, $k) => $n.' '.strtolower(ControlPlanRun::RESULTS[$k][0] ?? $k))->implode(', ');
        ActivityLog::record($dryRun ? 'plan.preview' : 'plan.apply', $tenant, ($summary['failed'] ?? 0) ? 'failure' : ($dryRun ? 'info' : 'success'),
            ($dryRun ? 'Previewed' : 'Applied')." control plan \"{$plan->name}\" ({$scope}): {$text}", null, ['run' => $run->id]);
        if (! $dryRun && ($summary['failed'] ?? 0)) {
            Notifier::send('change_failed', "Control plan \"{$plan->name}\": {$summary['failed']} control(s) failed", "{$scope}: {$text}.", $tenant);
        }

        return $run;
    }
}
