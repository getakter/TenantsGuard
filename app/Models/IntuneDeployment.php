<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class IntuneDeployment extends Model
{
    protected $fillable = ['batch', 'tenant_id', 'bundle_id', 'bundle_name', 'user_id', 'dry_run', 'update_existing', 'status', 'summary', 'request', 'started_at', 'finished_at'];

    protected $casts = ['summary' => 'array', 'dry_run' => 'boolean', 'update_existing' => 'boolean', 'request' => 'array', 'started_at' => 'datetime', 'finished_at' => 'datetime'];

    public function isActive(): bool
    {
        return in_array($this->status, ['queued', 'running'], true);
    }

    public function items(): HasMany
    {
        return $this->hasMany(IntuneDeploymentItem::class, 'deployment_id');
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function scopeVisibleTo(Builder $q, User $user): Builder
    {
        $ids = $user->visibleTenantIds();

        return $ids === null ? $q : $q->whereIn('tenant_id', $ids);
    }
}
