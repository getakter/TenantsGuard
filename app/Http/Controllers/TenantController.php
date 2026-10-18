<?php

namespace App\Http\Controllers;

use App\Models\ActivityLog;
use App\Models\Annotation;
use App\Models\Scan;
use App\Models\Tenant;
use App\Services\Checks\Catalog;
use App\Services\ConsentService;
use App\Services\Demo\DemoTenant;
use App\Services\Graph\GraphException;
use App\Services\Scanner;
use App\Support\MicrosoftApp;
use Illuminate\Http\Request;
use Illuminate\Support\MessageBag;
use Illuminate\Support\Str;
use Illuminate\Support\ViewErrorBag;
use RuntimeException;
use Throwable;

class TenantController extends Controller
{
    public function create()
    {
        return view('tenants.create', [
            'configured' => MicrosoftApp::configured(),
        ]);
    }

    public function store(Request $request)
    {
        $data = $request->validate(['identifier' => 'required|string|max:255', 'name' => 'nullable|string|max:255']);

        try {
            $tid = ConsentService::resolveTenantId($data['identifier']);
        } catch (RuntimeException $e) {
            return back()->withErrors(['identifier' => $e->getMessage()])->withInput();
        }

        $tenant = Tenant::firstOrCreate(['ms_tenant_id' => $tid, 'mode' => 'live'], [
            'name' => $data['name'] ?: $data['identifier'],
            'domain' => str_contains($data['identifier'], '.') ? strtolower($data['identifier']) : null,
            'consent_status' => 'pending',
        ]);
        if (! $tenant->wasRecentlyCreated && ! $request->user()->canAccessTenant($tenant)) {
            return back()->withErrors(['identifier' => 'This tenant is already managed in TenantsGuard. Ask a Global Administrator to give you access.'])->withInput();
        }
        if ($tenant->wasRecentlyCreated) {
            // Secure default: no Tier 0 until someone enables it with a justification.
            $tenant->forceFill(['access_profile' => \App\Support\Access::PRESETS['standard'][2]])->save();
        }
        $this->assignToCreator($tenant);
        ActivityLog::record('tenant.added', $tenant, 'info', "Added tenant {$tenant->name} ({$tid})");

        return redirect()->route('tenants.show', $tenant)->with('status', 'Tenant added. Next, send a Global Administrator of the tenant through admin consent.');
    }

    public function storeDemo(Scanner $scanner)
    {
        $n = Tenant::where('mode', 'demo')->count();
        $names = ['Brightwater Dental (Demo)', 'Meridian Logistics (Demo)', 'Apex Wealth Advisors (Demo)', 'Harbor Law Group (Demo)'];
        $tenant = DemoTenant::create($names[$n % count($names)].($n >= count($names) ? ' '.($n + 1) : ''));
        $this->assignToCreator($tenant);
        ActivityLog::record('tenant.added', $tenant, 'info', "Added demo tenant {$tenant->name}");
        $scanner->scan($tenant, 'onboarding');

        return redirect()->route('tenants.show', $tenant)->with('status', 'Demo tenant created and scanned.');
    }

    public function show(Request $request, Tenant $tenant)
    {
        $scans = $tenant->scans()->where('created_at', '>=', now()->subDays(config('tenantsguard.retention_days')))->get(['id', 'score', 'created_at', 'trigger', 'drifted', 'counts']);
        $scan = $request->filled('scan')
            ? Scan::where('tenant_id', $tenant->id)->findOrFail($request->integer('scan'))
            : $scans->first();
        $results = $scan ? $scan->results()->get()->keyBy('check_id') : collect();
        $annotations = Annotation::with('user')->where('tenant_id', $tenant->id)->get()->keyBy('check_id');

        return view('tenants.show', [
            'tenant' => $tenant,
            'scan' => $scan,
            'scans' => $scans,
            'results' => $results,
            'annotations' => $annotations,
            'catalog' => Catalog::all(),
        ]);
    }

    public function edit(Tenant $tenant)
    {
        return view('tenants.edit', ['tenant' => $tenant->load('complianceProfile')]);
    }

    public function update(Request $request, Tenant $tenant)
    {
        $data = $request->validate([
            'name' => 'required|string|max:255',
            'tags' => 'nullable|string|max:500',
            'contact_name' => 'nullable|string|max:120',
            'contact_email' => 'nullable|email|max:190',
            'notes' => 'nullable|string|max:5000',
        ]);
        $data['tags'] = collect(explode(',', (string) ($data['tags'] ?? '')))
            ->map(fn ($t) => Str::limit(trim($t), 40, ''))->filter()->unique(fn ($t) => strtolower($t))->values()->all();
        $tenant->update($data);
        ActivityLog::record('tenant.updated', $tenant, 'info', 'Client details updated');

        return back()->with('status', 'Client details saved.');
    }

    public function destroy(Request $request, Tenant $tenant)
    {
        if (strcasecmp(trim((string) $request->input('confirm_name')), $tenant->name) !== 0) {
            return back()->withErrors(['confirm_name' => 'Type the client name exactly to confirm removal.']);
        }
        ActivityLog::record('tenant.removed', null, 'info', "Removed tenant {$tenant->name}");
        $tenant->delete();

        return redirect()->route('dashboard')->with('status', 'Client removed.');
    }

    public function scan(Tenant $tenant, Scanner $scanner)
    {
        try {
            $scan = $scanner->scan($tenant);
        } catch (Throwable $e) {
            return back()->withErrors(['scan' => $e instanceof GraphException ? $e->friendly() : $e->getMessage()]);
        }
        ActivityLog::record('scan', $tenant, 'info', "Manual scan completed. Score {$scan->score}%.");

        return redirect()->route('tenants.show', $tenant)->with('status', "Scan complete in {$scan->duration_ms} ms. Score {$scan->score}%.");
    }

    public function scanAll(Scanner $scanner)
    {
        $done = 0;
        $failed = [];
        foreach (request()->user()->visibleTenants()->get() as $tenant) {
            if (! $tenant->isReady()) {
                continue;
            }
            try {
                $scanner->scan($tenant);
                $done++;
            } catch (Throwable $e) {
                $failed[] = $tenant->name;
            }
        }

        return back()->with('status', "Scanned {$done} tenants.".($failed ? ' Failed: '.implode(', ', $failed) : ''));
    }

    public function consent(Request $request, Tenant $tenant)
    {
        $popup = $request->boolean('popup');
        try {
            return redirect()->away(ConsentService::consentUrl($tenant, $popup));
        } catch (RuntimeException $e) {
            if ($popup) {
                session()->flash('errors', (new ViewErrorBag)->put('default', new MessageBag(['consent' => $e->getMessage()])));

                return response()->view('auth.popup-done', ['redirect' => route('tenants.show', $tenant)]);
            }

            return back()->withErrors(['consent' => $e->getMessage()]);
        }
    }

    public function verify(Tenant $tenant, Scanner $scanner)
    {
        try {
            ConsentService::verify($tenant);
            $scan = $scanner->scan($tenant, 'onboarding');
        } catch (GraphException $e) {
            return back()->withErrors(['consent' => $e->friendly()]);
        }

        return back()->with('status', "Connection verified. First scan scored {$scan->score}%.");
    }

    public function drift(Tenant $tenant)
    {
        abort_unless($tenant->isDemo(), 404);
        $changes = DemoTenant::simulateDrift($tenant);
        if (! $changes) {
            return back()->with('status', 'Nothing to drift: apply some fixes first, then simulate drift.');
        }
        ActivityLog::record('demo.drift', $tenant, 'info', 'Simulated out-of-band change: '.implode('; ', $changes));

        return back()->with('status', 'Simulated drift: '.implode('; ', $changes).'. Run a scan to detect it.');
    }
}
