<?php

namespace App\Services;

use App\Models\Tenant;
use App\Services\Graph\GraphClient;
use App\Services\Graph\GraphException;
use App\Support\MicrosoftApp;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Onboarding through Microsoft admin consent.
 *
 *  - "Connect with Microsoft": the admin picks their account in a popup, approves the permissions,
 *    and Microsoft tells us which tenant they consented for. No domain needs to be typed.
 *  - Per-tenant consent: for a tenant already added by domain / tenant ID.
 */
class ConsentService
{
    public static function resolveTenantId(string $domainOrId): string
    {
        $value = strtolower(trim($domainOrId));
        if (Str::isUuid($value)) {
            return $value;
        }
        if (! preg_match('/^[a-z0-9.-]+\.[a-z]{2,}$/', $value)) {
            throw new RuntimeException('Enter a tenant ID (GUID) or a verified domain such as contoso.com.');
        }
        try {
            $res = Http::timeout(15)->get(config('tenantsguard.microsoft.login_base')."/{$value}/v2.0/.well-known/openid-configuration");
        } catch (ConnectionException $e) {
            throw new RuntimeException('Could not reach login.microsoftonline.com: '.$e->getMessage());
        }
        if (! $res->successful() || ! preg_match('#/([0-9a-f-]{36})/#', (string) $res->json('token_endpoint'), $m)) {
            throw new RuntimeException("No Microsoft 365 tenant was found for \"{$value}\".");
        }

        return $m[1];
    }

    /** Admin consent URL. Without a tenant, the admin chooses the account (and thereby the tenant) in Microsoft's picker. */
    public static function consentUrl(?Tenant $tenant, bool $popup = false): string
    {
        $clientId = MicrosoftApp::clientId();
        if (! $clientId || ! MicrosoftApp::configured()) {
            throw new RuntimeException('The Microsoft app is not set up yet. Open Settings and use "Automatic setup" (one Microsoft sign-in) or enter an existing app registration.');
        }
        $authority = $tenant?->ms_tenant_id ?: 'organizations';

        return config('tenantsguard.microsoft.login_base')."/{$authority}/v2.0/adminconsent?".http_build_query([
            'client_id' => $clientId,
            'scope' => 'https://graph.microsoft.com/.default',
            'redirect_uri' => MicrosoftApp::consentRedirect(),
            'state' => Crypt::encryptString(json_encode(['t' => $tenant?->id, 'p' => $popup])),
        ]);
    }

    /** @return array{tenant: ?Tenant, popup: bool} */
    public static function decodeState(string $state): array
    {
        $data = json_decode(Crypt::decryptString($state), true) ?: [];

        return ['tenant' => isset($data['t']) ? Tenant::find($data['t']) : null, 'popup' => (bool) ($data['p'] ?? false)];
    }

    /**
     * Prove the app can read the tenant, then record its name and default domain.
     * Consent can take a few seconds to propagate, so we retry.
     */
    public static function verify(Tenant $tenant, int $attempts = 4, int $waitSeconds = 3): void
    {
        $last = null;
        for ($i = 1; $i <= $attempts; $i++) {
            try {
                GraphClient::token($tenant->ms_tenant_id, true);
                $org = (new GraphClient($tenant->ms_tenant_id))->get('/organization')['value'][0] ?? null;
                $default = collect($org['verifiedDomains'] ?? [])->firstWhere('isDefault', true)['name'] ?? null;
                $tenant->update([
                    'consent_status' => 'granted',
                    'consent_error' => null,
                    'name' => $org['displayName'] ?? $tenant->name,
                    'domain' => $tenant->domain ?: $default,
                ]);

                return;
            } catch (GraphException $e) {
                $last = $e;
                if ($i < $attempts) {
                    sleep($waitSeconds);
                }
            }
        }
        $tenant->update(['consent_status' => 'failed', 'consent_error' => $last->friendly()]);

        throw $last;
    }
}
