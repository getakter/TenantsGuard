<?php

namespace App\Services;

use App\Models\ActivityLog;
use App\Models\Baseline;
use App\Models\Tenant;
use App\Services\Checks\Catalog;
use App\Services\Checks\CheckContext;
use App\Services\Graph\GraphException;
use App\Support\TenantLock;
use RuntimeException;
use Throwable;

class FixRunner
{
    public function __construct(private readonly Scanner $scanner) {}

    /**
     * Apply one control's fix. Returns [ok, message].
     * $safe = true never enforces Conditional Access (only creates report-only policies).
     */
    public function apply(Tenant $tenant, string $checkId, bool $safe = false, ?CheckContext $ctx = null, string $source = 'quick-fix'): array
    {
        return TenantLock::run($tenant, fn () => $this->doApply($tenant, $checkId, $safe, $ctx, $source));
    }

    private function doApply(Tenant $tenant, string $checkId, bool $safe, ?CheckContext $ctx, string $source): array
    {
        $check = Catalog::find($checkId) ?? throw new RuntimeException("Unknown control {$checkId}");
        if (empty($check['fix'])) {
            return [false, 'This control has no automated fix. Follow the remediation guidance.'];
        }

        $ctx ??= Scanner::context($tenant);
        $current = Scanner::evaluate($check, $ctx);
        if ($current['status'] !== 'fail' || empty($current['fix'])) {
            return [false, 'Nothing to fix: the control is currently "'.(Catalog::STATUSES[$current['status']] ?? $current['status']).'"'.($current['status'] === 'fail' ? ' and needs manual remediation.' : '.')];
        }

        try {
            $message = ($check['fix'])($ctx, ['safe' => $safe]);
            ActivityLog::record($source, $tenant, 'success', $message, $checkId, ['action' => $current['fix']['label'] ?? null]);

            return [true, $message];
        } catch (GraphException $e) {
            $msg = $e->friendly();
            if ($e->isPermissionOrLicense()) {
                $msg .= ' Fixes need the write permissions listed in Settings. Grant them to the app and re-run admin consent.';
            }
            ActivityLog::record($source, $tenant, 'failure', $msg, $checkId);

            return [false, $msg];
        } catch (Throwable $e) {
            report($e);
            ActivityLog::record($source, $tenant, 'failure', $e->getMessage(), $checkId);

            return [false, $e->getMessage()];
        }
    }

    /**
     * Push a baseline to tenants: apply every failing, fixable control in safe mode, then rescan once per tenant.
     *
     * @return array<int, array{tenant: string, applied: array, skipped: array, failed: array, score: ?int}>
     */
    public function applyBaseline(Baseline $baseline, iterable $tenants, ?array $selection = null): array
    {
        $report = [];
        foreach ($tenants as $tenant) {
            // Optional per-tenant selection of individual controls (subset of the baseline).
            $ids = $selection === null
                ? $baseline->check_ids
                : array_values(array_intersect($baseline->check_ids, $selection[$tenant->id] ?? []));
            if (! $ids) {
                continue;
            }
            $row = ['tenant' => $tenant->name, 'applied' => [], 'skipped' => [], 'failed' => [], 'score' => null];
            if (! $tenant->isReady()) {
                $row['failed'][] = 'Tenant is not connected (admin consent pending).';
                $report[] = $row;

                continue;
            }
            $ctx = Scanner::context($tenant);
            foreach ($ids as $id) {
                $check = Catalog::find($id);
                if (! $check) {
                    continue;
                }
                if (empty($check['fix'])) {
                    $row['skipped'][] = "{$id}: manual remediation only";

                    continue;
                }
                [$ok, $msg] = $this->apply($tenant, $id, true, $ctx, 'baseline:'.$baseline->name);
                if ($ok && str_starts_with($msg, 'Skipped')) {
                    $row['skipped'][] = "{$id}: {$msg}";
                } elseif ($ok) {
                    $row['applied'][] = "{$id}: {$msg}";
                } elseif (str_starts_with($msg, 'Nothing to fix')) {
                    $row['skipped'][] = "{$id}: already compliant or not applicable";
                } else {
                    $row['failed'][] = "{$id}: {$msg}";
                }
                // Data may have changed: drop cached resources before the next control.
                $ctx = Scanner::context($tenant);
            }
            try {
                $row['score'] = $this->scanner->scan($tenant, 'baseline')->score;
            } catch (Throwable $e) {
                $row['failed'][] = 'Rescan failed: '.$e->getMessage();
            }
            $report[] = $row;
        }

        return $report;
    }
}
