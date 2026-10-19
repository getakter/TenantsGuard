<?php

namespace App\Http\Controllers;

use App\Models\ActivityLog;
use App\Models\Setting;
use App\Models\Tenant;
use App\Services\Graph\GraphException;
use App\Services\Licensing\LicenseInsights;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/** Licence optimisation across clients: unused seats, idle and disabled licensed users, duplicates. */
class LicensingController extends Controller
{
    public function index(Request $request)
    {
        $rows = $request->user()->visibleTenants()->orderBy('name')->get()->filter->isReady()->map(function (Tenant $t) {
            $r = $t->isDemo() ? (new LicenseInsights($t))->analyse() : LicenseInsights::cached($t);

            return ['tenant' => $t, 'r' => $r];
        })->values();
        $done = $rows->whereNotNull('r');

        return view('licensing.index', [
            'rows' => $rows,
            'currency' => LicenseInsights::currency(),
            'totals' => [
                'monthly' => $done->sum(fn ($x) => $x['r']['totals']['monthly']),
                'savings' => $done->sum(fn ($x) => $x['r']['totals']['savings']),
                'unused_seats' => $done->sum(fn ($x) => $x['r']['totals']['unused_seats']),
                'people' => $done->sum(fn ($x) => count($x['r']['inactive']) + count($x['r']['disabled'])),
            ],
            'prices' => LicenseInsights::prices(),
        ]);
    }

    public function tenant(Request $request, Tenant $tenant)
    {
        $days = (int) $request->integer('days', 90);
        try {
            $r = (new LicenseInsights($tenant))->analyse($days, $request->boolean('refresh'));
            $error = null;
        } catch (GraphException $e) {
            $r = null;
            $error = $e->friendly();
        }

        return view('licensing.tenant', ['tenant' => $tenant, 'r' => $r, 'error' => $error, 'days' => $days]);
    }

    public function refresh(Tenant $tenant)
    {
        try {
            (new LicenseInsights($tenant))->analyse(90, true);
        } catch (GraphException $e) {
            return back()->withErrors(['licensing' => $e->friendly()]);
        }

        return back()->with('status', "Licence data for {$tenant->name} refreshed.");
    }

    public function export(Request $request, Tenant $tenant)
    {
        $r = (new LicenseInsights($tenant))->analyse((int) $request->integer('days', 90));
        $cur = $r['currency'];
        $safe = fn ($v) => preg_match('/^[=+\-@\t\r]/', (string) $v) ? "'".$v : $v; // spreadsheet formula injection
        $out = fopen('php://temp', 'r+');
        fputcsv($out, ['Finding', 'User or licence', 'Sign-in name', 'Detail', "Monthly cost ({$cur})"]);
        foreach ($r['skus'] as $s) {
            if ($s['unused'] > 0) {
                fputcsv($out, ['Unused seats', $safe($s['name']), '', "{$s['unused']} of {$s['total']} unassigned", $s['waste']]);
            }
        }
        foreach ($r['disabled'] as $u) {
            fputcsv($out, ['Disabled account with licences', $safe($u['name']), $safe($u['upn']), $safe(implode(', ', $u['licences'])), $u['cost']]);
        }
        foreach ($r['inactive'] as $u) {
            fputcsv($out, ["No sign-in for {$r['days']}+ days", $safe($u['name']), $safe($u['upn']), $u['last_signin'] ? 'Last sign-in '.substr($u['last_signin'], 0, 10) : 'Never signed in', $u['cost']]);
        }
        foreach ($r['overlaps'] as $o) {
            fputcsv($out, ['Duplicate licence', $safe($o['name']), $safe($o['upn']), $safe("{$o['redundant']} is included in {$o['covered_by']}"), $o['cost']]);
        }
        rewind($out);
        ActivityLog::record('licensing.export', $tenant, 'info', 'Exported licence optimisation report');

        return response(stream_get_contents($out), 200, [
            'Content-Type' => 'text/csv; charset=utf-8',
            'Content-Disposition' => 'attachment; filename="licence-optimisation-'.Str::slug($tenant->name).'.csv"',
        ]);
    }

    /** Price list used for estimates (settings.manage). */
    public function prices(Request $request)
    {
        $data = $request->validate([
            'currency' => 'required|string|size:3|alpha',
            'prices' => 'array',
            'prices.*' => 'nullable|numeric|min:0|max:10000',
            'new_sku' => 'nullable|string|max:80|regex:/^[A-Za-z0-9_\-]+$/',
            'new_price' => 'nullable|required_with:new_sku|numeric|min:0|max:10000',
        ]);
        $prices = array_filter(array_map(fn ($v) => $v === null || $v === '' ? null : round((float) $v, 2), $data['prices'] ?? []), fn ($v) => $v !== null);
        if (! empty($data['new_sku'])) {
            $prices[$data['new_sku']] = round((float) $data['new_price'], 2);
        }
        Setting::put('license_prices', json_encode($prices));
        Setting::put('license_currency', strtoupper($data['currency']));
        foreach (Tenant::all() as $t) {
            foreach (LicenseInsights::INACTIVE_DAYS as $d) {
                cache()->forget("tg:licinsights:{$t->id}:{$d}");
            }
        }
        ActivityLog::record('licensing.prices', null, 'info', 'Licence price list updated');

        return back()->with('status', 'Prices saved. Estimates are recalculated on the next refresh.');
    }
}
