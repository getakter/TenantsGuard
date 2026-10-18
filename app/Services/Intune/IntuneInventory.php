<?php

namespace App\Services\Intune;

use App\Models\IntuneDeploymentItem;
use App\Models\Tenant;
use Illuminate\Support\Facades\Cache;

/**
 * Read-only view of a tenant's Intune estate: prerequisites, policies (TenantsGuard-managed or not), devices.
 * Live Graph reads are cached for 10 minutes per tenant. Deployments clear the cache.
 */
class IntuneInventory
{
    public const TYPES = [
        'compliance' => 'Compliance policies',
        'configuration' => 'Configuration profiles and update rings',
        'mam_ios' => 'iOS app protection',
        'mam_android' => 'Android app protection',
        'feature_update' => 'Feature update profiles',
        'autopilot' => 'Autopilot profiles',
    ];

    public static function cacheKey(Tenant $tenant): string
    {
        return 'intune_snapshot_'.$tenant->id;
    }

    public static function forget(Tenant $tenant): void
    {
        Cache::forget(self::cacheKey($tenant));
    }

    public static function snapshot(Tenant $tenant, bool $refresh = false): array
    {
        if (! $tenant->isReady()) {
            return ['ready' => false];
        }
        if ($refresh || $tenant->isDemo()) {
            self::forget($tenant);
        }
        $live = Cache::remember(self::cacheKey($tenant), now()->addMinutes(10), fn () => self::read($tenant));

        // Deployment history is local and cheap: always fresh.
        $live['latest'] = IntuneDeploymentItem::where('tenant_id', $tenant->id)
            ->whereHas('deployment', fn ($q) => $q->where('dry_run', false))
            ->orderBy('id')->get()->keyBy('template');

        return $live;
    }

    private static function read(Tenant $tenant): array
    {
        $ctx = new IntuneContext($tenant);
        $licenses = $ctx->licenses();

        $prereqs = [];
        foreach (IntuneCatalog::all() as $key => $t) {
            if ($t['kind'] === 'manual') {
                $prereqs[$key] = ['name' => $t['name'], 'platform' => $t['platform'], 'steps' => $t['steps'], 'result' => $ctx->verify($t)];
            }
        }

        $policies = [];
        foreach (self::TYPES as $kind => $label) {
            foreach ($ctx->tryGetAll(IntuneDeployer::COLLECTIONS[$kind]) as $obj) {
                $policies[] = [
                    'type' => $label,
                    'name' => $obj['displayName'] ?? '(unnamed)',
                    'id' => $obj['id'] ?? null,
                    'managed' => str_starts_with($obj['displayName'] ?? '', IntuneCatalog::PREFIX),
                    'modified' => $obj['lastModifiedDateTime'] ?? $obj['createdDateTime'] ?? null,
                ];
            }
        }

        $devices = collect($ctx->tryGetAll('/deviceManagement/managedDevices?$select=id,operatingSystem,complianceState,managedDeviceOwnerType'));

        return [
            'ready' => true,
            'read_at' => now()->toIso8601String(),
            'licenses' => $licenses,
            'denied' => array_values(array_unique($ctx->denied)),
            'prereqs' => $prereqs,
            'policies' => $policies,
            'devices' => [
                'total' => $devices->count(),
                'byOs' => $devices->countBy(fn ($d) => $d['operatingSystem'] ?? 'Unknown')->all(),
                'noncompliant' => $devices->whereIn('complianceState', ['noncompliant', 'error', 'conflict'])->count(),
                'personal' => $devices->where('managedDeviceOwnerType', 'personal')->count(),
            ],
        ];
    }
}
