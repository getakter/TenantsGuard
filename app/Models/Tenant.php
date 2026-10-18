<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Tenant extends Model
{
    protected $fillable = ['name', 'domain', 'tags', 'contact_name', 'contact_email', 'notes', 'ms_tenant_id', 'mode', 'compliance_profile_id', 'consent_status', 'consent_error', 'demo_state', 'last_score', 'last_scanned_at', 'access_profile', 'access_expiry', 'granted_roles', 'granted_checked_at'];

    protected $hidden = ['demo_state'];

    protected $casts = [
        'demo_state' => 'array',
        'access_profile' => 'array',
        'access_expiry' => 'array',
        'granted_roles' => 'array',
        'granted_checked_at' => 'datetime',
        'tags' => 'array',
        'last_scanned_at' => 'datetime',
    ];

    public function scans(): HasMany
    {
        return $this->hasMany(Scan::class)->orderByDesc('id');
    }

    public function latestScan(): HasOne
    {
        return $this->hasOne(Scan::class)->latestOfMany();
    }

    /** Microsoft Secure Score snapshots, newest first. */
    public function secureScores(): HasMany
    {
        return $this->hasMany(SecureScoreSnapshot::class)->orderByDesc('id');
    }

    public function latestSecureScore(): HasOne
    {
        return $this->hasOne(SecureScoreSnapshot::class)->latestOfMany();
    }

    public function complianceProfile(): BelongsTo
    {
        return $this->belongsTo(ComplianceProfile::class);
    }

    /** Staff explicitly assigned to this tenant. */
    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class);
    }

    public function annotations(): HasMany
    {
        return $this->hasMany(Annotation::class);
    }

    public function isDemo(): bool
    {
        return $this->mode === 'demo';
    }

    public function isReady(): bool
    {
        return $this->isDemo() || $this->consent_status === 'granted';
    }
}
