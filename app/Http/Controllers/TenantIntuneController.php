<?php

namespace App\Http\Controllers;

use App\Models\IntuneBundle;
use App\Models\IntuneDeployment;
use App\Models\Tenant;
use App\Services\Intune\IntuneCatalog;
use App\Services\Intune\IntuneDeployer;
use App\Services\Intune\IntuneInventory;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class TenantIntuneController extends Controller
{
    public function show(Request $request, Tenant $tenant)
    {
        return view('intune.tenant', [
            'tenant' => $tenant,
            'snap' => IntuneInventory::snapshot($tenant, $request->boolean('refresh')),
            'bundles' => IntuneBundle::orderByDesc('is_system')->orderBy('name')->get(),
            'history' => IntuneDeployment::where('tenant_id', $tenant->id)->with('user')->latest('id')->limit(15)->get(),
            'catalog' => IntuneCatalog::all(),
        ]);
    }

    public function deploy(Request $request, Tenant $tenant, IntuneDeployer $deployer)
    {
        $data = $request->validate([
            'bundle_id' => 'required|exists:intune_bundles,id',
            'mode' => 'required|in:preview,deploy',
            'update_existing' => 'nullable|boolean',
        ]);
        $bundle = IntuneBundle::findOrFail($data['bundle_id']);
        $batch = (string) Str::uuid();
        $deployer->queue($tenant, $bundle->items, $data['mode'] === 'preview', $request->boolean('update_existing'), $bundle, $bundle->name, $batch);
        IntuneDeployer::dispatch();

        return redirect()->route('intune.run', $batch)->with('status', $data['mode'] === 'preview' ? 'Preview started. Nothing is changed.' : "Deployment of \"{$bundle->name}\" to {$tenant->name} started. This page updates as it runs.");
    }
}
