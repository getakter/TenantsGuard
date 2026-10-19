<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SecurityAlert extends Model
{
    public const SEVERITIES = ['critical' => ['Critical', 's-fail', 0], 'high' => ['High', 's-fail', 1], 'medium' => ['Medium', 's-manual', 2], 'low' => ['Low', 's-info', 3]];

    public const STATUSES = ['new' => ['New', 's-fail'], 'acknowledged' => ['Investigating', 's-manual'], 'resolved' => ['Resolved', 's-pass']];

    public const RESOLUTIONS = [
        'true_positive' => 'Confirmed and remediated',
        'benign' => 'Expected or approved change',
        'false_positive' => 'False positive',
        'cleared_at_source' => 'Cleared in Microsoft 365',
    ];

    protected $fillable = ['tenant_id', 'rule', 'severity', 'title', 'detail', 'evidence', 'dedupe_key', 'status', 'occurred_at', 'last_seen_at',
        'occurrences', 'assigned_to', 'resolved_by', 'resolved_at', 'resolution', 'note'];

    protected $casts = ['evidence' => 'array', 'occurred_at' => 'datetime', 'last_seen_at' => 'datetime', 'resolved_at' => 'datetime'];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    public function resolver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resolved_by');
    }

    public function scopeOpen(Builder $q): Builder
    {
        return $q->where('status', '!=', 'resolved');
    }

    public function scopeVisibleTo(Builder $q, User $user): Builder
    {
        $ids = $user->visibleTenantIds();

        return $ids === null ? $q : $q->whereIn('tenant_id', $ids);
    }

    /** Most severe first, then newest. */
    public function scopeTriage(Builder $q): Builder
    {
        return $q->orderByRaw("CASE severity WHEN 'critical' THEN 0 WHEN 'high' THEN 1 WHEN 'medium' THEN 2 ELSE 3 END")
            ->orderByDesc('occurred_at')->orderByDesc('id');
    }
}
