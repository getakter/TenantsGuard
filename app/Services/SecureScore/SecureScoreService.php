<?php

namespace App\Services\SecureScore;

use App\Models\Scan;
use App\Models\SecureScoreSnapshot;
use App\Models\Tenant;
use App\Services\Graph\GraphClientInterface;
use App\Services\Graph\GraphException;
use App\Services\Notifier;
use Carbon\Carbon;
use Throwable;

/**
 * Captures Microsoft Secure Score (requires SecurityEvents.Read.All):
 *   GET /security/secureScores?$top=90          daily scores, comparatives, per-action scores
 *   GET /security/secureScoreControlProfiles     action catalogue (title, max, category, impact, cost, URL)
 */
class SecureScoreService
{
    public function capture(Tenant $tenant, GraphClientInterface $graph, ?Scan $scan = null): SecureScoreSnapshot
    {
        try {
            $scores = $graph->getAll('/security/secureScores?$top=90');
        } catch (GraphException $e) {
            return SecureScoreSnapshot::create([
                'tenant_id' => $tenant->id,
                'scan_id' => $scan?->id,
                'error' => $e->isPermissionOrLicense()
                    ? 'Microsoft refused access to Secure Score. The app needs SecurityEvents.Read.All and consent must be re-granted.'
                    : $e->friendly(),
            ]);
        }

        if (! $scores) {
            return SecureScoreSnapshot::create(['tenant_id' => $tenant->id, 'scan_id' => $scan?->id, 'error' => 'Microsoft has not calculated a Secure Score for this tenant yet (new tenants take up to 48 hours).']);
        }

        usort($scores, fn ($a, $b) => strcmp($b['createdDateTime'] ?? '', $a['createdDateTime'] ?? ''));
        $latest = $scores[0];

        try {
            $profiles = collect($graph->getAll('/security/secureScoreControlProfiles'))->keyBy('id');
        } catch (Throwable) {
            $profiles = collect();
        }

        $controls = [];
        $categories = array_fill_keys(SecureScoreSnapshot::CATEGORIES, ['score' => 0.0, 'max' => 0.0]);
        foreach ($latest['controlScores'] ?? [] as $cs) {
            $name = $cs['controlName'] ?? '';
            $p = $profiles[$name] ?? [];
            if ($p['deprecated'] ?? false) {
                continue;
            }
            $max = (float) ($p['maxScore'] ?? $cs['maxScore'] ?? 0);
            $score = (float) ($cs['score'] ?? 0);
            $category = $p['controlCategory'] ?? $cs['controlCategory'] ?? 'Identity';
            $title = $p['title'] ?? $cs['description'] ?? $name;
            $state = strtolower((string) (collect($p['controlStateUpdates'] ?? [])->last()['state'] ?? 'default'));
            $status = match (true) {
                $state === 'ignored' => 'risk_accepted',
                $state === 'thirdparty' => 'third_party',
                $max > 0 && $score >= $max => 'completed',
                $score > 0 => 'partial',
                default => 'to_address',
            };
            $controls[] = [
                'name' => $name,
                'title' => $title,
                'category' => $category,
                'score' => round($score, 2),
                'max' => round($max, 2),
                'status' => $status,
                'rank' => $p['rank'] ?? null,
                'user_impact' => $p['userImpact'] ?? null,
                'cost' => $p['implementationCost'] ?? null,
                'service' => $p['service'] ?? null,
                'action_url' => $p['actionUrl'] ?? null,
                'remediation' => isset($p['remediation']) ? mb_substr(strip_tags((string) $p['remediation']), 0, 600) : null,
                'tg_controls' => SecureScoreMap::controlsFor($name, $title),
            ];
            $categories[$category] ??= ['score' => 0.0, 'max' => 0.0];
            $categories[$category]['score'] += $score;
            $categories[$category]['max'] += $max;
        }
        $categories = array_filter(array_map(fn ($c) => ['score' => round($c['score'], 1), 'max' => round($c['max'], 1)], $categories), fn ($c) => $c['max'] > 0);

        $comparatives = [];
        foreach ($latest['averageComparativeScores'] ?? [] as $avg) {
            if (isset($avg['basis'], $avg['averageScore'])) {
                $comparatives[$avg['basis']] = round((float) $avg['averageScore'], 2);
            }
        }

        $history = collect($scores)->map(fn ($s) => [
            'date' => Carbon::parse($s['createdDateTime'])->toDateString(),
            'score' => round((float) $s['currentScore'], 2),
            'max' => round((float) $s['maxScore'], 2),
        ])->unique('date')->sortBy('date')->values()->all();

        $max = (float) ($latest['maxScore'] ?? 0);
        $current = (float) ($latest['currentScore'] ?? 0);
        $previous = $tenant->secureScores()->whereNull('error')->first();

        $snapshot = SecureScoreSnapshot::create([
            'tenant_id' => $tenant->id,
            'scan_id' => $scan?->id,
            'ms_created_at' => isset($latest['createdDateTime']) ? Carbon::parse($latest['createdDateTime']) : now(),
            'current_score' => round($current, 2),
            'max_score' => round($max, 2),
            'percent' => $max > 0 ? round(100 * $current / $max, 2) : null,
            'categories' => $categories,
            'comparatives' => $comparatives,
            'controls' => $controls,
            'history' => $history,
            'licensed_users' => $latest['licensedUserCount'] ?? null,
            'active_users' => $latest['activeUserCount'] ?? null,
        ]);

        // A meaningful drop (5 percentage points) is worth an alert.
        if ($previous && $previous->percent !== null && $snapshot->percent !== null && $previous->percent - $snapshot->percent >= 5) {
            Notifier::send('drift', 'Microsoft Secure Score dropped', sprintf('Secure Score fell from %.0f%% to %.0f%%.', $previous->percent, $snapshot->percent), $tenant);
        }

        return $snapshot;
    }
}
