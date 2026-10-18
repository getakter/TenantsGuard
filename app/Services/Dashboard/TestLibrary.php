<?php

namespace App\Services\Dashboard;

use App\Models\AssessmentReport;
use App\Models\Scan;
use App\Models\Tenant;
use App\Services\Checks\Catalog;
use App\Services\Intune\IntuneCatalog;
use App\Services\Intune\IntuneInventory;
use Throwable;

/**
 * One list of "tests" per client for the Dashboard: TenantsGuard controls (from the latest scan)
 * become Identity tests, and Devices-category controls plus the Intune / Zero Trust template
 * library become Device tests. Reports (built-in or custom) pick a subset of test ids.
 */
class TestLibrary
{
    /** End-user impact of fixing each control. */
    private const IMPACT = [
        'ID-01' => 'High', 'ID-02' => 'High', 'ID-03' => 'Medium', 'ID-04' => 'Medium', 'ID-05' => 'Low', 'ID-06' => 'Medium', 'ID-07' => 'Low',
        'ID-08' => 'Medium', 'ID-09' => 'Low', 'ID-10' => 'Low', 'ID-11' => 'Low', 'ID-12' => 'Low', 'ID-13' => 'Medium', 'ID-14' => 'Low', 'ID-15' => 'Low',
        'APP-01' => 'Low', 'APP-02' => 'Medium', 'APP-03' => 'Low', 'APP-04' => 'Low', 'APP-05' => 'Low',
        'COL-01' => 'Medium', 'COL-02' => 'Low', 'COL-03' => 'Medium', 'COL-04' => 'Low',
        'EML-01' => 'Low', 'EML-02' => 'Low', 'EML-03' => 'Low', 'DEV-01' => 'Medium', 'DEV-02' => 'Medium',
        'TEN-01' => 'Low', 'TEN-02' => 'Low', 'TEN-03' => 'Low', 'TEN-04' => 'Low',
    ];

    public const BUILT_IN = [
        'all' => ['All TenantsGuard tests', 'Every identity and device test in the library.'],
        'zero_trust' => ['Zero Trust assessment', 'Identity, privileged access, application and device tests: verify explicitly, least privilege, assume breach.'],
        'fw:cis' => ['CIS Microsoft 365 Foundations', 'Tests mapped to the CIS Microsoft 365 Foundations Benchmark.'],
        'fw:e8' => ['Essential Eight', 'Tests mapped to the ASD Essential Eight strategies.'],
        'fw:nist' => ['NIST CSF 2.0', 'Tests mapped to the NIST Cybersecurity Framework 2.0.'],
        'fw:ins' => ['Cyber insurance readiness', 'Controls insurers commonly ask about: MFA, admin protection, email security, backups of identity.'],
    ];

    public static function risk(string $severity): string
    {
        return match ($severity) {
            'critical', 'high' => 'High',
            'medium' => 'Medium',
            default => 'Low',
        };
    }

    /** @return array<string, array> all tests for the client, keyed by id */
    public static function tests(Tenant $tenant, ?Scan $scan = null): array
    {
        $scan ??= $tenant->scans()->first();
        $results = $scan ? $scan->results()->get()->keyBy('check_id') : collect();
        $tests = [];

        foreach (Catalog::all() as $id => $c) {
            $r = $results[$id] ?? null;
            $standard = isset($c['fix']);
            $tests[$id] = [
                'id' => $id, 'source' => 'check', 'name' => $c['title'], 'category' => $c['category'],
                'area' => $c['category'] === 'Devices' ? 'devices' : 'identity',
                'status' => $r->status ?? 'pending', 'risk' => self::risk($c['severity']), 'severity' => $c['severity'],
                'impact' => self::IMPACT[$id] ?? 'Low',
                'effort' => $standard ? (str_contains((string) ($r?->fix['label'] ?? ''), 'report-only') || str_starts_with($id, 'ID-0') ? 'Medium' : 'Low') : 'High',
                'standard' => $standard,
                'summary' => $r->summary ?? 'Not scanned yet.',
                'evidence' => $r->evidence ?? [], 'affected' => $r->affected ?? [],
                'description' => $c['description'], 'remediation' => $c['remediation'],
                'frameworks' => collect($c['frameworks'])->filter(fn ($v) => $v !== null && $v !== false)
                    ->mapWithKeys(fn ($v, $k) => [Catalog::FRAMEWORKS[$k] => $v === true ? 'Yes' : $v])->all(),
                'fw_keys' => array_keys(array_filter($c['frameworks'], fn ($v) => $v !== null && $v !== false)),
                'link' => route('tenants.show', $tenant).'#'.$id, 'link_label' => $standard ? 'Fix in Security posture' : 'Open in Security posture',
            ];
        }

        foreach (self::intuneTests($tenant) as $t) {
            $tests[$t['id']] = $t;
        }

        return $tests;
    }

    /** Intune / Zero Trust templates as device tests ("Is this in place?"). */
    private static function intuneTests(Tenant $tenant): array
    {
        try {
            $snap = IntuneInventory::snapshot($tenant);
        } catch (Throwable) {
            $snap = ['ready' => false];
        }
        $licences = $snap['licenses'] ?? [];
        $existing = collect($snap['policies'] ?? [])->pluck('name')->map(fn ($n) => strtolower($n))->flip();
        $out = [];
        foreach (IntuneCatalog::all() as $key => $t) {
            $missing = array_filter($t['license'] ?? [], fn ($l) => isset($licences[$l]) && ! $licences[$l]);
            if (! ($snap['ready'] ?? false)) {
                [$status, $summary] = ['pending', 'Device data has not been read yet.'];
            } elseif ($missing) {
                [$status, $summary] = ['na', 'Not licensed: '.implode(', ', array_map(fn ($l) => IntuneCatalog::LICENSES[$l] ?? $l, $missing)).'.'];
            } elseif ($t['kind'] === 'manual') {
                $res = $snap['prereqs'][$key]['result'] ?? ['ok' => null, 'detail' => 'Not checked.'];
                [$status, $summary] = [$res['ok'] === true ? 'pass' : ($res['ok'] === false ? 'fail' : 'manual'), $res['detail']];
            } else {
                $item = ($snap['latest'] ?? collect())[$key] ?? null;
                $deployed = ($item && in_array($item->status, ['created', 'updated', 'exists'], true)) || $existing->has(strtolower(IntuneCatalog::PREFIX.$t['name']));
                [$status, $summary] = $deployed ? ['pass', 'Deployed by TenantsGuard'.($item ? ' '.$item->updated_at->diffForHumans().'.' : '.')] : ['fail', 'Not deployed in this tenant.'];
            }
            $risk = in_array($t['category'], ['Compliance', 'Endpoint security', 'Conditional Access', 'App protection'], true) ? 'High'
                : (in_array($t['category'], ['Update management', 'Prerequisite'], true) ? 'Medium' : 'Low');
            $out[] = [
                'id' => 'INT-'.$key, 'source' => 'intune', 'name' => $t['name'], 'category' => $t['category'].' · '.(IntuneCatalog::PLATFORMS[$t['platform']] ?? $t['platform']),
                'area' => 'devices', 'status' => $status, 'risk' => $risk, 'severity' => strtolower($risk),
                'impact' => ucfirst($t['impact'] ?? 'low'), 'effort' => $t['kind'] === 'manual' ? 'High' : 'Low', 'standard' => $t['kind'] !== 'manual',
                'summary' => $summary, 'evidence' => [], 'affected' => [],
                'description' => $t['summary'].(isset($t['rationale']) ? ' '.$t['rationale'] : ''),
                'remediation' => $t['kind'] === 'manual' ? implode(' ', (array) ($t['steps'] ?? [])) : 'Deploy the "'.$t['name'].'" template from Intune and devices, starting with the pilot group.',
                'frameworks' => collect($t['frameworks'] ?? [])->mapWithKeys(fn ($v, $k) => [IntuneCatalog::FRAMEWORKS[$k] ?? $k => is_array($v) ? implode(', ', $v) : $v])->all(),
                'fw_keys' => array_keys($t['frameworks'] ?? []),
                'zt' => $t['zt'] ?? [],
                'link' => route('tenants.intune', $tenant), 'link_label' => 'Open Intune and devices',
            ];
        }

        return $out;
    }

    /** Reports the selector offers: built-in first, then custom ones. */
    public static function reports(): array
    {
        $out = [];
        foreach (self::BUILT_IN as $key => [$name, $desc]) {
            $out[$key] = ['key' => $key, 'name' => $name, 'description' => $desc, 'custom' => false];
        }
        foreach (AssessmentReport::orderBy('name')->get() as $r) {
            $out['custom:'.$r->id] = ['key' => 'custom:'.$r->id, 'name' => $r->name, 'description' => $r->description, 'custom' => true, 'model' => $r];
        }

        return $out;
    }

    /** The tests that belong to a report. */
    public static function filter(string $report, array $tests): array
    {
        if (str_starts_with($report, 'custom:')) {
            $ids = AssessmentReport::find((int) substr($report, 7))?->test_ids ?? [];

            return array_intersect_key($tests, array_flip($ids));
        }
        if (str_starts_with($report, 'fw:')) {
            $fw = substr($report, 3);
            $map = ['nist' => ['nist', 'nistcsf'], 'cis' => ['cis'], 'e8' => ['e8'], 'ins' => ['ins']][$fw] ?? [$fw];

            return array_filter($tests, fn ($t) => array_intersect($map, $t['fw_keys']));
        }
        if ($report === 'zero_trust') {
            return array_filter($tests, fn ($t) => $t['source'] === 'intune'
                ? ! empty($t['zt'])
                : in_array($t['category'], ['Identity', 'Privileged access', 'Applications', 'Devices'], true));
        }

        return $tests;
    }

    /** Pass counts for the Assessment card. */
    public static function summary(array $tests): array
    {
        $out = [];
        foreach (['identity', 'devices'] as $area) {
            $set = array_filter($tests, fn ($t) => $t['area'] === $area);
            $scored = array_filter($set, fn ($t) => in_array($t['status'], ['pass', 'fail'], true));
            $out[$area] = ['total' => count($set), 'passed' => count(array_filter($set, fn ($t) => $t['status'] === 'pass')), 'scored' => count($scored)];
        }

        return $out;
    }
}
