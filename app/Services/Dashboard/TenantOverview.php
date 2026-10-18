<?php

namespace App\Services\Dashboard;

use App\Models\Tenant;
use App\Services\Checks\CheckContext;
use App\Services\Graph\GraphException;
use App\Services\Lifecycle\Directory;
use App\Services\Scanner;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * Data behind the client Dashboard overview: tenant facts, directory metrics, MFA coverage,
 * authentication methods and licences. Read from Microsoft Graph and cached for 15 minutes.
 * Each block degrades on its own: a missing permission blanks one card, not the page.
 */
class TenantOverview
{
    /** Methods that resist phishing (FIDO2 / passkeys, Windows Hello, certificate). */
    public const PHISH_RESISTANT = ['fido2', 'passKeyDeviceBound', 'passKeyDeviceBoundAuthenticator', 'passKeyDeviceBoundWindowsHello', 'windowsHelloForBusiness', 'x509CertificateMultiFactor'];

    public const METHOD_LABELS = [
        'microsoftAuthenticatorPush' => 'Authenticator', 'microsoftAuthenticatorPasswordless' => 'Authenticator (passwordless)', 'softwareOneTimePasscode' => 'Software OTP',
        'hardwareOneTimePasscode' => 'Hardware OTP', 'mobilePhone' => 'Phone', 'alternateMobilePhone' => 'Phone', 'officePhone' => 'Phone', 'email' => 'Email',
        'fido2' => 'Passkey (FIDO2)', 'passKeyDeviceBound' => 'Passkey', 'passKeyDeviceBoundAuthenticator' => 'Passkey (Authenticator)', 'passKeyDeviceBoundWindowsHello' => 'Passkey (Windows Hello)',
        'windowsHelloForBusiness' => 'Windows Hello', 'x509CertificateMultiFactor' => 'Certificate', 'temporaryAccessPass' => 'Temporary Access Pass',
    ];

    public static function key(Tenant $tenant): string
    {
        return 'tg:overview:'.$tenant->id;
    }

    public static function forget(Tenant $tenant): void
    {
        Cache::forget(self::key($tenant));
    }

    public static function for(Tenant $tenant, bool $refresh = false): array
    {
        if (! $tenant->isReady()) {
            return ['ready' => false];
        }
        if ($refresh || $tenant->isDemo()) {
            self::forget($tenant);
        }

        return Cache::remember(self::key($tenant), now()->addMinutes(15), fn () => self::read($tenant));
    }

    private static function read(Tenant $tenant): array
    {
        $ctx = Scanner::context($tenant);
        $g = $ctx->graph;
        $errors = [];
        $try = function (string $block, callable $fn) use (&$errors) {
            try {
                return $fn();
            } catch (GraphException $e) {
                $errors[$block] = $e->friendly();
            } catch (Throwable $e) {
                $errors[$block] = $e->getMessage();
            }

            return null;
        };

        $org = $try('tenant', fn () => $g->get('/organization')['value'][0] ?? []) ?? [];
        $users = $try('metrics', fn () => $g->getAll('/users?$select=id,userType,accountEnabled&$top=999'));
        $groups = $try('groups', fn () => $g->getAll('/groups?$select=id&$top=999'));
        $apps = $try('apps', fn () => $g->getAll('/applications?$select=id&$top=999'));
        $devices = $try('devices', fn () => $g->getAll('/deviceManagement/managedDevices?$select=id,complianceState'));
        $registration = $try('auth', fn () => $ctx->registration());

        $members = $users === null ? null : array_values(array_filter($users, fn ($u) => strtolower($u['userType'] ?? 'Member') === 'member'));
        $metrics = [
            'users' => $members === null ? null : count($members),
            'guests' => $users === null ? null : count($users) - count($members),
            'groups' => $groups === null ? null : count($groups),
            'apps' => $apps === null ? null : count($apps),
            'devices' => $devices === null ? null : count($devices),
            'compliant' => $devices === null ? null : count(array_filter($devices, fn ($d) => ($d['complianceState'] ?? '') === 'compliant')),
        ];

        return [
            'ready' => true,
            'read_at' => now()->toIso8601String(),
            'tenant' => [
                'name' => $org['displayName'] ?? $tenant->name,
                'id' => $tenant->ms_tenant_id ?? $org['id'] ?? null,
                'domain' => collect($org['verifiedDomains'] ?? [])->firstWhere('isDefault', true)['name'] ?? $tenant->domain,
                'country' => $org['countryLetterCode'] ?? null,
            ],
            'metrics' => $metrics,
            'auth' => $registration === null ? null : self::auth($ctx, $registration, $users),
            'methods' => $registration === null ? null : self::methods($registration, $users),
            'licences' => $try('licences', fn () => (new Directory($tenant))->licences()),
            'errors' => $errors,
        ];
    }

    /** Enabled members, split by MFA registration and by what enforces MFA. */
    private static function auth(CheckContext $ctx, array $registration, ?array $users): array
    {
        $enabled = $users === null ? null : collect($users)->filter(fn ($u) => ($u['accountEnabled'] ?? true))->pluck('id')->flip();
        $rows = collect($registration)->filter(fn ($r) => strtolower($r['userType'] ?? 'member') === 'member')
            ->filter(fn ($r) => $enabled === null || $enabled->has($r['id'] ?? ''));
        $registered = $rows->filter(fn ($r) => $r['isMfaRegistered'] ?? false)->count();
        $total = $rows->count();

        $enforcement = 'none';
        try {
            if ($ctx->securityDefaultsOn()) {
                $enforcement = 'security_defaults';
            } else {
                $policies = collect($ctx->graph->getAll('/identity/conditionalAccess/policies'))->where('state', 'enabled');
                $all = $policies->first(fn ($p) => in_array('All', $p['conditions']['users']['includeUsers'] ?? [], true)
                    && (in_array('mfa', $p['grantControls']['builtInControls'] ?? [], true) || ! empty($p['grantControls']['authenticationStrength'])));
                $enforcement = $all ? 'ca' : 'none';
            }
        } catch (Throwable) {
            $enforcement = 'unknown';
        }

        return ['total' => $total, 'registered' => $registered, 'unregistered' => $total - $registered, 'enforcement' => $enforcement,
            'protected' => in_array($enforcement, ['ca', 'security_defaults'], true) ? $total : 0];
    }

    /** All members by strongest method: single factor, phishable MFA, phishing-resistant MFA. */
    private static function methods(array $registration, ?array $users): array
    {
        $rows = collect($registration)->filter(fn ($r) => strtolower($r['userType'] ?? 'member') === 'member');
        $single = $phishable = $resistant = 0;
        $byMethod = [];
        foreach ($rows as $r) {
            $methods = $r['methodsRegistered'] ?? [];
            foreach ($methods as $m) {
                $label = self::METHOD_LABELS[$m] ?? $m;
                $byMethod[$label] = ($byMethod[$label] ?? 0) + 1;
            }
            if (array_intersect($methods, self::PHISH_RESISTANT)) {
                $resistant++;
            } elseif (($r['isMfaRegistered'] ?? false) || array_diff($methods, ['email'])) {
                $phishable++;
            } else {
                $single++;
            }
        }
        arsort($byMethod);

        return ['total' => $rows->count(), 'single' => $single, 'phishable' => $phishable, 'resistant' => $resistant, 'byMethod' => $byMethod];
    }
}
