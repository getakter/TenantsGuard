<?php

namespace App\Models;

use App\Services\Intune\IntuneCatalog;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class IntuneBundle extends Model
{
    public const PLANS = [
        'business_premium' => 'Microsoft 365 Business Premium',
        'e3' => 'Microsoft 365 E3',
        'e5' => 'Microsoft 365 E5',
        'ems' => 'EMS E3 / Intune + Entra ID P1 add-on',
        'intune_only' => 'Intune Plan 1 only (no Entra ID P1)',
    ];

    public const OWNERSHIP = [
        'corporate' => 'Corporate-owned devices',
        'byod' => 'Bring your own device (BYOD)',
        'mixed' => 'Mixed: corporate PCs, BYOD mobile',
    ];

    public const INDUSTRIES = [
        'general' => 'General business',
        'healthcare' => 'Healthcare (HIPAA)',
        'finance' => 'Financial services (PCI DSS, SOX)',
        'legal' => 'Legal and professional services',
        'government' => 'Government / public sector',
        'education' => 'Education',
        'manufacturing' => 'Manufacturing and frontline',
    ];

    protected $fillable = ['name', 'description', 'context', 'items', 'is_system', 'created_by'];

    protected $casts = ['context' => 'array', 'items' => 'array', 'is_system' => 'boolean'];

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function deployments(): HasMany
    {
        return $this->hasMany(IntuneDeployment::class, 'bundle_id');
    }

    public function templateKeys(): array
    {
        return array_values(array_filter(array_column($this->items ?? [], 'template'), fn ($k) => IntuneCatalog::find($k)));
    }

    public function platforms(): array
    {
        return array_values(array_unique(array_map(fn ($k) => IntuneCatalog::find($k)['platform'], $this->templateKeys())));
    }

    public function coverage(): array
    {
        return IntuneCatalog::coverage($this->templateKeys());
    }

    public function requiredLicenses(): array
    {
        $out = [];
        foreach ($this->templateKeys() as $k) {
            foreach (IntuneCatalog::find($k)['license'] ?? [] as $l) {
                $out[$l] = true;
            }
        }

        return array_keys($out);
    }
}
