<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Auth;

class ActivityLog extends Model
{
    protected $fillable = ['user_id', 'tenant_id', 'action', 'check_id', 'outcome', 'message', 'detail'];

    protected $casts = ['detail' => 'array'];

    public static function record(string $action, ?Tenant $tenant, string $outcome, string $message, ?string $checkId = null, array $detail = []): self
    {
        return self::create([
            'user_id' => Auth::id(),
            'tenant_id' => $tenant?->id,
            'action' => $action,
            'check_id' => $checkId,
            'outcome' => $outcome,
            'message' => $message,
            'detail' => $detail ?: null,
        ]);
    }

    /**
     * Users scoped to specific tenants only see entries for those tenants.
     * Console-wide entries (settings, users, roles) are shown to users who see all tenants.
     */
    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        $ids = $user->visibleTenantIds();

        return $ids === null ? $query : $query->whereIn('tenant_id', $ids);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }
}
