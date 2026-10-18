<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LifecycleTask extends Model
{
    public const ACTIONS = [
        'enable_account' => 'Enable account on start date',
        'remove_licenses' => 'Remove licences',
        'delete_user' => 'Delete user account (restorable for 30 days)',
        'offboard' => 'Scheduled offboarding',
    ];

    protected $fillable = ['run_id', 'tenant_id', 'action', 'payload', 'execute_at', 'status', 'message'];

    protected $casts = ['execute_at' => 'datetime', 'payload' => 'array'];

    public function run(): BelongsTo
    {
        return $this->belongsTo(LifecycleRun::class, 'run_id');
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }
}
