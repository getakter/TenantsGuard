<?php

namespace App\Providers;

use App\Models\Tenant;
use App\Models\User;
use App\Services\Mfa;
use App\Support\Permissions;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        // Inactive users can do nothing. Global Administrators can do everything.
        Gate::before(function (User $user) {
            if (! $user->is_active) {
                return false;
            }

            return $user->isGlobalAdmin() ? true : null;
        });

        foreach (Permissions::all() as $permission) {
            Gate::define($permission, fn (User $user) => $user->hasPermission($permission));
        }

        Gate::define('access-tenant', fn (User $user, Tenant $tenant) => $user->canAccessTenant($tenant));

        // Separate buckets: password guessing per account and IP; MFA codes per pending or signed-in user.
        RateLimiter::for('login', fn (Request $r) => Limit::perMinute(10)->by('login|'.strtolower((string) $r->input('email')).'|'.$r->ip()));
        RateLimiter::for('mfa', fn (Request $r) => Limit::perMinute(10)->by('mfa|'.(session(Mfa::PENDING)['id'] ?? $r->user()?->id ?? 'anon').'|'.$r->ip()));
    }
}
