<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ControlPlanRun extends Model
{
    public const RESULTS = [
        'fixed' => ['Applied', 's-pass'],
        'deployed' => ['Deployed', 's-pass'],
        'already' => ['Already compliant', 's-na'],
        'would_fix' => ['Will apply', 's-info'],
        'would_deploy' => ['Will deploy', 's-info'],
        'manual' => ['Manual action', 's-manual'],
        'skipped' => ['Skipped', 's-na'],
        'failed' => ['Failed', 's-fail'],
    ];

    protected $fillable = ['tenant_id', 'profile_id', 'plan_name', 'user_id', 'dry_run', 'scope', 'summary', 'results'];

    protected $casts = ['dry_run' => 'boolean', 'summary' => 'array', 'results' => 'array'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }
}
