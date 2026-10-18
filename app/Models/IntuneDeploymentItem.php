<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class IntuneDeploymentItem extends Model
{
    public const STATUS_LABELS = [
        'created' => ['Created', 's-pass'],
        'updated' => ['Updated', 's-pass'],
        'exists' => ['Already deployed', 's-na'],
        'ready' => ['Prerequisite met', 's-pass'],
        'manual' => ['Action needed', 's-manual'],
        'skipped' => ['Skipped (licence)', 's-na'],
        'failed' => ['Failed', 's-fail'],
        'would_create' => ['Will create', 's-info'],
        'would_update' => ['Will update', 's-info'],
    ];

    protected $fillable = ['deployment_id', 'tenant_id', 'template', 'display_name', 'status', 'object_id', 'assignment', 'message'];

    public function deployment(): BelongsTo
    {
        return $this->belongsTo(IntuneDeployment::class, 'deployment_id');
    }
}
