<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SecureScoreSnapshot extends Model
{
    public const CATEGORIES = ['Identity', 'Data', 'Device', 'Apps', 'Infrastructure'];

    public const STATUS = [
        'completed' => ['Completed', 's-pass'],
        'partial' => ['Partially done', 's-manual'],
        'to_address' => ['To address', 's-fail'],
        'risk_accepted' => ['Risk accepted', 's-na'],
        'third_party' => ['Third party', 's-na'],
    ];

    protected $fillable = ['tenant_id', 'scan_id', 'ms_created_at', 'current_score', 'max_score', 'percent', 'categories', 'comparatives', 'controls', 'history', 'licensed_users', 'active_users', 'error'];

    protected $casts = [
        'ms_created_at' => 'datetime',
        'current_score' => 'float',
        'max_score' => 'float',
        'percent' => 'float',
        'categories' => 'array',
        'comparatives' => 'array',
        'controls' => 'array',
        'history' => 'array',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function ok(): bool
    {
        return $this->error === null && $this->max_score > 0;
    }

    /** Comparative average as a percentage of this tenant's max score. */
    public function comparativePercent(string $basis): ?float
    {
        $avg = $this->comparatives[$basis] ?? null;

        return $avg !== null && $this->max_score > 0 ? round(100 * $avg / $this->max_score, 1) : null;
    }

    /** Improvement actions still worth points, highest gain first. */
    public function openActions(): array
    {
        return collect($this->controls ?? [])
            ->filter(fn ($c) => in_array($c['status'], ['to_address', 'partial'], true) && ($c['max'] - $c['score']) > 0)
            ->sortByDesc(fn ($c) => $c['max'] - $c['score'])
            ->values()->all();
    }

    /** Points still available per TenantsGuard control (sum over the actions it addresses). */
    public function pointsByControl(): array
    {
        $out = [];
        foreach ($this->openActions() as $a) {
            foreach ($a['tg_controls'] ?? [] as $cid) {
                $out[$cid] = round(($out[$cid] ?? 0) + ($a['max'] - $a['score']), 2);
            }
        }

        return $out;
    }
}
