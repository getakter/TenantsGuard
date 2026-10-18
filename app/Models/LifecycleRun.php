<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class LifecycleRun extends Model
{
    public const STEP_STATUS = [
        'done' => ['Done', 's-pass'],
        'failed' => ['Failed', 's-fail'],
        'skipped' => ['Skipped', 's-na'],
        'pending' => ['To do', 's-manual'],
        'scheduled' => ['Scheduled', 's-info'],
        'cancelled' => ['Cancelled', 's-na'],
    ];

    public const STATUS = [
        'completed' => ['Completed', 's-pass'],
        'in_progress' => ['Open items', 's-manual'],
        'partial' => ['Partly failed', 's-fail'],
        'failed' => ['Failed', 's-fail'],
        'scheduled' => ['Scheduled', 's-info'],
        'cancelled' => ['Cancelled', 's-na'],
    ];

    protected $fillable = ['tenant_id', 'type', 'target_upn', 'target_name', 'target_id', 'ticket', 'status', 'steps', 'snapshot', 'notes', 'user_id'];

    protected $casts = ['steps' => 'array', 'snapshot' => 'array'];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function tasks(): HasMany
    {
        return $this->hasMany(LifecycleTask::class, 'run_id');
    }

    public function scopeVisibleTo(Builder $q, User $user): Builder
    {
        $ids = $user->visibleTenantIds();

        return $ids === null ? $q : $q->whereIn('tenant_id', $ids);
    }

    public function openSteps(): array
    {
        return array_values(array_filter($this->steps ?? [], fn ($s) => in_array($s['status'], ['pending', 'scheduled'], true)));
    }

    /** Recalculate the overall status from the steps. */
    public function refreshStatus(): void
    {
        $st = array_column($this->steps ?? [], 'status');
        $this->status = match (true) {
            $st !== [] && ! array_diff($st, ['cancelled', 'skipped']) => 'cancelled',
            $st === ['scheduled'] && ($this->steps[0]['key'] ?? '') === 'scheduled' => 'scheduled',
            in_array('failed', $st, true) && ! in_array('done', $st, true) => 'failed',
            in_array('failed', $st, true) => 'partial',
            in_array('pending', $st, true) || in_array('scheduled', $st, true) => 'in_progress',
            default => 'completed',
        };
    }

    public function updateStep(string $key, string $status, ?string $message = null, ?string $by = null): void
    {
        $steps = $this->steps;
        foreach ($steps as &$s) {
            if ($s['key'] === $key) {
                $s['status'] = $status;
                if ($message !== null) {
                    $s['message'] = $message;
                }
                $s['at'] = now()->toIso8601String();
                if ($by) {
                    $s['by'] = $by;
                }
            }
        }
        unset($s);
        $this->steps = $steps;
        $this->refreshStatus();
        $this->save();
    }
}
