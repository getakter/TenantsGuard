<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/** Role-based onboarding template for one client, e.g. "Dental nurse" or "Sales". */
class LifecycleTemplate extends Model
{
    protected $fillable = ['tenant_id', 'name', 'is_default', 'data', 'created_by'];

    protected $casts = ['data' => 'array', 'is_default' => 'boolean'];

    public const USERNAME_FORMATS = [
        'first.last' => 'firstname.lastname',
        'firstlast' => 'firstnamelastname',
        'f.last' => 'f.lastname',
        'flast' => 'flastname',
        'first.l' => 'firstname.l',
        'first' => 'firstname',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    /** Build a username from first/last name using a CIPP-style format. */
    public static function username(string $first, string $last, string $format = 'first.last'): string
    {
        $clean = fn (string $s) => preg_replace('/[^a-z0-9]/', '', strtolower(Str::ascii($s)));
        [$f, $l] = [$clean($first), $clean($last)];

        return match ($format) {
            'firstlast' => $f.$l,
            'f.last' => substr($f, 0, 1).'.'.$l,
            'flast' => substr($f, 0, 1).$l,
            'first.l' => $f.'.'.substr($l, 0, 1),
            'first' => $f,
            default => trim($f.'.'.$l, '.'),
        };
    }
}
