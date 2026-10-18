<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Setting extends Model
{
    protected $primaryKey = 'key';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = ['key', 'value'];

    public const DEFAULTS = [
        'company_name' => 'TenantsGuard',
        'accent_color' => '#0f6cbd',
        'logo_url' => '',
        'report_footer' => 'Confidential: prepared for the named client only.',
    ];

    public static function branding(): array
    {
        $stored = self::whereIn('key', array_keys(self::DEFAULTS))->pluck('value', 'key')->all();

        return array_merge(self::DEFAULTS, array_filter($stored, fn ($v) => $v !== null && $v !== ''));
    }

    public static function put(string $key, ?string $value): void
    {
        self::updateOrCreate(['key' => $key], ['value' => $value]);
    }
}
