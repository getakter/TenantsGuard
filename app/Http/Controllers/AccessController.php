<?php

namespace App\Http\Controllers;

use App\Models\ActivityLog;
use App\Models\Setting;
use App\Models\Tenant;
use App\Services\Access\GrantScript;
use App\Services\Access\TenantAccess;
use App\Services\ConsentService;
use App\Services\Graph\GraphException;
use App\Support\Access;
use App\Support\MicrosoftApp;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/** Permission scope: the app plan, each client's access profile, and the grant actually in place. */
class AccessController extends Controller
{
    /** Portfolio matrix: clients x capabilities, plus app registration hygiene. */
    public function index(Request $request)
    {
        $tenants = $request->user()->visibleTenants()->orderBy('name')->get();
        $rows = $tenants->map(fn (Tenant $t) => ['tenant' => $t, 'status' => TenantAccess::status($t, false, true)]);
        $secret = Setting::find('ms_secret_expires')?->value;

        return view('access.index', [
            'rows' => $rows,
            'plan' => Access::appPlan(),
            'secretExpires' => $secret ? Carbon::parse($secret) : null,
            'tier0' => $rows->filter(fn ($r) => collect($r['status'])->contains(fn ($s) => $s['tier'] === 'privileged' && $s['enabled']))->count(),
            'extra' => $rows->filter(fn ($r) => collect($r['status'])->contains('extra', true))->count(),
            'unchecked' => $rows->filter(fn ($r) => collect($r['status'])->contains('grant', 'unknown'))->count(),
            'configured' => MicrosoftApp::configured(),
        ]);
    }

    /** Global Administrator: which capabilities the app registration requests at all. */
    public function plan(Request $request)
    {
        abort_unless($request->user()->isGlobalAdmin(), 403, 'Only a Global Administrator can change the app permission plan.');
        $data = $request->validate(['caps' => 'array', 'caps.*' => 'in:'.implode(',', array_keys(Access::CAPABILITIES))]);
        $before = Access::appPlan();
        $caps = array_values(array_unique(array_merge(['monitor'], $data['caps'] ?? [])));
        Setting::put('app_capabilities', json_encode($caps));
        $added = array_diff($caps, $before);
        $removed = array_diff($before, $caps);
        ActivityLog::record('access.plan', null, 'success', 'App permission plan changed'.$this->diffText($added, $removed), null, ['added' => array_values($added), 'removed' => array_values($removed)]);

        return back()->with('status', $added
            ? 'App plan saved. Update the app permissions under Settings, then grant consent again (or run the grant script) in each client that needs the new capabilities.'
            : 'App plan saved. Clients keep any permissions already granted until you revoke them with the grant script.');
    }

    public function show(Tenant $tenant)
    {
        return view('access.tenant', [
            'tenant' => $tenant,
            'status' => TenantAccess::status($tenant),
            'enabled' => TenantAccess::enabledCapabilities($tenant),
            'plan' => Access::appPlan(),
            'preset' => $this->presetOf($tenant),
            'checkedAt' => $tenant->isDemo() ? null : $tenant->granted_checked_at,
            'configured' => MicrosoftApp::configured(),
        ]);
    }

    public function update(Request $request, Tenant $tenant)
    {
        $data = $request->validate([
            'preset' => 'nullable|in:'.implode(',', array_keys(Access::PRESETS)).',custom',
            'caps' => 'array',
            'caps.*' => 'in:'.implode(',', array_keys(Access::CAPABILITIES)),
            'expiry' => 'array',
            'expiry.*' => 'nullable|in:keep,1,7,30,90',
            'reason' => 'nullable|string|max:500',
        ]);
        $plan = Access::appPlan();
        $before = TenantAccess::enabledCapabilities($tenant);
        $preset = $data['preset'] ?? 'custom';
        $caps = $preset !== 'custom' && $preset !== 'full'
            ? Access::PRESETS[$preset][2]
            : ($preset === 'full' ? $plan : ($data['caps'] ?? []));
        $caps = array_values(array_intersect(array_unique(array_merge(['monitor'], $caps)), $plan));

        // Tier 0: only a Global Administrator, with a written justification.
        $newPrivileged = array_filter(array_diff($caps, $before), fn ($c) => Access::tier($c) === 'privileged');
        if ($newPrivileged) {
            if (! $request->user()->isGlobalAdmin()) {
                return back()->withErrors(['caps' => 'Only a Global Administrator can enable privileged (Tier 0) capabilities: '.implode(', ', array_map([Access::class, 'name'], $newPrivileged)).'.'])->withInput();
            }
            if (mb_strlen(trim((string) ($data['reason'] ?? ''))) < 10) {
                return back()->withErrors(['reason' => 'Give a short justification (at least 10 characters) for enabling privileged capabilities.'])->withInput();
            }
        }

        $expiry = [];
        foreach ($caps as $c) {
            $days = $data['expiry'][$c] ?? null;
            if (! $days || Access::tier($c) !== 'privileged') {
                continue;
            }
            if ($days === 'keep') {
                if (in_array($c, $before, true) && ! empty($tenant->access_expiry[$c])) {
                    $expiry[$c] = $tenant->access_expiry[$c];
                }

                continue;
            }
            $expiry[$c] = now()->addDays((int) $days)->toIso8601String();
        }

        $tenant->forceFill([
            // Full = follow the app plan as it changes.
            'access_profile' => $preset === 'full' ? null : $caps,
            'access_expiry' => $expiry ?: null,
        ])->save();

        $after = TenantAccess::enabledCapabilities($tenant);
        $added = array_values(array_diff($after, $before));
        $removed = array_values(array_diff($before, $after));
        ActivityLog::record('access.profile', $tenant, 'success', "Access profile for {$tenant->name} set to ".$this->presetName($preset).$this->diffText($added, $removed), null,
            ['preset' => $preset, 'added' => $added, 'removed' => $removed, 'expiry' => $expiry, 'reason' => $data['reason'] ?? null]);

        $missing = collect(TenantAccess::status($tenant, false, true))->filter(fn ($s) => $s['enabled'] && in_array($s['grant'], ['missing', 'partial'], true))->keys();

        return redirect()->route('access.tenant', $tenant)->with('status', 'Access profile saved.'
            .($removed ? ' TenantsGuard stops using the removed capabilities immediately; run the grant script to revoke them in the client as well.' : '')
            .($missing->isNotEmpty() ? ' Some enabled capabilities are not granted in the client yet: run the grant script or grant consent.' : ''));
    }

    /** Re-read the grant from a fresh token (roles claim). Read-only. */
    public function check(Tenant $tenant)
    {
        try {
            $roles = TenantAccess::granted($tenant, true);
            ActivityLog::record('access.check', $tenant, 'info', 'Checked granted permissions: '.count($roles).' application permissions');

            return back()->with('status', 'Grant checked: '.count($roles).' application permissions are granted in '.$tenant->name.'.');
        } catch (GraphException $e) {
            return back()->withErrors(['check' => $e->friendly()]);
        }
    }

    /** Least-privilege grant script for the selected (or saved) capabilities. */
    public function script(Request $request, Tenant $tenant)
    {
        $caps = $request->has('caps')
            ? array_values(array_intersect((array) $request->input('caps'), Access::appPlan()))
            : TenantAccess::enabledCapabilities($tenant);
        $script = GrantScript::generate($tenant, $caps, $request->user()->name);
        ActivityLog::record('access.script', $tenant, 'info', 'Downloaded the least-privilege grant script', null, ['capabilities' => $caps]);

        return response($script, 200, [
            'Content-Type' => 'text/plain; charset=utf-8',
            'Content-Disposition' => 'attachment; filename="Grant-TenantsGuard-'.Str::slug($tenant->name).'.ps1"',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    /** Demo clients: behave as if the client admin ran the grant script. */
    public function simulate(Request $request, Tenant $tenant)
    {
        abort_unless($tenant->isDemo(), 404);
        $mode = $request->input('mode') === 'all' ? 'all' : 'profile';
        $roles = Access::allRoles($mode === 'all' ? array_keys(Access::CAPABILITIES) : TenantAccess::enabledCapabilities($tenant));
        $tenant->forceFill(['demo_state' => array_merge($tenant->demo_state ?? [], ['grantedRoles' => $roles])])->save();
        ActivityLog::record('access.simulate', $tenant, 'info', 'Demo: simulated '.($mode === 'all' ? 'full consent' : 'the least-privilege grant script'));

        return back()->with('status', $mode === 'all' ? 'Demo: every permission is now granted (as after full admin consent).' : 'Demo: the grant now matches the access profile (as after running the grant script).');
    }

    /** Full admin consent for the whole app plan (quick path). */
    public function consent(Tenant $tenant)
    {
        ActivityLog::record('access.consent', $tenant, 'info', 'Started admin consent for the full app plan');

        return redirect()->away(ConsentService::consentUrl($tenant));
    }

    private function presetOf(Tenant $tenant): string
    {
        if ($tenant->access_profile === null) {
            return 'full';
        }
        $enabled = $tenant->access_profile;
        sort($enabled);
        foreach (Access::PRESETS as $key => [, , $caps]) {
            if ($caps === null) {
                continue;
            }
            sort($caps);
            if ($caps === $enabled) {
                return $key;
            }
        }

        return 'custom';
    }

    private function presetName(string $preset): string
    {
        return $preset === 'custom' ? 'Custom' : Access::PRESETS[$preset][0];
    }

    private function diffText(array $added, array $removed): string
    {
        $n = fn (array $c) => implode(', ', array_map([Access::class, 'name'], $c));

        return ($added ? '; enabled '.$n($added) : '').($removed ? '; disabled '.$n($removed) : '');
    }
}
