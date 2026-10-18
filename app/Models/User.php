<?php

namespace App\Models;

use App\Support\Totp;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    protected $fillable = ['name', 'email', 'password', 'role_id', 'all_tenants', 'is_active'];

    protected $hidden = ['password', 'remember_token', 'mfa_secret', 'mfa_recovery_codes'];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'last_login_at' => 'datetime',
            'password' => 'hashed',
            'all_tenants' => 'boolean',
            'is_active' => 'boolean',
            'mfa_secret' => 'encrypted',
            'mfa_enabled_at' => 'datetime',
            'mfa_recovery_codes' => 'array',
        ];
    }

    // ------------------------------------------------------------------ MFA

    public function hasMfa(): bool
    {
        return $this->mfa_enabled_at !== null && filled($this->mfa_secret);
    }

    /** Verify an authenticator code, consuming its time step so it cannot be replayed. */
    public function verifyTotp(string $code): bool
    {
        if (! $this->hasMfa()) {
            return false;
        }
        $step = Totp::verify($this->mfa_secret, $code, $this->mfa_last_step);
        if ($step === null) {
            return false;
        }
        $this->forceFill(['mfa_last_step' => $step])->save();

        return true;
    }

    /** @return string[] plain codes, shown once */
    public function regenerateRecoveryCodes(): array
    {
        $codes = [];
        for ($i = 0; $i < 10; $i++) {
            $codes[] = strtolower(Str::random(5).'-'.Str::random(5));
        }
        $this->forceFill(['mfa_recovery_codes' => array_map(fn ($c) => Hash::make($c), $codes)])->save();

        return $codes;
    }

    public function useRecoveryCode(string $code): bool
    {
        $code = strtolower(trim($code));
        $hashes = $this->mfa_recovery_codes ?? [];
        foreach ($hashes as $i => $hash) {
            if (Hash::check($code, $hash)) {
                unset($hashes[$i]);
                $this->forceFill(['mfa_recovery_codes' => array_values($hashes)])->save();

                return true;
            }
        }

        return false;
    }

    public function remainingRecoveryCodes(): int
    {
        return count($this->mfa_recovery_codes ?? []);
    }

    public function enableMfa(string $secret, int $step, ?string $deviceName = null): array
    {
        $this->forceFill([
            'mfa_secret' => $secret,
            'mfa_enabled_at' => now(),
            'mfa_last_step' => $step,
            'mfa_device_name' => trim((string) $deviceName) !== '' ? Str::limit(trim($deviceName), 80, '') : 'Authenticator app',
        ])->save();

        return $this->regenerateRecoveryCodes();
    }

    /** Remove the registered MFA device. The user must register a new one at their next sign-in. */
    public function resetMfa(): void
    {
        $this->forceFill(['mfa_secret' => null, 'mfa_enabled_at' => null, 'mfa_device_name' => null, 'mfa_recovery_codes' => null, 'mfa_last_step' => null])->save();
    }

    /** Sign the user out everywhere: delete stored sessions and invalidate "remember me" cookies. */
    public function endAllSessions(?string $exceptSessionId = null): int
    {
        $this->forceFill(['remember_token' => Str::random(60)])->save();
        if (config('session.driver') !== 'database') {
            return 0;
        }

        return DB::table(config('session.table', 'sessions'))
            ->where('user_id', $this->id)
            ->when($exceptSessionId, fn ($q) => $q->where('id', '!=', $exceptSessionId))
            ->delete();
    }

    public function role(): BelongsTo
    {
        return $this->belongsTo(Role::class);
    }

    /** Tenants explicitly assigned to this user (used when all_tenants is false). */
    public function tenants(): BelongsToMany
    {
        return $this->belongsToMany(Tenant::class);
    }

    public function isGlobalAdmin(): bool
    {
        return (bool) $this->role?->isGlobalAdmin();
    }

    public function hasPermission(string $permission): bool
    {
        return $this->is_active && (bool) $this->role?->allows($permission);
    }

    public function seesAllTenants(): bool
    {
        return $this->isGlobalAdmin() || $this->all_tenants;
    }

    public function canAccessTenant(Tenant $tenant): bool
    {
        return $this->seesAllTenants() || $this->tenants()->whereKey($tenant->id)->exists();
    }

    /** Restrict a Tenant query to what this user may see. */
    public function visibleTenants(): Builder
    {
        return $this->seesAllTenants()
            ? Tenant::query()
            : Tenant::query()->whereIn('id', $this->tenants()->select('tenants.id'));
    }

    /** IDs of tenants this user may see, or null for "all". */
    public function visibleTenantIds(): ?array
    {
        return $this->seesAllTenants() ? null : $this->tenants()->pluck('tenants.id')->all();
    }
}
