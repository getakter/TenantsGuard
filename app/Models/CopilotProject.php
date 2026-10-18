<?php

namespace App\Models;

use App\Services\Copilot\Catalog;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** A Microsoft 365 Copilot readiness and deployment programme for one client. */
class CopilotProject extends Model
{
    /** gate => [label, stage it unlocks] */
    public const GATES = [
        'readiness' => ['Ready for pilot', 'pilot'],
        'pilot' => ['Pilot exit: go for rollout', 'rollout'],
        'rollout' => ['Rollout complete', 'adopt'],
    ];

    protected $fillable = ['tenant_id', 'name', 'stage', 'requirements', 'assessment', 'assessed_at', 'usage', 'usage_at', 'signoffs', 'created_by'];

    protected $casts = ['requirements' => 'array', 'assessment' => 'array', 'usage' => 'array', 'signoffs' => 'array', 'assessed_at' => 'datetime', 'usage_at' => 'datetime'];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function tasks(): HasMany
    {
        return $this->hasMany(CopilotTask::class)->orderBy('position');
    }

    public function signoff(string $gate): ?array
    {
        return collect($this->signoffs ?? [])->firstWhere('gate', $gate);
    }

    public function unlocked(string $phase): bool
    {
        return match ($phase) {
            'prepare' => $this->assessment !== null,
            'pilot' => (bool) $this->signoff('readiness'),
            'rollout' => (bool) $this->signoff('pilot'),
            'adopt' => (bool) $this->signoff('rollout'),
            default => true,
        };
    }

    public function progress(?string $phase = null): array
    {
        $tasks = $this->tasks->where('stale', false)->where('required', true)->when($phase, fn ($c) => $c->where('phase', $phase));
        $done = $tasks->whereIn('status', ['done', 'skipped'])->count();

        return ['done' => $done, 'total' => $tasks->count(), 'pct' => $tasks->count() ? (int) round($done / $tasks->count() * 100) : 0];
    }

    public function stageIndex(): int
    {
        return array_search($this->stage, array_keys(Catalog::STAGES), true) ?: 0;
    }

    /** Pilot member ids from the requirements. */
    public function pilotUsers(): array
    {
        return array_values($this->requirements['pilot_users'] ?? []);
    }
}
