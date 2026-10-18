<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FrameworkAttestation extends Model
{
    public const STATUSES = [
        'implemented' => 'Implemented',
        'partial' => 'Partially implemented',
        'not_implemented' => 'Not implemented',
        'not_applicable' => 'Not applicable',
    ];

    protected $fillable = ['tenant_id', 'framework', 'requirement', 'status', 'note', 'evidence_url', 'review_by', 'user_id'];

    protected $casts = ['review_by' => 'date'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
