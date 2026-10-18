<?php

namespace App\Services\Microsoft;

use App\Models\Setting;
use App\Support\Access;
use App\Support\MicrosoftApp;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Automatic, one-time creation of the TenantsGuard multi-tenant app registration.
 *
 * The MSP admin signs in with the OAuth 2.0 device code flow using Microsoft's public
 * "Microsoft Graph Command Line Tools" client (the same one Connect-MgGraph uses), consents to
 * Application.ReadWrite.All, and we create the app, its secret and service principal through Graph.
 */
class AppProvisioner
{
    public const GRAPH_APP_ID = '00000003-0000-0000-c000-000000000000';

    private const SCOPES = 'https://graph.microsoft.com/Application.ReadWrite.All offline_access openid profile';

    private static function setupClientId(): string
    {
        return (string) config('tenantsguard.microsoft.setup_client_id');
    }

    private static function login(): string
    {
        return config('tenantsguard.microsoft.login_base').'/organizations/oauth2/v2.0';
    }

    /** @return array{device_code: string, user_code: string, verification_uri: string, interval: int, expires_in: int, message: string} */
    public static function startDeviceCode(): array
    {
        try {
            $res = Http::asForm()->timeout(20)->post(self::login().'/devicecode', [
                'client_id' => self::setupClientId(),
                'scope' => self::SCOPES,
            ]);
        } catch (ConnectionException $e) {
            throw new RuntimeException('Could not reach login.microsoftonline.com: '.$e->getMessage());
        }
        if (! $res->successful()) {
            throw new RuntimeException('Microsoft refused to start sign-in: '.($res->json('error_description') ?? $res->body()));
        }

        return [
            'device_code' => $res->json('device_code'),
            'user_code' => $res->json('user_code'),
            'verification_uri' => $res->json('verification_uri', 'https://microsoft.com/devicelogin'),
            'interval' => (int) $res->json('interval', 5),
            'expires_in' => (int) $res->json('expires_in', 900),
            'message' => (string) $res->json('message'),
        ];
    }

    /**
     * Poll once. Returns ['status' => 'pending'|'slow_down'|'done', 'token' => ?string].
     *
     * @throws RuntimeException when the code expired or the user declined
     */
    public static function pollDeviceCode(string $deviceCode): array
    {
        $res = Http::asForm()->timeout(20)->post(self::login().'/token', [
            'grant_type' => 'urn:ietf:params:oauth:grant-type:device_code',
            'client_id' => self::setupClientId(),
            'device_code' => $deviceCode,
        ]);
        if ($res->successful()) {
            return ['status' => 'done', 'token' => $res->json('access_token')];
        }

        return match ($res->json('error')) {
            'authorization_pending' => ['status' => 'pending', 'token' => null],
            'slow_down' => ['status' => 'slow_down', 'token' => null],
            'expired_token', 'code_expired' => throw new RuntimeException('The sign-in code expired. Start again.'),
            'authorization_declined', 'access_denied' => throw new RuntimeException('Sign-in was cancelled or consent was declined.'),
            default => throw new RuntimeException('Sign-in failed: '.strtok((string) ($res->json('error_description') ?? $res->body()), "\r\n")),
        };
    }

    /** Decode a JWT payload without verifying it (only used for tokens we just received from Microsoft over TLS). */
    public static function claims(string $jwt): array
    {
        $parts = explode('.', $jwt);
        if (count($parts) < 2) {
            return [];
        }

        return json_decode(base64_decode(strtr($parts[1], '-_', '+/')), true) ?: [];
    }

    /**
     * Create the app registration, a client secret and the service principal, then store the credentials.
     *
     * @return array{client_id: string, tenant: ?string, object_id: string, permissions: int}
     */
    /**
     * Resolve the app plan (Settings > Access) to app role IDs, as requiredResourceAccess entries.
     * Read-only setup keeps only the read-tier capabilities of the plan.
     */
    private static function resourceAccess(\Closure $graph, bool $includeWrite): array
    {
        $sp = $graph()->get("/servicePrincipals(appId='".self::GRAPH_APP_ID."')", ['$select' => 'id,appRoles,oauth2PermissionScopes']);
        if (! $sp->successful()) {
            throw new RuntimeException('Reading Microsoft Graph permissions failed: '.($sp->json('error.message') ?? $sp->body()));
        }
        $roles = collect($sp->json('appRoles'))->pluck('id', 'value');
        $scopes = collect($sp->json('oauth2PermissionScopes'))->pluck('id', 'value');

        $caps = Access::appPlan();
        if (! $includeWrite) {
            $caps = array_values(array_filter($caps, fn ($c) => Access::tier($c) === 'read'));
        }
        $wanted = Access::roles($caps);

        $access = [];
        foreach ($wanted['graph'] as $name) {
            if ($roles->has($name)) {
                $access[] = ['id' => $roles[$name], 'type' => 'Role'];
            }
        }
        foreach (['openid', 'profile', 'email', 'User.Read'] as $name) {
            if ($scopes->has($name)) {
                $access[] = ['id' => $scopes[$name], 'type' => 'Scope'];
            }
        }
        $out = [['resourceAppId' => self::GRAPH_APP_ID, 'resourceAccess' => $access]];

        if ($wanted['exo']) {
            $exo = $graph()->get("/servicePrincipals(appId='".Access::EXO."')", ['$select' => 'id,appRoles']);
            $exoRoles = collect($exo->successful() ? $exo->json('appRoles') : [])->pluck('id', 'value')->all() + Access::EXO_ROLE_IDS;
            $out[] = ['resourceAppId' => Access::EXO, 'resourceAccess' => array_values(array_map(fn ($n) => ['id' => $exoRoles[$n], 'type' => 'Role'], array_filter($wanted['exo'], fn ($n) => isset($exoRoles[$n]))))];
        }

        return $out;
    }

    /** Number of application permissions in a requiredResourceAccess list. */
    private static function countRoles(array $required): int
    {
        return collect($required)->sum(fn ($r) => collect($r['resourceAccess'])->where('type', 'Role')->count());
    }

    /**
     * Re-apply the full permission list to the existing app registration (after new modules add permissions).
     * Customers must re-grant admin consent afterwards.
     */
    public static function updatePermissions(string $accessToken, string $clientId, bool $includeWrite = true): int
    {
        $graph = fn () => Http::withToken($accessToken)->acceptJson()->timeout(30)->baseUrl(config('tenantsguard.microsoft.graph_base').'/v1.0');
        $access = self::resourceAccess($graph, $includeWrite);
        $res = $graph()->patch("/applications(appId='{$clientId}')", [
            'requiredResourceAccess' => $access,
            'web' => ['redirectUris' => MicrosoftApp::redirectUris()],
        ]);
        if (! $res->successful()) {
            throw new RuntimeException('Updating the app registration failed: '.($res->json('error.message') ?? $res->body()).' Sign in with an account that owns the app or is an Application Administrator.');
        }

        return self::countRoles($access);
    }

    public static function provision(string $accessToken, bool $includeWrite = true): array
    {
        $graph = fn () => Http::withToken($accessToken)->acceptJson()->timeout(30)->baseUrl(config('tenantsguard.microsoft.graph_base').'/v1.0');
        $fail = function ($res, string $step) {
            throw new RuntimeException("{$step} failed: ".($res->json('error.message') ?? $res->body()));
        };

        // 1. Resolve permission names to Microsoft Graph app role / scope IDs.
        $access = self::resourceAccess($graph, $includeWrite);

        // 2. Create the multi-tenant application.
        $app = $graph()->post('/applications', [
            'displayName' => config('tenantsguard.microsoft.app_name'),
            'signInAudience' => 'AzureADMultipleOrgs',
            'web' => [
                'redirectUris' => MicrosoftApp::redirectUris(),
                'implicitGrantSettings' => ['enableIdTokenIssuance' => false, 'enableAccessTokenIssuance' => false],
            ],
            'requiredResourceAccess' => $access,
        ]);
        if (! $app->successful()) {
            $fail($app, 'Creating the app registration');
        }

        // 3. Client secret (24 months).
        $secret = $graph()->post('/applications/'.$app->json('id').'/addPassword', [
            'passwordCredential' => ['displayName' => 'TenantsGuard', 'endDateTime' => now()->addMonths(24)->toIso8601ZuluString()],
        ]);
        if (! $secret->successful()) {
            $fail($secret, 'Creating the client secret');
        }

        // 4. Service principal in the home tenant (best effort; consent also creates it).
        $graph()->post('/servicePrincipals', ['appId' => $app->json('appId')]);

        $tenant = self::claims($accessToken)['tid'] ?? null;
        MicrosoftApp::save($app->json('appId'), $secret->json('secretText'), $tenant, 'wizard', $app->json('id'));
        Setting::put('ms_secret_expires', (string) ($secret->json('endDateTime') ?: now()->addMonths(24)->toIso8601String()));

        return ['client_id' => $app->json('appId'), 'tenant' => $tenant, 'object_id' => $app->json('id'), 'permissions' => self::countRoles($access)];
    }
}
