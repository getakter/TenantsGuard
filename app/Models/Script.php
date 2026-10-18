<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/** A PowerShell script in the library. The code lives in immutable versions. */
class Script extends Model
{
    public const CATEGORIES = ['General', 'Identity', 'Exchange', 'Intune', 'Licensing', 'Security', 'Reporting'];

    protected $fillable = ['name', 'description', 'category', 'archived', 'created_by'];

    protected $casts = ['archived' => 'boolean'];

    public function versions(): HasMany
    {
        return $this->hasMany(ScriptVersion::class)->orderByDesc('version');
    }

    /** The version that runs: the newest approved one. */
    public function approved(): HasOne
    {
        // The status filter must be inside ofMany: newer drafts must not hide the approved version.
        return $this->hasOne(ScriptVersion::class)->ofMany(['version' => 'max'], fn ($q) => $q->where('status', 'approved'));
    }

    public function latest(): HasOne
    {
        return $this->hasOne(ScriptVersion::class)->latestOfMany('version');
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
