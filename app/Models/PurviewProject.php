<?php

namespace App\Models;

use App\Services\Purview\Catalog;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** A Microsoft Purview implementation programme for one client. */
class PurviewProject extends Model
{
    /** Sign-off gates: key => [label, stage it unlocks]. */
    public const GATES = [
        'design' => ['Design approved', 'foundations'],
        'discover' => ['Discovery reviewed', 'pilot'],
        'pilot' => ['Pilot exit', 'production'],
        'production' => ['Production handover', 'operate'],
    ];

    protected $fillable = ['tenant_id', 'name', 'stage', 'requirements', 'capabilities', 'design', 'design_hash', 'design_approved_at', 'design_approved_by',
        'signoffs', 'verification', 'verified_at', 'created_by'];

    protected $casts = ['requirements' => 'array', 'capabilities' => 'array', 'design' => 'array', 'signoffs' => 'array', 'verification' => 'array',
        'design_approved_at' => 'datetime', 'verified_at' => 'datetime'];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function tasks(): HasMany
    {
        return $this->hasMany(PurviewTask::class)->orderBy('position');
    }

    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'design_approved_by');
    }

    public function isApproved(): bool
    {
        return $this->design_approved_at !== null && $this->design_hash === self::hash($this->design);
    }

    public static function hash(?array $design): ?string
    {
        if (! $design) {
            return null;
        }
        unset($design['generated_at']);

        return hash('sha256', json_encode($design));
    }

    public function signoff(string $gate): ?array
    {
        return collect($this->signoffs ?? [])->firstWhere('gate', $gate);
    }

    /** A phase can be worked on when the gate before it is signed. */
    public function unlocked(string $phase): bool
    {
        return match ($phase) {
            'foundations' => $this->design !== null,
            'discover' => $this->isApproved(),
            'pilot' => $this->isApproved() && (bool) $this->signoff('discover'),
            'production' => $this->isApproved() && (bool) $this->signoff('pilot'),
            'operate' => (bool) $this->signoff('production'),
            default => true,
        };
    }

    public function progress(?string $phase = null): array
    {
        $tasks = $this->tasks->where('stale', false)->when($phase, fn ($c) => $c->where('phase', $phase))->where('required', true);
        $done = $tasks->whereIn('status', ['done', 'skipped'])->count();

        return ['done' => $done, 'total' => $tasks->count(), 'pct' => $tasks->count() ? (int) round($done / $tasks->count() * 100) : 0];
    }

    public function stageIndex(): int
    {
        return array_search($this->stage, array_keys(Catalog::STAGES), true) ?: 0;
    }
}
