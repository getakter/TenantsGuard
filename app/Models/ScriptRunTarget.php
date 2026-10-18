<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One tenant in a script run, with its own process, status and output. */
class ScriptRunTarget extends Model
{
    public const STATUS = [
        'queued' => ['Queued', 's-na'],
        'running' => ['Running', 's-info'],
        'succeeded' => ['Succeeded', 's-pass'],
        'failed' => ['Failed', 's-fail'],
        'timeout' => ['Timed out', 's-fail'],
        'cancelled' => ['Cancelled', 's-na'],
    ];

    protected $fillable = ['script_run_id', 'tenant_id', 'status', 'exit_code', 'output', 'results', 'error', 'duration_ms', 'truncated', 'started_at', 'finished_at'];

    protected $casts = ['results' => 'array', 'truncated' => 'boolean', 'started_at' => 'datetime', 'finished_at' => 'datetime'];

    public function run(): BelongsTo
    {
        return $this->belongsTo(ScriptRun::class, 'script_run_id');
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function isDone(): bool
    {
        return ! in_array($this->status, ['queued', 'running'], true);
    }
}
