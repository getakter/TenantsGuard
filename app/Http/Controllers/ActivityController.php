<?php

namespace App\Http\Controllers;

use App\Models\ActivityLog;
use Illuminate\Http\Request;

class ActivityController extends Controller
{
    private function query(Request $request)
    {
        return ActivityLog::visibleTo($request->user())->with('tenant', 'user')
            ->when($request->filled('tenant'), fn ($q) => $q->where('tenant_id', $request->integer('tenant')))
            ->when($request->filled('outcome'), fn ($q) => $q->where('outcome', $request->string('outcome')))
            ->when($request->filled('from'), fn ($q) => $q->whereDate('created_at', '>=', $request->date('from')))
            ->when($request->filled('to'), fn ($q) => $q->whereDate('created_at', '<=', $request->date('to')))
            ->latest('id');
    }

    public function index(Request $request)
    {
        return view('activity.index', [
            'logs' => $this->query($request)->paginate(50)->withQueryString(),
            'tenants' => $request->user()->visibleTenants()->orderBy('name')->get(),
        ]);
    }

    /** Audit evidence for clients, auditors or PSA tickets (respects the same filters and tenant scope). */
    public function export(Request $request)
    {
        $query = $this->query($request);

        return response()->streamDownload(function () use ($query) {
            $out = fopen('php://output', 'w');
            fputcsv($out, ['When (UTC)', 'Tenant', 'User', 'Action', 'Control', 'Outcome', 'Details']);
            $query->chunk(500, function ($logs) use ($out) {
                foreach ($logs as $l) {
                    fputcsv($out, [$l->created_at->utc()->toDateTimeString(), $l->tenant?->name, $l->user?->name ?? 'System', $l->action, $l->check_id, $l->outcome, $l->message]);
                }
            });
            fclose($out);
        }, 'tenantsguard-activity-'.now()->format('Y-m-d').'.csv', ['Content-Type' => 'text/csv']);
    }
}
