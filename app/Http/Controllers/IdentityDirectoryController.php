<?php

namespace App\Http\Controllers;

use App\Models\ActivityLog;
use App\Models\Tenant;
use App\Services\Graph\GraphException;
use App\Services\Lifecycle\IdentityDirectory;
use App\Services\Scanner;
use App\Support\TenantLock;
use Illuminate\Http\Request;

/** Identity management directory: users, guests and groups of one client. */
class IdentityDirectoryController extends Controller
{
    private function load(Tenant $tenant, callable $fn)
    {
        abort_unless($tenant->isReady(), 409, 'Connect the tenant first.');
        try {
            return $fn(new IdentityDirectory($tenant));
        } catch (GraphException $e) {
            return redirect()->route('operations.tenant', $tenant)->withErrors(['graph' => $e->friendly()]);
        }
    }

    public function users(Tenant $tenant)
    {
        return $this->load($tenant, fn ($d) => view('identity.users', ['tenant' => $tenant, 'data' => $d->people()]));
    }

    public function guests(Tenant $tenant)
    {
        return $this->load($tenant, fn ($d) => view('identity.guests', ['tenant' => $tenant, 'data' => $d->people()]));
    }

    public function groups(Tenant $tenant)
    {
        return $this->load($tenant, fn ($d) => view('identity.groups', ['tenant' => $tenant, 'data' => $d->groups()]));
    }

    public function group(Tenant $tenant, string $group)
    {
        abort_unless(preg_match('/^[0-9a-f-]{36}$/i', $group), 404);

        return $this->load($tenant, fn ($d) => view('identity.group', ['tenant' => $tenant, 'group' => $d->group($group)]));
    }

    public function refresh(Tenant $tenant)
    {
        (new IdentityDirectory($tenant))->forget();

        return back()->with('status', 'Directory data refreshed from Microsoft 365.');
    }

    /** Remove guest accounts (soft delete: restorable from Deleted users for 30 days). */
    public function removeGuests(Request $request, Tenant $tenant)
    {
        $in = $request->validate(['guests' => 'required|array|min:1|max:200', 'guests.*' => 'string|max:64', 'confirm' => 'required|string']);
        $expected = 'DELETE '.count($in['guests']);
        if (strcasecmp(trim($in['confirm']), $expected) !== 0) {
            return back()->withErrors(['guests' => "Type {$expected} to confirm."]);
        }
        $dir = new IdentityDirectory($tenant);
        $guests = collect($dir->people()['guests'])->keyBy('id');
        $ids = array_values(array_filter($in['guests'], fn ($id) => $guests->has($id))); // never delete members through this path
        $removed = TenantLock::run($tenant, function () use ($tenant, $ids, $guests) {
            $g = Scanner::context($tenant)->graph;
            $done = [];
            foreach ($ids as $id) {
                $g->delete('/users/'.$id);
                $done[] = $guests[$id]['email'] ?? $guests[$id]['name'];
            }

            return $done;
        });
        $dir->forget();
        ActivityLog::record('identity.guests', $tenant, 'success', "{$request->user()->email} removed ".count($removed).' guest account(s): '.implode(', ', $removed).' (restorable for 30 days)');

        return back()->with('status', count($removed).' guest account(s) removed. They can be restored from Deleted users in Entra ID for 30 days.');
    }
}
