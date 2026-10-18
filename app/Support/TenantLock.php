<?php

namespace App\Support;

use App\Exceptions\TenantBusyException;
use App\Models\Tenant;
use Closure;
use Illuminate\Support\Facades\Cache;

/**
 * Serialises changing operations per tenant (scan, fix, plan apply, Intune deployment) so two
 * technicians or a scheduled scan cannot collide. Re-entrant within one request: a plan apply
 * that triggers a scan reuses the lock it already holds.
 */
final class TenantLock
{
    private static array $held = [];

    public const SECONDS = 600;

    public static function run(Tenant $tenant, Closure $fn): mixed
    {
        if (isset(self::$held[$tenant->id])) {
            return $fn();
        }
        $lock = Cache::lock('tenant-op-'.$tenant->id, self::SECONDS);
        if (! $lock->get()) {
            throw new TenantBusyException($tenant);
        }
        self::$held[$tenant->id] = true;
        try {
            return $fn();
        } finally {
            unset(self::$held[$tenant->id]);
            $lock->release();
        }
    }
}
