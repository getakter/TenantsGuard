<?php

namespace App\Services\Compliance;

use App\Models\Annotation;
use App\Models\ComplianceProfile;
use App\Models\FrameworkAttestation;
use App\Models\IntuneDeploymentItem;
use App\Models\Tenant;

/**
 * Evaluates a tenant against the frameworks of its compliance profile.
 *
 * Control status:
 *  - posture checks: latest scan (pass/fail). A compensating-control annotation counts as met.
 *  - Intune templates: latest real deployment (created/updated/already deployed = deployed).
 *  - controls excluded by the profile are out of scope and ignored.
 * Requirement status: attestation if recorded, else derived from in-scope controls:
 *  met (all pass) · partial (some) · not_met (none) · manual (no automated control) · na.
 * Essential Eight: a strategy achieves level N when every requirement at levels 1..N is met or N/A.
 */
class ComplianceEngine
{
    public const STATUS = [
        'met' => ['Met', 's-pass'],
        'partial' => ['Partially met', 's-manual'],
        'not_met' => ['Not met', 's-fail'],
        'manual' => ['Evidence needed', 's-info'],
        'na' => ['Not applicable', 's-na'],
    ];

    public const CONTROL_STATUS = [
        'pass' => ['Pass', 's-pass'],
        'deployed' => ['Deployed', 's-pass'],
        'compensating' => ['Compensating control', 's-pass'],
        'fail' => ['Fail', 's-fail'],
        'not_deployed' => ['Not deployed', 's-fail'],
        'accepted' => ['Risk accepted', 's-manual'],
        'unknown' => ['Manual check', 's-manual'],
        'na' => ['N/A', 's-na'],
        'out_of_scope' => ['Out of scope', 's-na'],
    ];

    /** Status of every control for a tenant. */
    public static function controlStatuses(Tenant $tenant, ?ComplianceProfile $profile = null): array
    {
        $out = [];
        $scan = $tenant->scans()->with('results')->first();
        $results = $scan ? $scan->results->keyBy('check_id') : collect();
        $notes = Annotation::where('tenant_id', $tenant->id)->get()->keyBy('check_id');
        $intune = IntuneDeploymentItem::where('tenant_id', $tenant->id)
            ->whereHas('deployment', fn ($q) => $q->where('dry_run', false))
            ->orderBy('id')->get()->keyBy('template');

        foreach (FrameworkRegistry::controls() as $id => $c) {
            if ($profile?->isExcluded($id)) {
                $out[$id] = 'out_of_scope';

                continue;
            }
            if ($c['source'] === 'posture') {
                $r = $results[$id] ?? null;
                $note = $notes[$id] ?? null;
                $out[$id] = match (true) {
                    ! $r => 'unknown',
                    $r->status === 'pass' => 'pass',
                    $r->status === 'fail' && $note?->type === 'compensating' => 'compensating',
                    $r->status === 'fail' && $note?->type === 'accepted' => 'accepted',
                    $r->status === 'fail' => 'fail',
                    in_array($r->status, ['na', 'info'], true) => 'na',
                    default => 'unknown',
                };
            } else {
                $item = $intune[$id] ?? null;
                $out[$id] = match ($item?->status) {
                    'created', 'updated', 'exists', 'ready' => 'deployed',
                    'skipped' => 'na',
                    null => 'not_deployed',
                    default => 'fail',
                };
            }
        }

        return $out;
    }

    public static function evaluate(Tenant $tenant, ?ComplianceProfile $profile = null): array
    {
        $profile ??= $tenant->complianceProfile;
        if (! $profile) {
            return [];
        }
        $statuses = self::controlStatuses($tenant, $profile);
        $attest = FrameworkAttestation::with('user')->where('tenant_id', $tenant->id)->get()->keyBy(fn ($a) => $a->framework.'|'.$a->requirement);

        $out = [];
        foreach ($profile->frameworkKeys() as $key) {
            $fw = FrameworkRegistry::find($key);
            $target = $profile->targetLevel($key);
            $reqs = [];
            foreach ($fw['requirements'] as $r) {
                if ($target && $r['level'] && $r['level'] > $target) {
                    continue;
                }
                $controls = array_map(fn ($cid) => [
                    'id' => $cid,
                    'title' => FrameworkRegistry::control($cid)['title'] ?? $cid,
                    'source' => FrameworkRegistry::control($cid)['source'] ?? 'posture',
                    'status' => $statuses[$cid] ?? 'unknown',
                ], $r['controls']);
                $a = $attest[$key.'|'.$r['id']] ?? null;
                $reqs[] = $r + ['controls_status' => $controls, 'attestation' => $a, 'status' => self::requirementStatus($controls, $a)];
            }

            $counts = array_count_values(array_column($reqs, 'status')) + array_fill_keys(array_keys(self::STATUS), 0);
            $denominator = $counts['met'] + $counts['partial'] + $counts['not_met'] + $counts['manual'];
            $entry = [
                'framework' => $fw,
                'target' => $target,
                'requirements' => $reqs,
                'counts' => $counts,
                'score' => $denominator ? (int) round(100 * ($counts['met'] + 0.5 * $counts['partial']) / $denominator) : null,
                'automated' => count(array_filter($reqs, fn ($r) => array_filter($r['controls_status'], fn ($c) => $c['status'] !== 'out_of_scope'))),
            ];
            if ($fw['levels']) {
                $entry['maturity'] = self::maturity($reqs, $fw['levels']);
            }
            $out[$key] = $entry;
        }

        return $out;
    }

    private static function requirementStatus(array $controls, ?FrameworkAttestation $a): string
    {
        if ($a) {
            return ['implemented' => 'met', 'partial' => 'partial', 'not_implemented' => 'not_met', 'not_applicable' => 'na'][$a->status] ?? 'manual';
        }
        $scoped = array_values(array_filter($controls, fn ($c) => $c['status'] !== 'out_of_scope'));
        if (! $scoped) {
            return 'manual';
        }
        $relevant = array_values(array_filter($scoped, fn ($c) => $c['status'] !== 'na'));
        if (! $relevant) {
            return 'na';
        }
        $passing = count(array_filter($relevant, fn ($c) => in_array($c['status'], ['pass', 'deployed', 'compensating'], true)));

        return match (true) {
            $passing === count($relevant) => 'met',
            $passing > 0 => 'partial',
            default => 'not_met',
        };
    }

    /** @return array<string, array{achieved: int, levels: array<int, string>}> per strategy */
    private static function maturity(array $reqs, array $levels): array
    {
        $out = [];
        foreach (collect($reqs)->groupBy('group') as $group => $items) {
            $achieved = 0;
            $perLevel = [];
            foreach ($levels as $level) {
                $atLevel = $items->where('level', $level);
                $perLevel[$level] = $atLevel->isEmpty() ? 'na' : (
                    $atLevel->every(fn ($r) => in_array($r['status'], ['met', 'na'], true)) ? 'met' :
                    ($atLevel->contains(fn ($r) => in_array($r['status'], ['met', 'partial'], true)) ? 'partial' : 'not_met'));
            }
            foreach ($levels as $level) {
                $upTo = $items->filter(fn ($r) => $r['level'] <= $level);
                if ($upTo->isNotEmpty() && $upTo->every(fn ($r) => in_array($r['status'], ['met', 'na'], true))) {
                    $achieved = $level;
                } else {
                    break;
                }
            }
            $out[$group] = ['achieved' => $achieved, 'levels' => $perLevel];
        }

        return $out;
    }
}
