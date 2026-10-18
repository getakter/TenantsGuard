<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Role extends Model
{
    public const GLOBAL_ADMIN = 'global_admin';

    protected $fillable = ['key', 'name', 'description', 'permissions', 'is_system'];

    protected $casts = ['permissions' => 'array', 'is_system' => 'boolean'];

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    public function isGlobalAdmin(): bool
    {
        return $this->key === self::GLOBAL_ADMIN;
    }

    public function allows(string $permission): bool
    {
        $perms = $this->permissions ?? [];

        return in_array('*', $perms, true) || in_array($permission, $perms, true);
    }

    public static function byKey(string $key): ?self
    {
        return self::where('key', $key)->first();
    }
}
