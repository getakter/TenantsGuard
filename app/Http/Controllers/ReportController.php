<?php

namespace App\Http\Controllers;

use App\Models\ActivityLog;
use App\Models\Scan;
use App\Models\Tenant;
use App\Services\Dashboard\TenantOverview;
use App\Services\ReportBuilder;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

class ReportController extends Controller
{
    private function scans(Request $request, Tenant $tenant): array
    {
        $scan = $request->filled('scan')
            ? Scan::where('tenant_id', $tenant->id)->findOrFail($request->integer('scan'))
            : $tenant->scans()->firstOrFail();
        $compare = $request->filled('compare')
            ? Scan::where('tenant_id', $tenant->id)->findOrFail($request->integer('compare'))
            : null;

        return [$scan, $compare];
    }

    public function show(Request $request, Tenant $tenant)
    {
        [$scan, $compare] = $this->scans($request, $tenant);
        $all = array_keys(TenantDashboardController::REPORT_SECTIONS);
        $sections = $request->has('sections') ? array_values(array_intersect((array) $request->input('sections'), $all)) : $all;
        $embed = $request->boolean('embed');
        if (! $embed) {
            ActivityLog::record('report', $tenant, 'info', ($compare ? 'Delta' : 'Executive').' report generated');
        }
        $overview = array_intersect($sections, ['identity', 'licences', 'devices']) ? rescue(fn () => TenantOverview::for($tenant), [], false) : [];

        return view('reports.executive', ReportBuilder::data($tenant, $scan, $compare) + [
            'show' => fn (string $key) => in_array($key, $sections, true),
            'embed' => $embed,
            'overview' => $overview,
        ]);
    }

    public function excel(Request $request, Tenant $tenant)
    {
        [$scan, $compare] = $this->scans($request, $tenant);
        $book = ReportBuilder::spreadsheet(ReportBuilder::data($tenant, $scan, $compare));
        $file = Str::slug($tenant->name).'-m365-assessment-'.$scan->created_at->format('Y-m-d').'.xlsx';
        ActivityLog::record('report', $tenant, 'info', 'Excel export generated');

        return response()->streamDownload(fn () => (new Xlsx($book))->save('php://output'), $file, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ]);
    }
}
