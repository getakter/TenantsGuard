<?php

namespace App\Http\Controllers;

use App\Models\ActivityLog;
use App\Models\Baseline;
use App\Services\BaselineMatrix;
use App\Services\Checks\Catalog;
use App\Services\FixRunner;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class BaselineController extends Controller
{
    public function index()
    {
        $fixable = array_filter(Catalog::all(), fn ($c) => ! empty($c['fix']));
        $tenants = request()->user()->visibleTenants()->orderBy('name')->get();

        return view('baselines.index', [
            'baselines' => Baseline::orderBy('name')->get(),
            'tenants' => $tenants,
            'matrix' => BaselineMatrix::build($tenants),
            'fixable' => $fixable,
            'catalog' => Catalog::all(),
            'report' => session('baseline_report'),
        ]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'name' => 'required|string|max:120',
            'description' => 'nullable|string|max:500',
            'check_ids' => 'required|array|min:1',
            'check_ids.*' => [Rule::in(array_keys(Catalog::all()))],
        ]);
        $baseline = Baseline::create($data);
        ActivityLog::record('baseline.created', null, 'info', "Baseline \"{$baseline->name}\" created with ".count($data['check_ids']).' controls');

        return back()->with('status', 'Baseline created.');
    }

    public function destroy(Baseline $baseline)
    {
        $baseline->delete();

        return back()->with('status', 'Baseline deleted.');
    }

    public function apply(Request $request, Baseline $baseline, FixRunner $runner)
    {
        // Preferred: selection[tenant_id][] = check_id (individual items chosen in the grid).
        // Legacy: tenant_ids[] applies every failing control of the baseline.
        $request->validate([
            'selection' => 'required_without:tenant_ids|array',
            'selection.*' => 'array',
            'selection.*.*' => ['string', Rule::in($baseline->check_ids)],
            'tenant_ids' => 'required_without:selection|array',
            'tenant_ids.*' => 'integer',
        ], ['selection.required_without' => 'Switch on at least one item to apply.']);

        if ($request->has('selection')) {
            $selection = array_filter(array_map(fn ($ids) => array_values(array_unique((array) $ids)), (array) $request->input('selection')));
            $tenantIds = array_map('intval', array_keys($selection));
        } else {
            $selection = null;
            $tenantIds = array_map('intval', $request->input('tenant_ids'));
        }

        $tenants = $request->user()->visibleTenants()->whereIn('id', $tenantIds)->get();
        if ($tenants->isEmpty()) {
            return back()->withErrors(['selection' => 'Switch on at least one item to apply.']);
        }
        $report = $runner->applyBaseline($baseline, $tenants, $selection);
        $items = $selection === null ? null : array_sum(array_map('count', $selection));

        return redirect()->route('baselines.index')->withFragment('baseline-'.$baseline->id)
            ->with('baseline_report', ['name' => $baseline->name, 'rows' => $report])
            ->with('status', "Baseline \"{$baseline->name}\": ".($items !== null ? "{$items} selected items" : 'all items').' pushed to '.count($report).' tenants.');
    }
}
