<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Scan extends Model
{
    protected $fillable = ['tenant_id', 'trigger', 'score', 'counts', 'drifted', 'duration_ms'];

    protected $casts = ['counts' => 'array', 'drifted' => 'array'];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function results(): HasMany
    {
        return $this->hasMany(ScanResult::class);
    }

    /** @return array<string, ScanResult> */
    public function resultsByCheck(): array
    {
        return $this->results->keyBy('check_id')->all();
    }
}
