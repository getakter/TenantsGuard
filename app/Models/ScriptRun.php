<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ScriptRun extends Model
{
    public const STATUS = [
        'queued' => ['Queued', 's-na'],
        'running' => ['Running', 's-info'],
        'completed' => ['Completed', 's-pass'],
        'partial' => ['Partly failed', 's-manual'],
        'failed' => ['Failed', 's-fail'],
        'cancelling' => ['Cancelling', 's-manual'],
        'cancelled' => ['Cancelled', 's-na'],
    ];

    protected $fillable = ['script_version_id', 'user_id', 'status', 'parameters', 'reason', 'ticket', 'ip', 'user_agent', 'mfa_verified', 'started_at', 'finished_at'];

    protected $casts = ['parameters' => 'array', 'mfa_verified' => 'boolean', 'started_at' => 'datetime', 'finished_at' => 'datetime'];

    public function version(): BelongsTo
    {
        return $this->belongsTo(ScriptVersion::class, 'script_version_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function targets(): HasMany
    {
        return $this->hasMany(ScriptRunTarget::class);
    }

    public function isActive(): bool
    {
        return in_array($this->status, ['queued', 'running', 'cancelling'], true);
    }

    /** Runs that touch at least one tenant the user may see. */
    public function scopeVisibleTo(Builder $q, User $user): Builder
    {
        $ids = $user->visibleTenantIds();

        return $ids === null ? $q : $q->whereHas('targets', fn ($t) => $t->whereIn('tenant_id', $ids));
    }

    /** Recompute the overall status from the targets. */
    public function refreshStatus(): void
    {
        $st = $this->targets()->pluck('status')->all();
        $open = array_intersect($st, ['queued', 'running']);
        if ($open) {
            if (! in_array($this->status, ['cancelling'], true)) {
                $this->status = in_array('running', $st, true) || array_diff($st, ['queued']) ? 'running' : 'queued';
            }
            $this->save();

            return;
        }
        $ok = count(array_keys($st, 'succeeded', true));
        $this->status = match (true) {
            $this->status === 'cancelling' || ($ok === 0 && in_array('cancelled', $st, true) && ! array_intersect($st, ['failed', 'timeout'])) => 'cancelled',
            $ok === count($st) => 'completed',
            $ok === 0 => 'failed',
            default => 'partial',
        };
        $this->finished_at ??= now();
        $this->save();
    }
}
