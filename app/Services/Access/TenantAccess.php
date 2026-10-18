<?php

namespace App\Services\Access;

use App\Models\Tenant;
use App\Services\Graph\GraphClient;
use App\Services\Graph\GraphException;
use App\Support\Access;
use Carbon\Carbon;

/** What TenantsGuard may do (policy) and can do (grant) in one client. */
final class TenantAccess
{
    /** Capabilities allowed by the client's profile (null profile = whole app plan), minus expired ones. */
    public static function enabledCapabilities(Tenant $tenant): array
    {
        $plan = Access::appPlan();
        $profile = is_array($tenant->access_profile) ? array_values(array_intersect($tenant->access_profile, $plan)) : $plan;
        $expiry = $tenant->access_expiry ?? [];

        return array_values(array_unique(array_merge(['monitor'], array_filter($profile, fn ($c) => empty($expiry[$c]) || Carbon::parse($expiry[$c])->isFuture()))));
    }

    public static function enabled(Tenant $tenant, string $cap): bool
    {
        return in_array($cap, self::enabledCapabilities($tenant), true);
    }

    /**
     * Roles the client actually granted to the app. Real tenants: the roles claim of a fresh app-only
     * token (no extra permission needed). Demo tenants: simulated grant state.
     */
    public static function granted(Tenant $tenant, bool $refresh = false, bool $cachedOnly = false): array
    {
        if ($tenant->isDemo()) {
            return $tenant->demo_state['grantedRoles'] ?? Access::allRoles(array_keys(Access::CAPABILITIES));
        }
        if (! $refresh && is_array($tenant->granted_roles) && ($cachedOnly || $tenant->granted_checked_at?->gt(now()->subHour()))) {
            return $tenant->granted_roles;
        }
        if ($cachedOnly || ! $tenant->isReady()) {
            throw new GraphException('The grant has not been checked yet.', 0, 'NotChecked');
        }
        if (! $tenant->ms_tenant_id) {
            return [];
        }
        $roles = GraphClient::grantedRoles($tenant->ms_tenant_id, $refresh);
        $exo = [];
        if ($refresh || ! is_array($tenant->granted_roles)) {
            try {
                $exo = GraphClient::grantedRoles($tenant->ms_tenant_id, $refresh, 'https://outlook.office365.com/.default');
            } catch (GraphException) {
                $exo = [];
            }
        } else {
            $exo = array_values(array_intersect($tenant->granted_roles, Access::roles(['exchange'])['exo']));
        }
        $all = array_values(array_unique(array_merge($roles, $exo)));
        $tenant->forceFill(['granted_roles' => $all, 'granted_checked_at' => now()])->saveQuietly();

        return $all;
    }

    /**
     * Status of every capability: enabled by policy, in the app plan, and how much of it is granted.
     *
     * @return array<string, array{name: string, tier: string, in_plan: bool, enabled: bool, expires: ?string, grant: string, missing: string[], extra: bool}>
     */
    public static function status(Tenant $tenant, bool $refresh = false, bool $cachedOnly = false): array
    {
        $plan = Access::appPlan();
        $enabled = self::enabledCapabilities($tenant);
        try {
            $granted = self::granted($tenant, $refresh, $cachedOnly);
            $known = true;
        } catch (GraphException) {
            $granted = [];
            $known = false;
        }
        $out = [];
        foreach (Access::CAPABILITIES as $cap => [$name, $tier]) {
            $need = Access::allRoles([$cap]);
            $missing = array_values(array_diff($need, $granted));
            $out[$cap] = [
                'name' => $name, 'tier' => $tier, 'in_plan' => in_array($cap, $plan, true), 'enabled' => in_array($cap, $enabled, true),
                'expires' => $tenant->access_expiry[$cap] ?? null,
                'grant' => ! $known ? 'unknown' : (! $missing ? 'granted' : (count($missing) < count($need) ? 'partial' : 'missing')),
                'missing' => $missing,
                // Granted in the client but not enabled by policy: least privilege says revoke it.
                'extra' => $known && ! in_array($cap, $enabled, true) && count($missing) < count($need),
            ];
        }

        return $out;
    }

    /** Ready to use: enabled by policy and fully granted. */
    public static function ready(Tenant $tenant, string $cap): bool
    {
        $s = self::status($tenant)[$cap] ?? null;

        return $s && $s['enabled'] && in_array($s['grant'], ['granted', 'unknown'], true);
    }

    /** Policy gate for every Microsoft Graph write. Default deny for unmapped paths. */
    public static function assertWrite(Tenant $tenant, string $method, string $path): void
    {
        $caps = Access::writeCapabilities($method, $path);
        if (! $caps) {
            throw new GraphException("TenantsGuard does not allow {$method} {$path}: this change is not mapped to a capability.", 403, 'AccessPolicy');
        }
        $enabled = self::enabledCapabilities($tenant);
        if (! array_intersect($caps, $enabled)) {
            $names = implode(' or ', array_map([Access::class, 'name'], $caps));
            throw new GraphException("The TenantsGuard access profile for {$tenant->name} does not allow {$names}. A Global Administrator or an administrator with client management rights can enable it under the client's Access and permissions.", 403, 'AccessPolicy');
        }
    }
}
