<?php

namespace App\Http\Controllers;

use App\Services\Portfolio;
use Illuminate\Http\Request;

class DashboardController extends Controller
{
    public function index(Request $request)
    {
        $user = $request->user();
        $rows = Portfolio::rows($user);
        $scored = collect($rows)->whereNotNull('score');

        return view('dashboard', [
            'rows' => $rows,
            'attention' => Portfolio::attention($user, $rows),
            'tags' => Portfolio::allTags($rows),
            'plans' => collect($rows)->pluck('plan')->filter()->unique('id')->sortBy('name')->values(),
            'totals' => [
                'tenants' => count($rows),
                'avg' => $scored->count() ? (int) round($scored->avg('score')) : null,
                'secure' => collect($rows)->whereNotNull('secure')->count() ? (int) round(collect($rows)->whereNotNull('secure')->avg('secure')) : null,
                'critical' => $scored->sum('critical'),
                'drift' => $scored->sum('drift'),
                'pending' => collect($rows)->filter(fn ($r) => ! $r['tenant']->isReady())->count(),
            ],
        ]);
    }
}
