<?php

namespace App\Http\Controllers;

use App\Models\ActivityLog;
use App\Models\IntuneBundle;
use App\Models\IntuneDeployment;
use App\Services\Intune\IntuneCatalog;
use App\Services\Intune\IntuneContext;
use App\Services\Intune\IntuneDeployer;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class IntuneController extends Controller
{
    public function index(Request $request)
    {
        return view('intune.index', [
            'bundles' => IntuneBundle::withCount('deployments')->orderByDesc('is_system')->orderBy('name')->get(),
            'catalog' => IntuneCatalog::all(),
            'runs' => IntuneDeployment::visibleTo($request->user())->with('tenant', 'user')->latest('id')->limit(40)->get()->groupBy('batch'),
        ]);
    }

    // ------------------------------------------------------------------ bundles

    public function create(Request $request)
    {
        $from = $request->filled('from') ? IntuneBundle::find($request->integer('from')) : null;

        return view('intune.bundle-form', ['bundle' => $from ? $from->replicate()->fill(['name' => $from->name.' (copy)', 'is_system' => false]) : new IntuneBundle(['context' => ['platforms' => ['windows', 'ios', 'android'], 'ownership' => 'mixed', 'plan' => 'business_premium', 'industry' => 'general'], 'items' => []]), 'catalog' => IntuneCatalog::all(), 'isNew' => true]);
    }

    public function store(Request $request)
    {
        $bundle = IntuneBundle::create($this->validated($request) + ['created_by' => $request->user()->id, 'is_system' => false]);
        ActivityLog::record('intune.bundle', null, 'info', "Intune bundle \"{$bundle->name}\" created with ".count($bundle->items).' items');

        return redirect()->route('intune.index')->with('status', "Bundle \"{$bundle->name}\" created.");
    }

    public function edit(IntuneBundle $bundle)
    {
        return view('intune.bundle-form', ['bundle' => $bundle, 'catalog' => IntuneCatalog::all(), 'isNew' => false]);
    }

    public function update(Request $request, IntuneBundle $bundle)
    {
        $bundle->update($this->validated($request, $bundle));
        ActivityLog::record('intune.bundle', null, 'info', "Intune bundle \"{$bundle->name}\" updated (".count($bundle->items).' items)');

        return redirect()->route('intune.index')->with('status', "Bundle \"{$bundle->name}\" saved.");
    }

    public function export(IntuneBundle $bundle)
    {
        $json = ['type' => 'tenantsguard.intune-bundle', 'version' => 1, 'exported_at' => now()->toIso8601String()]
            + $bundle->only(['name', 'description', 'context', 'items']);

        return response()->streamDownload(fn () => print (json_encode($json, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)),
            Str::slug($bundle->name).'.intune-bundle.json', ['Content-Type' => 'application/json']);
    }

    public function import(Request $request)
    {
        $request->validate(['file' => 'required|file|max:1024']);
        $data = json_decode((string) file_get_contents($request->file('file')->getRealPath()), true);
        if (! is_array($data) || ($data['type'] ?? null) !== 'tenantsguard.intune-bundle') {
            return back()->withErrors(['file' => 'This is not a TenantsGuard Intune bundle export.']);
        }
        $items = [];
        foreach ((array) ($data['items'] ?? []) as $it) {
            $t = IntuneCatalog::find($it['template'] ?? '');
            if (! $t) {
                continue;
            }
            $items[] = [
                'template' => $t['key'],
                'assignment' => in_array($it['assignment'] ?? null, $t['targets'] ?? ['none'], true) ? $it['assignment'] : ($t['default_target'] ?? 'none'),
                'group' => isset($it['group']) ? Str::limit((string) $it['group'], 120, '') : null,
                'params' => IntuneCatalog::params($t, (array) ($it['params'] ?? [])),
            ];
        }
        if (! $items) {
            return back()->withErrors(['file' => 'The file contains no templates this version knows.']);
        }
        $name = Str::limit((string) ($data['name'] ?? 'Imported bundle'), 140, '');
        while (IntuneBundle::where('name', $name)->exists()) {
            $name .= ' (imported)';
        }
        $bundle = IntuneBundle::create(['name' => $name, 'description' => $data['description'] ?? null, 'context' => (array) ($data['context'] ?? []), 'items' => $items, 'is_system' => false, 'created_by' => $request->user()->id]);
        ActivityLog::record('intune.bundle', null, 'info', "Intune bundle \"{$bundle->name}\" imported with ".count($items).' items');

        return redirect()->route('intune.bundles.edit', $bundle)->with('status', 'Bundle imported. Review the settings.');
    }

    public function destroy(IntuneBundle $bundle)
    {
        ActivityLog::record('intune.bundle', null, 'info', "Intune bundle \"{$bundle->name}\" deleted");
        $bundle->delete();

        return redirect()->route('intune.index')->with('status', 'Bundle deleted. Policies already deployed to tenants are not removed.');
    }

    private function validated(Request $request, ?IntuneBundle $bundle = null): array
    {
        $request->validate([
            'name' => ['required', 'string', 'max:150', Rule::unique('intune_bundles', 'name')->ignore($bundle?->id)],
            'description' => 'nullable|string|max:2000',
            'plan' => ['required', Rule::in(array_keys(IntuneBundle::PLANS))],
            'ownership' => ['required', Rule::in(array_keys(IntuneBundle::OWNERSHIP))],
            'industry' => ['required', Rule::in(array_keys(IntuneBundle::INDUSTRIES))],
            'platforms' => 'array',
            'platforms.*' => [Rule::in(['windows', 'ios', 'android'])],
            'items' => 'required|array',
        ]);

        $items = [];
        foreach (IntuneCatalog::all() as $key => $t) {
            $in = $request->input("items.{$key}", []);
            if (empty($in['include'])) {
                continue;
            }
            $assignment = in_array($in['assignment'] ?? null, $t['targets'] ?? ['none'], true) ? $in['assignment'] : ($t['default_target'] ?? 'none');
            $items[] = [
                'template' => $key,
                'assignment' => $t['kind'] === 'manual' ? 'none' : $assignment,
                'group' => $assignment === 'group' ? Str::limit(trim((string) ($in['group'] ?? '')), 120, '') : null,
                'params' => IntuneCatalog::params($t, (array) ($in['params'] ?? [])),
            ];
        }
        if (! $items) {
            abort(back()->withErrors(['items' => 'Select at least one template.'])->withInput());
        }
        foreach ($items as $it) {
            if ($it['assignment'] === 'group' && $it['group'] === '') {
                abort(back()->withErrors(['items' => IntuneCatalog::find($it['template'])['name'].': enter the group name for "Named group".'])->withInput());
            }
        }

        return [
            'name' => $request->string('name')->trim()->value(),
            'description' => $request->input('description'),
            'context' => [
                'plan' => $request->input('plan'),
                'ownership' => $request->input('ownership'),
                'industry' => $request->input('industry'),
                'platforms' => array_values($request->input('platforms', [])),
            ],
            'items' => $items,
        ];
    }

    // ------------------------------------------------------------------ deploy

    public function deployForm(Request $request, IntuneBundle $bundle)
    {
        $tenants = $request->user()->visibleTenants()->orderBy('name')->get();
        $fit = [];
        foreach ($tenants as $t) {
            if (! $t->isReady()) {
                $fit[$t->id] = ['ready' => false];

                continue;
            }
            $lic = (new IntuneContext($t))->licenses();
            $missing = [];
            foreach ($bundle->templateKeys() as $k) {
                foreach (IntuneCatalog::missingLicenses(IntuneCatalog::find($k), $lic) as $l) {
                    $missing[$l][] = $k;
                }
            }
            $fit[$t->id] = ['ready' => true, 'licenses' => $lic, 'missing' => $missing];
        }

        return view('intune.deploy', ['bundle' => $bundle, 'tenants' => $tenants, 'fit' => $fit, 'catalog' => IntuneCatalog::all()]);
    }

    public function deploy(Request $request, IntuneBundle $bundle, IntuneDeployer $deployer)
    {
        $data = $request->validate([
            'tenant_ids' => 'required|array|min:1',
            'tenant_ids.*' => 'integer',
            'mode' => 'required|in:preview,deploy',
            'update_existing' => 'nullable|boolean',
        ], ['tenant_ids.required' => 'Select at least one tenant.']);

        $tenants = $request->user()->visibleTenants()->whereIn('id', $data['tenant_ids'])->get();
        abort_if($tenants->isEmpty(), 403);

        $batch = (string) Str::uuid();
        foreach ($tenants as $tenant) {
            $deployer->queue($tenant, $bundle->items, $data['mode'] === 'preview', $request->boolean('update_existing'), $bundle, $bundle->name, $batch);
        }
        IntuneDeployer::dispatch();

        return redirect()->route('intune.run', $batch)->with('status', $data['mode'] === 'preview'
            ? 'Preview started. Nothing is changed. This page updates as each client is checked.'
            : "Deployment of \"{$bundle->name}\" to {$tenants->count()} client(s) started. This page updates as it runs; you can leave it.");
    }

    public function run(Request $request, string $batch)
    {
        $deployments = IntuneDeployment::visibleTo($request->user())->where('batch', $batch)->with('items', 'tenant', 'user')->get();
        abort_if($deployments->isEmpty(), 404);
        $first = $deployments->first();

        return view('intune.run', [
            'deployments' => $deployments,
            'first' => $first,
            'bundle' => $first->bundle_id ? IntuneBundle::find($first->bundle_id) : null,
            'catalog' => IntuneCatalog::all(),
            'active' => $deployments->contains(fn ($d) => $d->isActive()),
            'done' => $deployments->sum(fn ($d) => $d->items->count()),
            'total' => $deployments->sum(fn ($d) => count($d->request['items'] ?? [])),
        ]);
    }
}
