<?php

namespace App\Services;

use App\Models\ActivityLog;
use App\Models\Annotation;
use App\Models\Tenant;
use App\Services\Checks\Catalog;
use Illuminate\Support\Collection;

/**
 * Per tenant × control state used by the baseline grid.
 *
 * States:
 *  applied     latest scan passes (greyed out, nothing to do)
 *  open        failing and automatically fixable (active toggle)
 *  accepted    failing but a risk decision is recorded (toggle available, off by default)
 *  pending     Conditional Access policy exists in report-only mode; enforce it on the tenant page
 *  manual      failing but needs manual remediation (no automated fix possible right now)
 *  na          not applicable, licence missing, manual review or could not be checked
 *  unavailable tenant not connected or never scanned
 */
class BaselineMatrix
{
    public const SELECTABLE = ['open', 'accepted'];

    /** @param Collection<int, Tenant> $tenants */
    public static function build(Collection $tenants): array
    {
        $tenants->loadMissing('latestScan.results');
        $annotations = Annotation::whereIn('tenant_id', $tenants->pluck('id'))->get()->groupBy('tenant_id');

        // Last successful automated change per tenant + control.
        $applied = [];
        ActivityLog::whereIn('tenant_id', $tenants->pluck('id'))
            ->where('outcome', 'success')->whereNotNull('check_id')
            ->where(fn ($q) => $q->where('action', 'quick-fix')->orWhere('action', 'like', 'baseline:%'))
            ->with('user')->orderBy('id')->get()
            ->each(function ($log) use (&$applied) {
                $applied[$log->tenant_id][$log->check_id] = $log;
            });

        $matrix = [];
        foreach ($tenants as $tenant) {
            $results = $tenant->latestScan?->results->keyBy('check_id') ?? collect();
            $notes = ($annotations[$tenant->id] ?? collect())->keyBy('check_id');
            foreach (Catalog::all() as $id => $check) {
                $matrix[$tenant->id][$id] = self::cell($tenant, $results[$id] ?? null, $notes[$id] ?? null, $applied[$tenant->id][$id] ?? null);
            }
        }

        return $matrix;
    }

    private static function cell(Tenant $tenant, $result, ?Annotation $note, ?ActivityLog $log): array
    {
        if (! $tenant->isReady()) {
            return ['state' => 'unavailable', 'label' => 'Not connected', 'detail' => 'Grant admin consent first.'];
        }
        if (! $result) {
            return ['state' => 'unavailable', 'label' => 'Not scanned', 'detail' => 'Run a scan first.'];
        }

        $how = $log
            ? 'Applied '.$log->created_at->format('M j, Y').' via '.($log->action === 'quick-fix' ? 'quick fix' : str_replace('baseline:', 'baseline "', $log->action).'"').($log->user ? ' by '.$log->user->name : '')
            : null;

        return match ($result->status) {
            'pass' => ['state' => 'applied', 'label' => 'Applied', 'detail' => $how ?? 'Already compliant.'],
            'fail' => match (true) {
                $note && $note->excludesFromScore() => ['state' => 'accepted', 'label' => Annotation::TYPES[$note->type], 'detail' => $note->note],
                ($result->fix['step'] ?? null) === 2 => ['state' => 'pending', 'label' => 'Report-only', 'detail' => 'Policy created in report-only mode. Review it, then enforce it on the tenant page.'],
                empty($result->fix) => ['state' => 'manual', 'label' => 'Manual', 'detail' => $result->summary],
                default => ['state' => 'open', 'label' => 'Not applied', 'detail' => $result->summary.' Impact: '.($result->fix['impact'] ?? '')],
            },
            default => ['state' => 'na', 'label' => Catalog::STATUSES[$result->status] ?? $result->status, 'detail' => $result->summary],
        };
    }
}
