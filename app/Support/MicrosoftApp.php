<?php

namespace App\Support;

use App\Models\Setting;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Crypt;

/**
 * The multi-tenant Microsoft Entra app TenantsGuard uses for console sign-in,
 * admin consent and app-only Graph access.
 *
 * Credentials saved through the setup wizard or the Settings form (database,
 * secret encrypted) take precedence over MS_CLIENT_ID / MS_CLIENT_SECRET in .env.
 */
final class MicrosoftApp
{
    private static function stored(string $key): ?string
    {
        $value = Setting::find($key)?->value;

        return filled($value) ? $value : null;
    }

    public static function clientId(): ?string
    {
        return self::stored('ms_client_id') ?? (config('tenantsguard.microsoft.client_id') ?: null);
    }

    public static function clientSecret(): ?string
    {
        if ($enc = self::stored('ms_client_secret')) {
            try {
                return Crypt::decryptString($enc);
            } catch (DecryptException) {
                return null;
            }
        }

        return config('tenantsguard.microsoft.client_secret') ?: null;
    }

    public static function configured(): bool
    {
        return filled(self::clientId()) && filled(self::clientSecret());
    }

    /** Where the credentials come from: "wizard", "settings", "env" or null. */
    public static function source(): ?string
    {
        if (self::stored('ms_client_id')) {
            return self::stored('ms_setup_method') ?? 'settings';
        }

        return config('tenantsguard.microsoft.client_id') ? 'env' : null;
    }

    public static function save(string $clientId, string $secret, ?string $homeTenant, string $method, ?string $objectId = null): void
    {
        Setting::put('ms_client_id', trim($clientId));
        Setting::put('ms_client_secret', Crypt::encryptString(trim($secret)));
        Setting::put('ms_setup_method', $method);
        Setting::put('ms_app_object_id', $objectId);
        if ($homeTenant) {
            Setting::put('ms_home_tenant', strtolower(trim($homeTenant)));
            if (! self::signinTenants()) {
                Setting::put('ms_signin_tenants', strtolower(trim($homeTenant)));
            }
        }
    }

    public static function forget(): void
    {
        Setting::whereIn('key', ['ms_client_id', 'ms_client_secret', 'ms_setup_method', 'ms_app_object_id'])->delete();
    }

    public static function homeTenant(): ?string
    {
        return self::stored('ms_home_tenant');
    }

    /** Tenants whose users may sign in to the console with Microsoft and get an account automatically. */
    public static function signinTenants(): array
    {
        return array_values(array_filter(array_map('trim', explode(',', strtolower((string) self::stored('ms_signin_tenants'))))));
    }

    public static function consentRedirect(): string
    {
        return config('tenantsguard.microsoft.redirect_uri');
    }

    public static function signinRedirect(): string
    {
        return rtrim((string) config('app.url'), '/').'/auth/microsoft/callback';
    }

    public static function redirectUris(): array
    {
        return [self::consentRedirect(), self::signinRedirect()];
    }
}
