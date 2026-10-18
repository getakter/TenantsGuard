<?php

namespace App\Http\Controllers;

use App\Models\Tenant;
use App\Services\Checks\Catalog;
use App\Services\FixRunner;
use App\Services\Scanner;
use Throwable;

class FixController extends Controller
{
    public function store(Tenant $tenant, string $check, FixRunner $runner, Scanner $scanner)
    {
        $definition = Catalog::find($check) ?? abort(404);

        // Enforcing a Conditional Access policy (step 2) needs its own permission.
        $ctx = Scanner::context($tenant);
        $current = Scanner::evaluate($definition, $ctx);
        $enforcing = ($current['fix']['step'] ?? null) === 2;
        if ($enforcing && ! request()->user()->can('fixes.enforce')) {
            return redirect(route('tenants.show', $tenant).'#'.$check)
                ->withErrors(['fix' => 'Your role can create report-only policies but cannot enforce Conditional Access. Ask an Administrator to enforce it.']);
        }

        [$ok, $message] = $runner->apply($tenant, $check, false, $ctx);
        if (! $ok) {
            return redirect(route('tenants.show', $tenant).'#'.$check)->withErrors(['fix' => $message]);
        }

        try {
            $scan = $scanner->scan($tenant, 'fix');
            $message .= " Rescanned: score {$scan->score}%.";
        } catch (Throwable $e) {
            $message .= ' Rescan failed: '.$e->getMessage();
        }

        return redirect(route('tenants.show', $tenant).'#'.$check)->with('status', $message);
    }
}
