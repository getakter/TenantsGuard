<?php

namespace App\Services\Checks;

use App\Models\Tenant;
use App\Services\Dns\DnsResolverInterface;
use App\Services\Graph\GraphClientInterface;
use App\Services\Graph\GraphException;
use Closure;
use Throwable;

/**
 * Per-scan data access with memoisation, so each Graph resource is fetched once
 * even when several checks use it.
 */
class CheckContext
{
    private array $memo = [];

    public function __construct(
        public readonly Tenant $tenant,
        public readonly GraphClientInterface $graph,
        public readonly DnsResolverInterface $dns,
    ) {}

    public function load(string $key, Closure $fn): mixed
    {
        if (! array_key_exists($key, $this->memo)) {
            try {
                $this->memo[$key] = ['ok' => $fn()];
            } catch (Throwable $e) {
                $this->memo[$key] = ['err' => $e];
            }
        }
        if (isset($this->memo[$key]['err'])) {
            throw $this->memo[$key]['err'];
        }

        return $this->memo[$key]['ok'];
    }

    public function forget(string $key): void
    {
        unset($this->memo[$key]);
    }

    // ---------- Graph resources ----------

    public function securityDefaults(): array
    {
        return $this->load('secdef', fn () => $this->graph->get('/policies/identitySecurityDefaultsEnforcementPolicy'));
    }

    public function securityDefaultsOn(): bool
    {
        try {
            return (bool) ($this->securityDefaults()['isEnabled'] ?? false);
        } catch (GraphException) {
            return false;
        }
    }

    public function conditionalAccess(): array
    {
        return $this->load('ca', fn () => $this->graph->getAll('/identity/conditionalAccess/policies'));
    }

    public function authorizationPolicy(): array
    {
        return $this->load('authz', function () {
            $res = $this->graph->get('/policies/authorizationPolicy');

            // Some tenants return a collection.
            return isset($res['value'][0]) ? $res['value'][0] : $res;
        });
    }

    public function registration(): array
    {
        return $this->load('reg', fn () => $this->graph->getAll('/reports/authenticationMethods/userRegistrationDetails?$top=999'));
    }

    /** @return array{list: array, signIn: bool} */
    public function users(): array
    {
        return $this->load('users', function () {
            $select = 'id,displayName,userPrincipalName,accountEnabled,userType,onPremisesSyncEnabled,createdDateTime';
            try {
                return ['list' => $this->graph->getAll('/users?$top=999&$select='.$select.',signInActivity'), 'signIn' => true];
            } catch (GraphException $e) {
                if (! in_array($e->status, [400, 403], true)) {
                    throw $e;
                }

                return ['list' => $this->graph->getAll('/users?$top=999&$select='.$select), 'signIn' => false];
            }
        });
    }

    public function usersById(): array
    {
        return collect($this->users()['list'])->keyBy('id')->all();
    }

    public function directoryRoles(): array
    {
        return $this->load('roles', fn () => $this->graph->getAll('/directoryRoles?$expand=members'));
    }

    public function domains(): array
    {
        return $this->load('domains', fn () => $this->graph->getAll('/domains'));
    }

    public function authMethodsPolicy(): array
    {
        return $this->load('amp', fn () => $this->graph->get('/policies/authenticationMethodsPolicy'));
    }

    public function adminConsentPolicy(): array
    {
        return $this->load('acr', fn () => $this->graph->get('/policies/adminConsentRequestPolicy'));
    }

    public function sharepointSettings(): array
    {
        return $this->load('spo', fn () => $this->graph->get('/admin/sharepoint/settings'));
    }

    public function compliancePolicies(): array
    {
        return $this->load('cmp', fn () => $this->graph->getAll('/deviceManagement/deviceCompliancePolicies'));
    }

    public function managedDevices(): array
    {
        return $this->load('devices', fn () => $this->graph->getAll('/deviceManagement/managedDevices?$select=id,deviceName,operatingSystem,complianceState,userPrincipalName'));
    }

    public function applications(): array
    {
        return $this->load('apps', fn () => $this->graph->getAll('/applications?$select=id,appId,displayName,passwordCredentials,keyCredentials&$top=999'));
    }

    public function secureScore(): ?array
    {
        return $this->load('score', fn () => $this->graph->get('/security/secureScores?$top=1')['value'][0] ?? null);
    }

    public function riskyUsers(): array
    {
        return $this->load('risky', fn () => $this->graph->getAll("/identityProtection/riskyUsers?\$filter=riskState eq 'atRisk'"));
    }

    /** @return array{p1: bool, p2: bool, intune: bool, known: bool, skus: string[]} */
    public function licenses(): array
    {
        return $this->load('licenses', function () {
            try {
                $skus = $this->graph->getAll('/subscribedSkus');
            } catch (GraphException) {
                return ['p1' => true, 'p2' => true, 'intune' => true, 'mde' => true, 'known' => false, 'skus' => []];
            }
            $plans = collect($skus)
                ->filter(fn ($s) => ($s['capabilityStatus'] ?? '') === 'Enabled')
                ->pluck('servicePlans')->flatten(1)
                ->filter(fn ($p) => in_array($p['provisioningStatus'] ?? 'Success', ['Success', 'PendingActivation', 'PendingProvisioning'], true))
                ->pluck('servicePlanName');

            return [
                'p1' => $plans->contains(fn ($n) => in_array($n, ['AAD_PREMIUM', 'AAD_PREMIUM_P2'], true)),
                'p2' => $plans->contains('AAD_PREMIUM_P2'),
                'intune' => $plans->contains(fn ($n) => str_starts_with((string) $n, 'INTUNE_A')),
                'mde' => $plans->contains(fn ($n) => in_array($n, ['WINDEFATP', 'MDE_LITE', 'MDE_SMB', 'DEFENDER_ENDPOINT_P1'], true)),
                'known' => true,
                'skus' => collect($skus)->pluck('skuPartNumber')->all(),
            ];
        });
    }

    /** Verified custom domains that send email (excludes *.onmicrosoft.com). */
    public function emailDomains(): array
    {
        return collect($this->domains())
            ->filter(fn ($d) => ($d['isVerified'] ?? false)
                && ! str_ends_with(strtolower($d['id']), '.onmicrosoft.com')
                && in_array('Email', $d['supportedServices'] ?? [], true))
            ->pluck('id')->values()->all();
    }
}
