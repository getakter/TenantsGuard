<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Annotation extends Model
{
    public const TYPES = [
        'accepted' => 'Risk accepted',
        'compensating' => 'Compensating control',
        'note' => 'Note',
    ];

    protected $fillable = ['tenant_id', 'check_id', 'type', 'note', 'review_by', 'user_id'];

    protected $casts = ['review_by' => 'date'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** Accepted risks and compensating controls are excluded from the score. */
    public function excludesFromScore(): bool
    {
        return in_array($this->type, ['accepted', 'compensating'], true);
    }
}
