<?php

namespace App\Services;

use App\Models\Annotation;
use App\Models\FrameworkAttestation;
use App\Models\Scan;
use App\Models\SecureScoreSnapshot;
use App\Models\Setting;
use App\Models\Tenant;
use App\Services\Checks\Catalog;
use App\Services\Compliance\ComplianceEngine;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

class ReportBuilder
{
    /** Assemble everything the executive / delta report and the Excel export need. */
    public static function data(Tenant $tenant, Scan $scan, ?Scan $compare = null): array
    {
        $catalog = Catalog::all();
        $results = $scan->results()->get()->keyBy('check_id');
        $annotations = Annotation::where('tenant_id', $tenant->id)->get()->keyBy('check_id');

        $rows = [];
        foreach ($catalog as $id => $c) {
            $r = $results[$id] ?? null;
            if (! $r) {
                continue;
            }
            $rows[$id] = ['check' => $c, 'result' => $r, 'annotation' => $annotations[$id] ?? null];
        }

        $sevOrder = array_flip(Catalog::SEVERITIES);
        $failures = collect($rows)
            ->filter(fn ($x) => $x['result']->status === 'fail')
            ->sortBy(fn ($x) => [$x['annotation']?->excludesFromScore() ? 1 : 0, $sevOrder[$x['check']['severity']]])
            ->all();

        $byCategory = [];
        foreach (Catalog::CATEGORIES as $cat) {
            $in = array_filter($rows, fn ($x) => $x['check']['category'] === $cat);
            $byCategory[$cat] = [
                'pass' => count(array_filter($in, fn ($x) => $x['result']->status === 'pass')),
                'fail' => count(array_filter($in, fn ($x) => $x['result']->status === 'fail')),
                'other' => count(array_filter($in, fn ($x) => ! in_array($x['result']->status, ['pass', 'fail'], true))),
            ];
        }

        $frameworks = [];
        foreach (Catalog::FRAMEWORKS as $key => $label) {
            $mapped = array_filter($rows, fn ($x) => ! empty($x['check']['frameworks'][$key]));
            $pass = count(array_filter($mapped, fn ($x) => $x['result']->status === 'pass'));
            $fail = count(array_filter($mapped, fn ($x) => $x['result']->status === 'fail'));
            $frameworks[$key] = ['label' => $label, 'mapped' => count($mapped), 'pass' => $pass, 'fail' => $fail,
                'pct' => ($pass + $fail) ? (int) round(100 * $pass / ($pass + $fail)) : null];
        }

        $delta = null;
        if ($compare) {
            $before = $compare->results()->pluck('status', 'check_id')->all();
            $fixed = $regressed = [];
            foreach ($rows as $id => $x) {
                $was = $before[$id] ?? null;
                if ($was === 'fail' && $x['result']->status === 'pass') {
                    $fixed[] = $id;
                } elseif ($was === 'pass' && $x['result']->status === 'fail') {
                    $regressed[] = $id;
                }
            }
            $delta = ['from' => $compare, 'fixed' => $fixed, 'regressed' => $regressed, 'change' => $scan->score - $compare->score];
        }

        $trend = $tenant->scans()->where('created_at', '>=', now()->subDays(config('tenantsguard.retention_days')))
            ->reorder('id')->get(['id', 'score', 'created_at']);

        return [
            'tenant' => $tenant,
            'scan' => $scan,
            'rows' => $rows,
            'failures' => $failures,
            'byCategory' => $byCategory,
            'frameworks' => $frameworks,
            'delta' => $delta,
            'trend' => $trend,
            'branding' => Setting::branding(),
            'statuses' => Catalog::STATUSES,
            'compliance' => ComplianceEngine::evaluate($tenant),
            'secure' => $tenant->secureScores()->first(),
            'securePrev' => $tenant->secureScores()->whereNull('error')->skip(1)->first(),
            'profile' => $tenant->complianceProfile,
        ];
    }

    public static function spreadsheet(array $d): Spreadsheet
    {
        $book = new Spreadsheet;
        $accent = ltrim($d['branding']['accent_color'], '#');

        // Summary
        $s = $book->getActiveSheet()->setTitle('Summary');
        $s->fromArray([
            [$d['branding']['company_name'].': Microsoft 365 security assessment'],
            [],
            ['Tenant', $d['tenant']->name],
            ['Tenant ID', $d['tenant']->ms_tenant_id],
            ['Scan date', $d['scan']->created_at->toDayDateTimeString()],
            ['Security score', $d['scan']->score.'%'],
            ['Critical failures', $d['scan']->counts['fail_critical'] ?? 0],
            ['High failures', $d['scan']->counts['fail_high'] ?? 0],
            ['Medium failures', $d['scan']->counts['fail_medium'] ?? 0],
            ['Low failures', $d['scan']->counts['fail_low'] ?? 0],
            ['Passed controls', $d['scan']->counts['pass'] ?? 0],
            [],
            ['Framework', 'Mapped controls', 'Passing', 'Failing', 'Compliance %'],
        ]);
        $r = 14;
        foreach ($d['frameworks'] as $fw) {
            $s->fromArray([[$fw['label'], $fw['mapped'], $fw['pass'], $fw['fail'], $fw['pct'] === null ? 'n/a' : $fw['pct'].'%']], null, "A{$r}");
            $r++;
        }
        $s->getStyle('A1')->getFont()->setBold(true)->setSize(14);
        self::header($s, 'A13:E13', $accent);

        // Findings
        $f = $book->createSheet()->setTitle('Findings');
        $f->fromArray([['Control', 'Title', 'Category', 'Severity', 'Status', 'Result', 'Remediation', 'CIS M365', 'CIS v8.1', 'NIST CSF 2.0', 'Essential Eight', 'Insurance', 'Annotation']]);
        $r = 2;
        foreach ($d['rows'] as $id => $x) {
            $fw = $x['check']['frameworks'];
            $ann = $x['annotation'] ? ucfirst($x['annotation']->type).': '.$x['annotation']->note : '';
            $f->fromArray([[$id, $x['check']['title'], $x['check']['category'], ucfirst($x['check']['severity']),
                $d['statuses'][$x['result']->status] ?? $x['result']->status, $x['result']->summary, $x['check']['remediation'],
                $fw['cis'] ?? '', $fw['cisv8'] ?? '', $fw['nist'] ?? '', $fw['e8'] ?? '', ! empty($fw['ins']) ? 'Yes' : '', $ann]], null, "A{$r}");
            $r++;
        }
        self::header($f, 'A1:M1', $accent);
        foreach (['B' => 45, 'F' => 60, 'G' => 70, 'M' => 40] as $col => $w) {
            $f->getColumnDimension($col)->setWidth($w);
        }
        $f->setAutoFilter('A1:M'.($r - 1));
        $f->freezePane('A2');

        // Affected objects
        $a = $book->createSheet()->setTitle('Affected objects');
        $a->fromArray([['Control', 'Title', 'Name', 'Identifier', 'Detail']]);
        $r = 2;
        foreach ($d['rows'] as $id => $x) {
            if ($x['result']->status !== 'fail') {
                continue;
            }
            foreach ($x['result']->affected ?? [] as $obj) {
                $a->fromArray([[$id, $x['check']['title'], $obj['name'] ?? '', $obj['id'] ?? '', $obj['detail'] ?? '']], null, "A{$r}");
                $r++;
            }
        }
        self::header($a, 'A1:E1', $accent);
        foreach (['B' => 45, 'C' => 30, 'D' => 40, 'E' => 50] as $col => $w) {
            $a->getColumnDimension($col)->setWidth($w);
        }

        // Delta
        if ($d['delta']) {
            $dl = $book->createSheet()->setTitle('Delta');
            $dl->fromArray([
                ['Compared with scan', $d['delta']['from']->created_at->toDayDateTimeString()],
                ['Score change', ($d['delta']['change'] >= 0 ? '+' : '').$d['delta']['change'].' points'],
                [],
                ['Change', 'Control', 'Title'],
            ]);
            $r = 5;
            foreach (['Fixed' => $d['delta']['fixed'], 'Regressed' => $d['delta']['regressed']] as $label => $ids) {
                foreach ($ids as $id) {
                    $dl->fromArray([[$label, $id, $d['rows'][$id]['check']['title']]], null, "A{$r}");
                    $r++;
                }
            }
            self::header($dl, 'A4:C4', $accent);
            $dl->getColumnDimension('C')->setWidth(60);
        }

        // Microsoft Secure Score
        if (($d['secure'] ?? null)?->ok()) {
            $s = $d['secure'];
            $ss = $book->createSheet()->setTitle('Secure Score');
            $ss->fromArray([
                ['Microsoft Secure Score', round($s->percent, 1).'%', $s->current_score.' / '.$s->max_score.' points'],
                ['Similar-size tenants', ($s->comparativePercent('TotalSeats') ?? 'n/a').'%'],
                ['All tenants', ($s->comparativePercent('AllTenants') ?? 'n/a').'%'],
                ['Calculated', $s->ms_created_at?->toDayDateTimeString()],
                [],
                ['Action', 'Category', 'Score', 'Max', 'Points available', 'Status', 'User impact', 'Cost', 'TenantsGuard controls'],
            ]);
            $r = 7;
            foreach (collect($s->controls)->sortByDesc(fn ($c) => $c['max'] - $c['score']) as $c) {
                $ss->fromArray([[$c['title'], $c['category'], $c['score'], $c['max'], round($c['max'] - $c['score'], 2),
                    SecureScoreSnapshot::STATUS[$c['status']][0] ?? $c['status'], $c['user_impact'], $c['cost'], implode(', ', $c['tg_controls'] ?? [])]], null, "A{$r}");
                $r++;
            }
            self::header($ss, 'A6:I6', $accent);
            $ss->getColumnDimension('A')->setWidth(70);
            $ss->setAutoFilter('A6:I'.($r - 1));
        }

        // Compliance profile
        if (! empty($d['compliance'])) {
            $cs = $book->createSheet()->setTitle('Compliance');
            $cs->fromArray([['Profile', $d['profile']->name], [], ['Framework', 'Requirement', 'Group', 'Level', 'Title', 'Status', 'Controls', 'Attestation']]);
            $r = 4;
            foreach ($d['compliance'] as $key => $fw) {
                foreach ($fw['requirements'] as $req) {
                    $controls = implode(', ', array_map(fn ($c) => $c['id'].' ('.(ComplianceEngine::CONTROL_STATUS[$c['status']][0] ?? $c['status']).')', $req['controls_status']));
                    $att = $req['attestation'] ? FrameworkAttestation::STATUSES[$req['attestation']->status].': '.$req['attestation']->note : '';
                    $cs->fromArray([[$fw['framework']['name'], $req['id'], $req['group'], $req['level'] ? 'ML'.$req['level'] : '', $req['title'],
                        ComplianceEngine::STATUS[$req['status']][0], $controls, $att]], null, "A{$r}");
                    $r++;
                }
            }
            self::header($cs, 'A3:H3', $accent);
            foreach (['A' => 30, 'B' => 22, 'C' => 32, 'E' => 60, 'G' => 50, 'H' => 50] as $col => $w) {
                $cs->getColumnDimension($col)->setWidth($w);
            }
            $cs->setAutoFilter('A3:H'.($r - 1));
        }

        foreach ($book->getAllSheets() as $sheet) {
            $sheet->getColumnDimension('A')->setWidth(22);
        }
        $book->setActiveSheetIndex(0);

        return $book;
    }

    private static function header(Worksheet $s, string $range, string $hex): void
    {
        $style = $s->getStyle($range);
        $style->getFont()->setBold(true)->getColor()->setRGB('FFFFFF');
        $style->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB(strtoupper($hex));
    }
}
