<?php

namespace App\Services;

use App\Models\Annotation;
use App\Models\ControlPlanRun;
use App\Models\FrameworkAttestation;
use App\Models\IntuneDeployment;
use App\Models\Setting;
use App\Models\User;
use App\Services\Compliance\ComplianceEngine;
use App\Support\MicrosoftApp;
use Carbon\Carbon;

/** Multi-client overview for MSPs: one row per tenant and a prioritised "needs attention" queue. */
class Portfolio
{
    public static function rows(User $user): array
    {
        $tenants = $user->visibleTenants()->with([
            'complianceProfile',
            'scans' => fn ($q) => $q->limit(2),
            'secureScores' => fn ($q) => $q->select(['id', 'tenant_id', 'percent', 'error'])->limit(2),
        ])->orderBy('name')->get();
        $staleAfter = now()->subHours(max(1, config('tenantsguard.scan_interval_hours')) * 2);
        $rows = [];

        foreach ($tenants as $t) {
            [$latest, $previous] = [$t->scans[0] ?? null, $t->scans[1] ?? null];
            $plan = $t->complianceProfile;
            $progress = null;
            if ($plan && $t->isReady()) {
                $ids = $plan->inScopeControls();
                $st = ComplianceEngine::controlStatuses($t, $plan);
                $done = count(array_filter($ids, fn ($id) => in_array($st[$id] ?? '', ['pass', 'deployed', 'compensating'], true)));
                $progress = $ids ? (int) round(100 * $done / count($ids)) : null;
            }
            [$ss, $ssPrev] = [$t->secureScores[0] ?? null, $t->secureScores[1] ?? null];
            $rows[] = [
                'tenant' => $t,
                'secure' => $ss && $ss->error === null ? $ss->percent : null,
                'secure_delta' => $ss && $ssPrev && $ss->error === null && $ssPrev->error === null ? round($ss->percent - $ssPrev->percent, 1) : null,
                'secure_error' => $ss?->error,
                'score' => $latest?->score,
                'delta' => $latest && $previous ? $latest->score - $previous->score : null,
                'critical' => $latest->counts['fail_critical'] ?? 0,
                'high' => $latest->counts['fail_high'] ?? 0,
                'errors' => $latest->counts['error'] ?? 0,
                'drift' => count($latest?->drifted ?? []),
                'plan' => $plan,
                'progress' => $progress,
                'scanned' => $t->last_scanned_at,
                'stale' => $t->isReady() && (! $t->last_scanned_at || $t->last_scanned_at->lt($staleAfter)),
            ];
        }

        return $rows;
    }

    /** @return array<int, array{level: string, text: string, url: string, tenant: ?string}> */
    public static function attention(User $user, array $rows): array
    {
        $items = [];
        $add = function (string $level, string $text, string $url, ?string $tenant = null) use (&$items) {
            $items[] = compact('level', 'text', 'url', 'tenant');
        };

        // ---- platform health (people who manage settings) ----
        if ($user->can('settings.manage')) {
            if (! MicrosoftApp::configured()) {
                $add('warning', 'The Microsoft app is not set up, so tenants cannot be connected.', route('settings.index').'#microsoft');
            }
            if ($exp = Setting::find('ms_secret_expires')?->value) {
                $exp = Carbon::parse($exp);
                if ($exp->isPast()) {
                    $add('critical', 'The Microsoft app secret expired '.$exp->diffForHumans().'. Every tenant has lost access. Create a new secret in Settings.', route('settings.index').'#microsoft');
                } elseif ($exp->lt(now()->addDays(30))) {
                    $add('warning', 'The Microsoft app secret expires '.$exp->diffForHumans().'. Renew it to avoid losing access to all tenants.', route('settings.index').'#microsoft');
                }
            }
            $beat = Setting::find('scheduler_last_run')?->value;
            if (collect($rows)->contains(fn ($r) => $r['tenant']->isReady())) {
                if (! $beat) {
                    $add('info', 'Automatic rescans are not running. Start "php artisan schedule:work" (or a scheduled task) for drift detection.', route('settings.index').'#health');
                } elseif (Carbon::parse($beat)->lt(now()->subHours(3))) {
                    $add('warning', 'The scheduler last ran '.Carbon::parse($beat)->diffForHumans().'. Drift detection is paused.', route('settings.index').'#health');
                }
            }
        }

        // ---- per tenant ----
        foreach ($rows as $r) {
            $t = $r['tenant'];
            if (! $t->isReady()) {
                $add($t->consent_status === 'failed' ? 'critical' : 'warning', $t->consent_status === 'failed' ? 'Admin consent failed or access was lost.' : 'Waiting for admin consent.', route('tenants.show', $t), $t->name);

                continue;
            }
            if ($r['errors'] > 0) {
                $add('warning', "{$r['errors']} checks could not run (missing permission or consent). Re-grant consent.", route('tenants.show', $t), $t->name);
            }
            if ($r['secure_delta'] !== null && $r['secure_delta'] <= -5) {
                $add('warning', 'Microsoft Secure Score dropped '.abs($r['secure_delta']).' points to '.round($r['secure']).'%.', route('tenants.securescore', $t), $t->name);
            }
            if ($r['secure_error'] && str_contains($r['secure_error'], 'SecurityEvents')) {
                $add('info', 'Secure Score unavailable: missing SecurityEvents.Read.All. Re-grant consent.', route('tenants.securescore', $t), $t->name);
            }
            if ($r['drift'] > 0) {
                $add('critical', "{$r['drift']} control(s) drifted since the previous scan.", route('tenants.show', $t), $t->name);
            }
            if ($r['critical'] > 0) {
                $add('warning', "{$r['critical']} critical control(s) failing.", route('tenants.show', $t), $t->name);
            }
            if ($r['stale']) {
                $add('info', $r['scanned'] ? 'Last scan '.$r['scanned']->diffForHumans().'.' : 'Never scanned.', route('tenants.show', $t), $t->name);
            }
        }

        $ids = collect($rows)->pluck('tenant.id')->all();
        $names = collect($rows)->pluck('tenant.name', 'tenant.id');

        foreach (Annotation::whereIn('tenant_id', $ids)->whereNotNull('review_by')->whereDate('review_by', '<=', now())->get() as $a) {
            $add('info', "Risk decision on {$a->check_id} is due for review (".$a->review_by->toFormattedDateString().').', route('tenants.show', $a->tenant_id).'#'.$a->check_id, $names[$a->tenant_id] ?? null);
        }
        foreach (FrameworkAttestation::whereIn('tenant_id', $ids)->whereNotNull('review_by')->whereDate('review_by', '<=', now())->get() as $a) {
            $add('info', "Attestation {$a->requirement} is due for review.", route('compliance.tenant', $a->tenant_id), $names[$a->tenant_id] ?? null);
        }
        foreach (ControlPlanRun::whereIn('tenant_id', $ids)->where('dry_run', false)->where('created_at', '>=', now()->subDays(7))->latest('id')->get()->unique('tenant_id') as $run) {
            if (($run->summary['failed'] ?? 0) > 0) {
                $add('warning', "Last control plan run had {$run->summary['failed']} failure(s).", route('plans.rollout', [$run->tenant_id, 'run' => $run->id]), $names[$run->tenant_id] ?? null);
            }
        }
        foreach (IntuneDeployment::whereIn('tenant_id', $ids)->where('dry_run', false)->whereIn('status', ['failed', 'partial'])->where('created_at', '>=', now()->subDays(7))->get()->unique('tenant_id') as $d) {
            $add('warning', "Intune deployment \"{$d->bundle_name}\" had failures.", route('intune.run', $d->batch), $names[$d->tenant_id] ?? null);
        }

        $order = ['critical' => 0, 'warning' => 1, 'info' => 2];
        usort($items, fn ($a, $b) => $order[$a['level']] <=> $order[$b['level']]);

        return $items;
    }

    public static function allTags(array $rows): array
    {
        return collect($rows)->pluck('tenant.tags')->flatten()->filter()->unique()->sort()->values()->all();
    }
}
