<?php

namespace App\Http\Controllers;

use App\Models\Tenant;

abstract class Controller
{
    /** A user limited to specific tenants keeps access to tenants they add themselves. */
    protected function assignToCreator(Tenant $tenant): void
    {
        $user = auth()->user();
        if ($user && ! $user->seesAllTenants()) {
            $user->tenants()->syncWithoutDetaching([$tenant->id]);
        }
    }
}
