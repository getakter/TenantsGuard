<?php

namespace App\Services;

use App\Models\ActivityLog;
use App\Models\Annotation;
use App\Models\Scan;
use App\Models\Tenant;
use App\Services\Checks\Catalog;
use App\Services\Checks\CheckContext;
use App\Services\Demo\MockDnsResolver;
use App\Services\Demo\MockGraphClient;
use App\Services\Dns\DnsResolver;
use App\Services\Graph\GraphClient;
use App\Services\Graph\GraphException;
use App\Services\SecureScore\SecureScoreService;
use App\Support\TenantLock;
use Illuminate\Support\Facades\DB;
use Throwable;

class Scanner
{
    public static function context(Tenant $tenant): CheckContext
    {
        return $tenant->isDemo()
            ? new CheckContext($tenant, new MockGraphClient($tenant), new MockDnsResolver($tenant))
            : new CheckContext($tenant, new GraphClient($tenant->ms_tenant_id), new DnsResolver);
    }

    /** Evaluate a single control, converting licence gaps and Graph errors into statuses. */
    public static function evaluate(array $check, CheckContext $ctx): array
    {
        try {
            if ($check['license']) {
                $lic = $ctx->licenses();
                if (! $lic[$check['license']]) {
                    $name = ['p1' => 'Microsoft Entra ID P1', 'p2' => 'Microsoft Entra ID P2', 'intune' => 'Microsoft Intune'][$check['license']];

                    return ['status' => 'na', 'summary' => "Requires {$name}, which this tenant is not licensed for.", 'evidence' => ['licenses' => $lic['skus']], 'affected' => [], 'fix' => null];
                }
            }

            return ($check['run'])($ctx) + ['evidence' => [], 'affected' => [], 'fix' => null];
        } catch (GraphException $e) {
            $hint = $check['permission'] ? ' Required permission: '.$check['permission'].'.' : '';

            return ['status' => 'error', 'summary' => $e->friendly().($e->isPermissionOrLicense() ? $hint : ''), 'evidence' => ['status' => $e->status, 'code' => $e->graphCode], 'affected' => [], 'fix' => null];
        } catch (Throwable $e) {
            report($e);

            return ['status' => 'error', 'summary' => 'Unexpected error: '.$e->getMessage(), 'evidence' => [], 'affected' => [], 'fix' => null];
        }
    }

    /**
     * Score = passed weight / (passed + failed weight). Fails covered by an
     * "accepted" or "compensating" annotation are excluded from both sides.
     */
    public static function score(array $statuses, array $excluded): int
    {
        $weights = config('tenantsguard.severity_weights');
        $pass = $fail = 0;
        foreach ($statuses as $id => $status) {
            $w = $weights[Catalog::find($id)['severity'] ?? 'low'] ?? 0;
            if ($status === 'pass') {
                $pass += $w;
            } elseif ($status === 'fail' && ! in_array($id, $excluded, true)) {
                $fail += $w;
            }
        }

        return ($pass + $fail) === 0 ? 100 : (int) round(100 * $pass / ($pass + $fail));
    }

    public function scan(Tenant $tenant, string $trigger = 'manual'): Scan
    {
        return TenantLock::run($tenant, fn () => $this->doScan($tenant, $trigger));
    }

    private function doScan(Tenant $tenant, string $trigger): Scan
    {
        if (! $tenant->isReady()) {
            throw new GraphException('Admin consent has not been granted for this tenant yet.');
        }

        $started = microtime(true);
        $ctx = self::context($tenant);
        $results = [];
        foreach (Catalog::all() as $id => $check) {
            $results[$id] = self::evaluate($check, $ctx);
        }

        $statuses = array_map(fn ($r) => $r['status'], $results);
        $excluded = Annotation::where('tenant_id', $tenant->id)->get()->filter->excludesFromScore()->pluck('check_id')->all();
        $excluded = array_values(array_unique(array_merge($excluded, $tenant->complianceProfile?->scoreExclusions() ?? [])));

        $counts = array_count_values($statuses) + array_fill_keys(array_keys(Catalog::STATUSES), 0);
        foreach (Catalog::SEVERITIES as $sev) {
            $counts['fail_'.$sev] = 0;
        }
        foreach ($results as $id => $r) {
            if ($r['status'] === 'fail' && ! in_array($id, $excluded, true)) {
                $counts['fail_'.Catalog::find($id)['severity']]++;
            }
        }

        $previous = $tenant->scans()->with('results')->first();
        $drifted = [];
        if ($previous) {
            $prev = $previous->results->pluck('status', 'check_id')->all();
            foreach ($statuses as $id => $status) {
                if (($prev[$id] ?? null) === 'pass' && $status === 'fail') {
                    $drifted[] = $id;
                }
            }
        }

        $scan = DB::transaction(function () use ($tenant, $trigger, $statuses, $excluded, $counts, $drifted, $started, $results) {
            $scan = Scan::create([
                'tenant_id' => $tenant->id,
                'trigger' => $trigger,
                'score' => self::score($statuses, $excluded),
                'counts' => $counts,
                'drifted' => $drifted,
                'duration_ms' => (int) ((microtime(true) - $started) * 1000),
            ]);
            foreach ($results as $id => $r) {
                $scan->results()->create([
                    'check_id' => $id,
                    'status' => $r['status'],
                    'summary' => $r['summary'],
                    'evidence' => $r['evidence'] ?: null,
                    'affected' => $r['affected'] ? array_slice($r['affected'], 0, 500) : null,
                    'fix' => $r['fix'],
                ]);
            }
            $tenant->update(['last_score' => $scan->score, 'last_scanned_at' => now()]);

            return $scan;
        });

        // Microsoft Secure Score is captured alongside every scan (never fails the scan).
        try {
            app(SecureScoreService::class)->capture($tenant, $ctx->graph, $scan);
        } catch (Throwable $e) {
            report($e);
        }

        if ($drifted) {
            ActivityLog::record('drift', $tenant, 'failure', count($drifted).' controls drifted from pass to fail: '.implode(', ', $drifted), null, ['checks' => $drifted, 'scan' => $scan->id]);
            Notifier::send('drift', count($drifted).' control(s) drifted', 'These controls passed in the previous scan and fail now: '.implode(', ', array_map(fn ($id) => $id.' '.(Catalog::find($id)['title'] ?? ''), $drifted)).'.', $tenant);
        }

        return $scan;
    }

    /** Recompute the stored score of the latest scan after annotations change. */
    public static function rescore(Tenant $tenant): void
    {
        $scan = $tenant->scans()->with('results')->first();
        if (! $scan) {
            return;
        }
        $excluded = Annotation::where('tenant_id', $tenant->id)->get()->filter->excludesFromScore()->pluck('check_id')->all();
        $excluded = array_values(array_unique(array_merge($excluded, $tenant->complianceProfile?->scoreExclusions() ?? [])));
        $statuses = $scan->results->pluck('status', 'check_id')->all();
        $counts = $scan->counts;
        foreach (Catalog::SEVERITIES as $sev) {
            $counts['fail_'.$sev] = 0;
        }
        foreach ($statuses as $id => $s) {
            if ($s === 'fail' && ! in_array($id, $excluded, true) && Catalog::find($id)) {
                $counts['fail_'.Catalog::find($id)['severity']]++;
            }
        }
        $scan->update(['score' => self::score($statuses, $excluded), 'counts' => $counts]);
        $tenant->update(['last_score' => $scan->score]);
    }
}
