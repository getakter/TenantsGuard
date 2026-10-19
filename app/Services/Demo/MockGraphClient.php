<?php

namespace App\Services\Demo;

use App\Models\Tenant;
use App\Services\Access\TenantAccess;
use App\Services\Graph\GraphClientInterface;
use App\Services\Graph\GraphException;
use Carbon\Carbon;
use Illuminate\Support\Str;

/**
 * Answers the subset of Microsoft Graph that TenantsGuard uses from a demo tenant's stored state.
 * Writes are persisted so fixes, drift and rescans behave like a real tenant.
 */
class MockGraphClient implements GraphClientInterface
{
    public function __construct(private readonly Tenant $tenant) {}

    public function get(string $path): array
    {
        return $this->handle('GET', $path);
    }

    public function getAll(string $path): array
    {
        return $this->handle('GET', $path)['value'] ?? [];
    }

    public function post(string $path, array $body): array
    {
        return $this->handle('POST', $path, $body);
    }

    public function patch(string $path, array $body): array
    {
        return $this->handle('PATCH', $path, $body);
    }

    public function put(string $path, array $body): array
    {
        return $this->handle('PUT', $path, $body);
    }

    public function delete(string $path): array
    {
        return $this->handle('DELETE', $path);
    }

    private function handle(string $method, string $path, array $body = []): array
    {
        if ($method !== 'GET') {
            // Same access policy as real clients.
            TenantAccess::assertWrite($this->tenant, $method, $path);
        }
        $s = $this->tenant->demo_state;
        $p = rtrim(strtok($path, '?'), '/');
        $list = fn (array $items) => ['value' => array_values($items)];
        $hasPlan = fn (string $plan) => collect($s['skus'])->pluck('servicePlans')->flatten(1)->contains('servicePlanName', $plan);

        $mon = $this->monitoring($method, $path, $p);
        if ($mon !== null) {
            return $mon;
        }

        $dir = $this->directory($method, $path, $p, $body);
        if ($dir !== null) {
            return $dir;
        }

        $generic = $this->collection($method, $path, $p, $body);
        if ($generic !== null) {
            return $generic;
        }

        $result = match (true) {
            $method === 'GET' && $p === '/organization' => $list([$s['organization']]),
            $method === 'GET' && $p === '/subscribedSkus' => $list($s['skus']),
            $method === 'GET' && $p === '/policies/identitySecurityDefaultsEnforcementPolicy' => $s['securityDefaults'],
            $method === 'GET' && $p === '/identity/conditionalAccess/policies' => $list($s['conditionalAccess']),
            $method === 'GET' && $p === '/policies/authorizationPolicy' => $s['authorizationPolicy'],
            $method === 'GET' && $p === '/reports/authenticationMethods/userRegistrationDetails' => $list($s['registration']),
            $method === 'GET' && $p === '/users' => $list($s['users']),
            $method === 'GET' && $p === '/directoryRoles' => $list($s['directoryRoles']),
            $method === 'GET' && $p === '/domains' => $list($s['domains']),
            $method === 'GET' && $p === '/policies/authenticationMethodsPolicy' => $s['authenticationMethodsPolicy'],
            $method === 'GET' && $p === '/policies/adminConsentRequestPolicy' => $s['adminConsentRequestPolicy'],
            $method === 'GET' && $p === '/admin/sharepoint/settings' => $s['sharepointSettings'],
            $method === 'GET' && $p === '/deviceManagement/deviceCompliancePolicies' => $list($s['compliancePolicies']),
            $method === 'GET' && $p === '/deviceManagement/managedDevices' => $list($s['managedDevices']),
            $method === 'GET' && $p === '/applications' => $list($s['applications']),
            $method === 'GET' && $p === '/security/secureScores' => $list(DemoSecureScore::scores($this->tenant)),
            $method === 'GET' && $p === '/security/secureScoreControlProfiles' => $list(DemoSecureScore::profiles()),
            $method === 'GET' && $p === '/identityProtection/riskyUsers' => $hasPlan('AAD_PREMIUM_P2')
                ? $list([])
                : throw new GraphException('Your tenant is not licensed for this feature.', 403, 'Forbidden'),
            default => null,
        };
        if ($result !== null) {
            return $result;
        }

        // ---- writes ----
        if ($method === 'PATCH' && $p === '/policies/identitySecurityDefaultsEnforcementPolicy') {
            if (($body['isEnabled'] ?? false) && collect($s['conditionalAccess'])->contains('state', 'enabled')) {
                throw new GraphException('Security defaults cannot be enabled while Conditional Access policies are enabled.', 400, 'BadRequest');
            }
            $s['securityDefaults'] = self::merge($s['securityDefaults'], $body);
        } elseif ($method === 'POST' && $p === '/identity/conditionalAccess/policies') {
            if ($s['securityDefaults']['isEnabled']) {
                throw new GraphException('Conditional Access policies cannot be created while security defaults are enabled.', 400, 'BadRequest');
            }
            $body['id'] = (string) Str::uuid();
            $body['createdDateTime'] = now()->toIso8601ZuluString();
            $s['conditionalAccess'][] = $body;
            $result = $body;
        } elseif ($method === 'PATCH' && preg_match('#^/identity/conditionalAccess/policies/([\w-]+)$#', $p, $m)) {
            $i = collect($s['conditionalAccess'])->search(fn ($x) => $x['id'] === $m[1]);
            if ($i === false) {
                throw new GraphException('Policy not found', 404, 'NotFound');
            }
            $s['conditionalAccess'][$i] = self::merge($s['conditionalAccess'][$i], $body);
        } elseif ($method === 'PATCH' && $p === '/policies/authorizationPolicy') {
            $s['authorizationPolicy'] = self::merge($s['authorizationPolicy'], $body);
        } elseif ($method === 'PATCH' && preg_match('#^/domains/(.+)$#', $p, $m)) {
            $i = collect($s['domains'])->search(fn ($x) => $x['id'] === urldecode($m[1]));
            if ($i === false) {
                throw new GraphException('Domain not found', 404, 'NotFound');
            }
            $s['domains'][$i] = self::merge($s['domains'][$i], $body);
        } elseif ($method === 'PATCH' && preg_match('#^/policies/authenticationMethodsPolicy/authenticationMethodConfigurations/(\w+)$#', $p, $m)) {
            foreach ($s['authenticationMethodsPolicy']['authenticationMethodConfigurations'] as &$cfg) {
                if (strcasecmp($cfg['id'], $m[1]) === 0) {
                    $cfg = self::merge($cfg, $body);
                }
            }
            unset($cfg);
        } elseif ($method === 'PUT' && $p === '/policies/adminConsentRequestPolicy') {
            $s['adminConsentRequestPolicy'] = $body;
        } elseif ($method === 'PATCH' && $p === '/admin/sharepoint/settings') {
            $s['sharepointSettings'] = self::merge($s['sharepointSettings'], $body);
        } else {
            throw new GraphException("Demo tenant does not implement {$method} {$p}", 404, 'NotFound');
        }

        $this->tenant->demo_state = $s;
        $this->tenant->save();

        return $result ?? [];
    }

    /** Collections handled generically (Intune objects, groups) mapped to demo state keys. */
    private const COLLECTIONS = [
        '/deviceManagement/deviceCompliancePolicies' => 'compliancePolicies',
        '/deviceManagement/deviceConfigurations' => 'deviceConfigurations',
        '/deviceAppManagement/iosManagedAppProtections' => 'iosAppProtections',
        '/deviceAppManagement/androidManagedAppProtections' => 'androidAppProtections',
        '/deviceManagement/windowsFeatureUpdateProfiles' => 'featureUpdateProfiles',
        '/beta/deviceManagement/windowsAutopilotDeploymentProfiles' => 'autopilotProfiles',
        '/groups' => 'groups',
        '/security/labels/retentionLabels' => 'retentionLabels',
        '/beta/security/informationProtection/sensitivityLabels' => 'sensitivityLabels',
    ];

    /** Kinds Graph requires an @odata.type for on create. */
    private const TYPED = ['compliancePolicies', 'deviceConfigurations', 'iosAppProtections', 'androidAppProtections', 'autopilotProfiles'];

    /**
     * Generic CRUD for Intune collections and groups, emulating Graph validation closely enough
     * to catch payload mistakes: required @odata.type and displayName, scheduledActionsForRule on
     * compliance creation, and no scheduledActionsForRule in PATCH.
     */
    private function collection(string $method, string $fullPath, string $p, array $body): ?array
    {
        $s = $this->tenant->demo_state;

        // Read-only Intune service endpoints used by prerequisite checks.
        if ($method === 'GET') {
            switch ($p) {
                case '/deviceManagement/applePushNotificationCertificate':
                    return $s['apns'] ?? throw new GraphException('Resource not found', 404, 'ResourceNotFound');
                case '/deviceManagement/androidManagedStoreAccountEnterpriseSettings':
                    return $s['managedGooglePlay'] ?? ['bindStatus' => 'notBound'];
                case '/deviceManagement/mobileThreatDefenseConnectors':
                    return ['value' => $s['mtdConnectors'] ?? []];
                case '/beta/policies/mobileDeviceManagementPolicies':
                    return ['value' => [['id' => '0000000a-0000-0000-c000-000000000000', 'displayName' => 'Microsoft Intune', 'appliesTo' => $s['mdmScope'] ?? 'all']]];
                case "/beta/reports/getMicrosoft365CopilotUsageUserDetail(period='D30')":
                    // Copilot-licensed users; activity is deterministic per user (a third never used it).
                    $copilot = '639dec6b-bb19-468b-871c-c5c441c4b0cb';
                    $rows = [];
                    foreach ($s['users'] as $u) {
                        if (! collect($u['assignedLicenses'] ?? [])->contains('skuId', $copilot)) {
                            continue;
                        }
                        $h = crc32($u['id']);
                        $last = $h % 3 === 0 ? null : now()->subDays($h % 9)->toDateString();
                        $rows[] = ['userPrincipalName' => $u['userPrincipalName'], 'displayName' => $u['displayName'], 'lastActivityDate' => $last,
                            'microsoftTeamsCopilotLastActivityDate' => $last, 'outlookCopilotLastActivityDate' => $last, 'wordCopilotLastActivityDate' => $h % 2 ? $last : null,
                            'excelCopilotLastActivityDate' => null, 'powerPointCopilotLastActivityDate' => null, 'copilotChatLastActivityDate' => $last];
                    }

                    return ['value' => $rows];
                case '/beta/policies/deviceRegistrationPolicy':
                    return ['localAdminPassword' => ['isEnabled' => $s['lapsEnabled'] ?? false]];
            }
        }

        $base = null;
        $rest = '';
        foreach (self::COLLECTIONS as $path => $key) {
            if ($p === $path || str_starts_with($p, $path.'/')) {
                $base = $path;
                $rest = substr($p, strlen($path));
                break;
            }
        }
        if ($base === null) {
            return null;
        }
        $key = self::COLLECTIONS[$base];
        $items = $s[$key] ?? [];
        $parts = array_values(array_filter(explode('/', $rest)));
        $id = $parts[0] ?? null;
        $action = $parts[1] ?? null;
        $index = $id === null ? false : collect($items)->search(fn ($x) => ($x['id'] ?? null) === $id);

        if ($id === null && $method === 'GET') {
            if (preg_match("/displayName eq '((?:[^']|'')*)'/", urldecode((string) parse_url($fullPath, PHP_URL_QUERY)), $m)) {
                $name = str_replace("''", "'", $m[1]);
                $items = array_values(array_filter($items, fn ($x) => ($x['displayName'] ?? null) === $name));
            }

            return ['value' => array_values($items)];
        }

        if ($id === null && $method === 'POST') {
            if (empty($body['displayName'])) {
                throw new GraphException('displayName is required.', 400, 'BadRequest');
            }
            if (in_array($key, self::TYPED, true) && empty($body['@odata.type'])) {
                throw new GraphException('An @odata.type is required to create this resource.', 400, 'BadRequest');
            }
            if ($key === 'retentionLabels') {
                in_array($body['behaviorDuringRetentionPeriod'] ?? null, ['doNotRetain', 'retain', 'retainAsRecord', 'retainAsRegulatoryRecord'], true) || throw new GraphException('Invalid behaviorDuringRetentionPeriod.', 400, 'BadRequest');
                isset($body['retentionDuration']['@odata.type']) || throw new GraphException('retentionDuration with @odata.type is required.', 400, 'BadRequest');
                collect($items)->contains(fn ($x) => strcasecmp($x['displayName'], $body['displayName']) === 0) && throw new GraphException('A label with this name already exists.', 400, 'BadRequest');
            }
            if ($key === 'sensitivityLabels') {
                throw new GraphException('Sensitivity labels cannot be created through Microsoft Graph.', 405, 'MethodNotAllowed');
            }
            if ($key === 'compliancePolicies' && empty($body['scheduledActionsForRule'])) {
                throw new GraphException('scheduledActionsForRule is required when creating a compliance policy.', 400, 'BadRequest');
            }
            $obj = $body + ['id' => (string) Str::uuid(), 'createdDateTime' => now()->toIso8601ZuluString()];
            $obj['lastModifiedDateTime'] = $obj['createdDateTime'];
            unset($obj['scheduledActionsForRule']);
            $s[$key][] = $obj;
            $this->save($s);

            return $obj;
        }

        if ($index === false) {
            throw new GraphException('Resource not found', 404, 'ResourceNotFound');
        }

        if ($method === 'GET' && $action === null) {
            return $items[$index];
        }
        if ($method === 'PATCH' && $action === null) {
            if (isset($body['scheduledActionsForRule'])) {
                throw new GraphException('scheduledActionsForRule cannot be updated with PATCH.', 400, 'BadRequest');
            }
            $s[$key][$index] = self::merge($items[$index], $body) + ['lastModifiedDateTime' => now()->toIso8601ZuluString()];
            $s[$key][$index]['lastModifiedDateTime'] = now()->toIso8601ZuluString();
            $this->save($s);

            return [];
        }
        if ($method === 'POST' && $action === 'assign') {
            $s[$key][$index]['assignments'] = $body['assignments'] ?? [];
            $this->save($s);

            return ['value' => $body['assignments'] ?? []];
        }
        if ($method === 'POST' && $action === 'assignments') {
            $s[$key][$index]['assignments'][] = $body;
            $this->save($s);

            return $body + ['id' => (string) Str::uuid()];
        }
        if ($method === 'POST' && $action === 'targetApps') {
            $s[$key][$index]['appGroupType'] = $body['appGroupType'] ?? 'selectedPublicApps';
            $s[$key][$index]['apps'] = $body['apps'] ?? [];
            $this->save($s);

            return [];
        }

        throw new GraphException("Demo tenant does not implement {$method} {$p}", 404, 'NotFound');
    }

    /** Audit and sign-in logs, Defender alerts and service health for security monitoring. */
    private function monitoring(string $method, string $fullPath, string $p): ?array
    {
        $routes = ['/auditLogs/directoryAudits' => 'audits', '/auditLogs/signIns' => 'signIns', '/security/alerts_v2' => 'defenderAlerts', '/admin/serviceAnnouncement/issues' => 'serviceIssues'];
        if ($method !== 'GET' || ! isset($routes[$p])) {
            return null;
        }
        $s = $this->tenant->demo_state;
        if (empty($s['monitoringSeeded'])) {
            $s = DemoActivity::seed($this->seedDirectory($s));
            $this->save($s);
        }
        $hasPlan = fn (string $plan) => collect($s['skus'])->pluck('servicePlans')->flatten(1)->contains('servicePlanName', $plan);
        if ($p === '/auditLogs/signIns' && ! $hasPlan('AAD_PREMIUM')) {
            throw new GraphException('Neither tenant is B2C or tenant doesn\'t have premium license', 403, 'Authentication_RequestFromNonPremiumTenantOrB2CTenant');
        }
        $items = $s[$routes[$p]] ?? [];
        parse_str((string) parse_url($fullPath, PHP_URL_QUERY), $q);
        $filter = (string) ($q['$filter'] ?? '');
        if (preg_match('/(activityDateTime|createdDateTime) ge (\S+)/', $filter, $m)) {
            $since = Carbon::parse($m[2]);
            $items = array_filter($items, fn ($x) => Carbon::parse($x[$m[1]] ?? $x['createdDateTime'] ?? now())->gte($since));
        }
        if (preg_match("/userPrincipalName eq '([^']+)'/", $filter, $m)) {
            $items = array_filter($items, fn ($x) => strcasecmp($x['userPrincipalName'] ?? '', $m[1]) === 0);
        }
        if (str_contains($filter, 'isResolved eq false')) {
            $items = array_filter($items, fn ($x) => ! ($x['isResolved'] ?? false));
        }

        return ['value' => array_values($items)];
    }

    // ------------------------------------------------------------------ users, licences, groups (staff lifecycle)

    public const DEMO_SKUS = [
        ['skuId' => 'cbdc14ab-d96c-4c30-b9f4-6ada7cdc1d46', 'skuPartNumber' => 'SPB', 'enabled' => 15],
        ['skuId' => '3b555118-da6a-4418-894f-7df1e2096870', 'skuPartNumber' => 'O365_BUSINESS_ESSENTIALS', 'enabled' => 5],
        ['skuId' => '4b9405b0-7788-4568-add1-99614e613b69', 'skuPartNumber' => 'EXCHANGESTANDARD', 'enabled' => 3],
        ['skuId' => '639dec6b-bb19-468b-871c-c5c441c4b0cb', 'skuPartNumber' => 'Microsoft_365_Copilot', 'enabled' => 5],
    ];

    /** Service plans of the non-SPB demo SKUs (SPB plans come from the tenant state). */
    private const SKU_PLANS = [
        'O365_BUSINESS_ESSENTIALS' => ['EXCHANGE_S_STANDARD', 'SHAREPOINTSTANDARD', 'TEAMS1', 'SHAREPOINTWAC'],
        'EXCHANGESTANDARD' => ['EXCHANGE_S_STANDARD'],
        'Microsoft_365_Copilot' => ['M365_COPILOT_APPS', 'M365_COPILOT_TEAMS', 'M365_COPILOT_BUSINESS_CHAT', 'GRAPH_CONNECTORS_COPILOT', 'M365_COPILOT_SHAREPOINT'],
    ];

    private const DEMO_GROUPS = [
        ['All Staff', ['Unified'], true, true],
        ['Clinic Team', ['Unified'], true, true],
        ['Reception', [], false, true],
        ['Finance', [], false, true],
        ['VPN Users', [], false, true],
        ['All Users (dynamic)', ['DynamicMembership'], false, true],
    ];

    /** Ensure demo directory data exists (older demo tenants were created before staff lifecycle). */
    private function seedDirectory(array $s): array
    {
        $changed = false;
        if (empty($s['directorySeeded'])) {
            $spb = self::DEMO_SKUS[0]['skuId'];
            foreach ($s['users'] as $i => $u) {
                if (($u['userType'] ?? 'Member') === 'Member') {
                    $s['users'][$i]['assignedLicenses'] = [['skuId' => $spb, 'disabledPlans' => []]];
                    $s['users'][$i]['usageLocation'] = 'AU';
                }
            }
            $members = array_column(array_filter($s['users'], fn ($u) => ($u['userType'] ?? 'Member') === 'Member'), 'id');
            foreach (self::DEMO_GROUPS as $n => [$name, $types, $mail, $sec]) {
                $s['groups'][] = [
                    'id' => (string) Str::uuid(), 'displayName' => $name, 'groupTypes' => $types, 'mailEnabled' => $mail, 'securityEnabled' => $sec,
                    'resourceProvisioningOptions' => $name === 'Clinic Team' ? ['Team'] : [],
                    'members' => $n === 0 ? $members : array_slice($members, $n, 3),
                ];
            }
            $s['organization']['countryLetterCode'] = 'AU';
            $s['directorySeeded'] = true;
            $changed = true;
        }
        if (empty($s['ownershipSeeded'])) {
            // Front Desk solely owns "Reception" and co-owns "Clinic Team"; they also have a forwarding inbox rule.
            $fd = $this->findUser($s, 'frontdesk@'.($s['domains'][0]['id'] ?? ''));
            $fdId = $fd !== false ? $s['users'][$fd]['id'] : null;
            $firstMember = collect($s['users'])->firstWhere('userType', 'Member')['id'] ?? null;
            foreach ($s['groups'] ?? [] as $gi => $g) {
                $owners = $g['owners'] ?? [];
                if ($fdId && $g['displayName'] === 'Reception') {
                    $owners = [$fdId];
                }
                if ($fdId && $g['displayName'] === 'Clinic Team') {
                    $owners = array_values(array_unique(array_filter([$firstMember, $fdId])));
                }
                $s['groups'][$gi]['owners'] = $owners;
            }
            if ($fd !== false) {
                $s['users'][$fd]['inboxRules'] = [
                    ['id' => 'rule-'.substr(md5($fdId), 0, 8), 'displayName' => 'Forward everything', 'isEnabled' => true, 'actions' => ['forwardTo' => [['emailAddress' => ['address' => 'frontdesk.personal@gmail.com']]]]],
                    ['id' => 'rule-'.substr(md5($fdId.'b'), 0, 8), 'displayName' => 'Newsletters to folder', 'isEnabled' => true, 'actions' => ['moveToFolder' => 'Newsletters']],
                ];
            }
            $s['ownershipSeeded'] = true;
            $changed = true;
        }
        if (empty($s['guestsSeeded'])) {
            // Guest states for the guest directory: the existing guest accepted long ago; a new one is still pending.
            foreach ($s['users'] as $i => $u) {
                if (($u['userType'] ?? '') === 'Guest' && ! isset($u['externalUserState'])) {
                    $s['users'][$i] += ['externalUserState' => 'Accepted', 'mail' => 'partner@lab.example', 'creationType' => 'Invitation'];
                }
            }
            $s['users'][] = ['id' => (string) Str::uuid(), 'displayName' => 'Sam Contractor (Guest)', 'userPrincipalName' => 'sam_contractor.example#EXT#@'.($s['domains'][0]['id'] ?? 'demo.example'),
                'mail' => 'sam@contractor.example', 'accountEnabled' => true, 'userType' => 'Guest', 'externalUserState' => 'PendingAcceptance', 'creationType' => 'Invitation',
                'createdDateTime' => now()->subDays(12)->toIso8601ZuluString(), 'signInActivity' => ['lastSignInDateTime' => null], 'assignedLicenses' => []];
            $s['guestsSeeded'] = true;
            $changed = true;
        }
        if (empty($s['copilotSeeded'])) {
            // Microsoft 365 groups are public unless made private; two demo groups are public (oversharing signal).
            foreach ($s['groups'] ?? [] as $gi => $g) {
                if (in_array('Unified', $g['groupTypes'] ?? [], true) && ! isset($g['visibility'])) {
                    $s['groups'][$gi]['visibility'] = in_array($g['displayName'], ['All Staff', 'Clinic Team'], true) ? 'Public' : 'Private';
                }
            }
            foreach ($s['skus'] ?? [] as $i => $sku) {
                if (($sku['skuPartNumber'] ?? '') === 'SPB' && ! collect($sku['servicePlans'])->contains('servicePlanName', 'OFFICE_BUSINESS')) {
                    $s['skus'][$i]['servicePlans'][] = ['servicePlanName' => 'OFFICE_BUSINESS', 'provisioningStatus' => 'Success'];
                    $s['skus'][$i]['servicePlans'][] = ['servicePlanName' => 'SHAREPOINTWAC', 'provisioningStatus' => 'Success'];
                }
            }
            $s['copilotSeeded'] = true;
            $changed = true;
        }
        if (empty($s['licenceWasteSeeded'])) {
            // A duplicate licence for licence optimisation: Exchange Plan 1 on top of Business Premium.
            $fd = collect($s['users'])->search(fn ($u) => ($u['displayName'] ?? '') === 'Front Desk');
            if ($fd !== false) {
                $s['users'][$fd]['assignedLicenses'][] = ['skuId' => self::DEMO_SKUS[2]['skuId'], 'disabledPlans' => []];
            }
            $s['licenceWasteSeeded'] = true;
            $changed = true;
        }
        if ($changed) {
            $this->save($s);
        }

        return $s;
    }

    private function findUser(array $s, string $idOrUpn): int|false
    {
        $key = strtolower(urldecode($idOrUpn));

        return collect($s['users'])->search(fn ($u) => strtolower($u['id']) === $key || strtolower($u['userPrincipalName']) === $key);
    }

    private static function refId(array $body): string
    {
        return basename((string) ($body['@odata.id'] ?? ''));
    }

    private function directory(string $method, string $fullPath, string $p, array $body): ?array
    {
        if (! preg_match('#^/(users|groups|subscribedSkus|directoryRoles|deviceManagement/managedDevices|organization)(/|$)#', $p)) {
            return null;
        }
        $s = $this->seedDirectory($this->tenant->demo_state);
        $fail = fn (string $msg, int $code = 400) => throw new GraphException($msg, $code, $code === 404 ? 'Request_ResourceNotFound' : 'Request_BadRequest');

        // ---- licences ----
        if ($method === 'GET' && $p === '/subscribedSkus') {
            $plans = $s['skus'][0]['servicePlans'] ?? [];

            return ['value' => array_map(function ($sku) use ($s, $plans) {
                $consumed = collect($s['users'])->filter(fn ($u) => collect($u['assignedLicenses'] ?? [])->contains('skuId', $sku['skuId']))->count();

                return [
                    'skuId' => $sku['skuId'], 'skuPartNumber' => $sku['skuPartNumber'], 'capabilityStatus' => 'Enabled',
                    'prepaidUnits' => ['enabled' => $sku['enabled'], 'suspended' => 0, 'warning' => 0], 'consumedUnits' => $consumed,
                    'servicePlans' => $sku['skuPartNumber'] === 'SPB' ? $plans : array_map(fn ($n) => ['servicePlanName' => $n, 'provisioningStatus' => 'Success'], self::SKU_PLANS[$sku['skuPartNumber']] ?? ['EXCHANGE_S_STANDARD']),
                ];
            }, self::DEMO_SKUS)];
        }

        if ($method === 'GET' && $p === '/organization') {
            return ['value' => [$s['organization'] + ['verifiedDomains' => array_map(fn ($d) => ['name' => $d['id'], 'isDefault' => $d['isDefault'] ?? false], $s['domains'])]]];
        }

        // ---- directory roles ----
        if ($p === '/directoryRoles' && $method === 'GET') {
            return ['value' => $s['directoryRoles']];
        }
        if ($method === 'DELETE' && preg_match('#^/directoryRoles/([^/]+)/members/([^/]+)/\$ref$#', $p, $m)) {
            foreach ($s['directoryRoles'] as &$role) {
                if ($role['id'] === $m[1]) {
                    $role['members'] = array_values(array_filter($role['members'], fn ($x) => $x['id'] !== $m[2]));
                }
            }
            unset($role);
            $this->save($s);

            return [];
        }

        // ---- devices ----
        if ($p === '/deviceManagement/managedDevices' && $method === 'GET') {
            $q = urldecode((string) parse_url($fullPath, PHP_URL_QUERY));
            $devices = $s['managedDevices'];
            if (preg_match("/userId eq '([^']+)'/", $q, $m)) {
                $upn = collect($s['users'])->firstWhere('id', $m[1])['userPrincipalName'] ?? '';
                $devices = array_values(array_filter($devices, fn ($d) => strcasecmp($d['userPrincipalName'] ?? '', $upn) === 0));
            }

            return ['value' => array_map(fn ($d) => $d + ['managedDeviceOwnerType' => 'company', 'managementState' => 'managed', 'lastSyncDateTime' => now()->subHours(3)->toIso8601ZuluString()], $devices)];
        }
        if ($method === 'POST' && preg_match('#^/deviceManagement/managedDevices/([^/]+)/(retire|wipe)$#', $p, $m)) {
            $i = collect($s['managedDevices'])->search(fn ($d) => $d['id'] === $m[1]);
            $i === false && $fail('Device not found', 404);
            $s['managedDevices'][$i]['managementState'] = $m[2].'Pending';
            $this->save($s);

            return [];
        }

        // ---- groups (membership only; group CRUD stays in the generic collection) ----
        if ($p === '/groups' && $method === 'GET') {
            $q = urldecode((string) parse_url($fullPath, PHP_URL_QUERY));
            $groups = $s['groups'] ?? [];
            if (preg_match("/displayName eq '((?:[^']|'')*)'/", $q, $m)) {
                $groups = array_values(array_filter($groups, fn ($g) => $g['displayName'] === str_replace("''", "'", $m[1])));
            }

            return ['value' => array_map(fn ($g) => array_diff_key($g, ['members' => 1]) + ['groupTypes' => [], 'mailEnabled' => false, 'securityEnabled' => true], $groups)];
        }
        if (preg_match('#^/groups/([^/]+)/members(/([^/]+))?/\$ref$#', $p, $m)) {
            $gi = collect($s['groups'] ?? [])->search(fn ($g) => $g['id'] === $m[1]);
            $gi === false && $fail('Group not found', 404);
            if (in_array('DynamicMembership', $s['groups'][$gi]['groupTypes'] ?? [], true)) {
                $fail('Members of a dynamic group cannot be added or removed manually.');
            }
            $members = $s['groups'][$gi]['members'] ?? [];
            if ($method === 'POST') {
                $uid = self::refId($body);
                in_array($uid, $members, true) && $fail('One or more added object references already exist for the following modified properties: \'members\'.');
                $members[] = $uid;
            } elseif ($method === 'DELETE') {
                $members = array_values(array_diff($members, [$m[3]]));
            }
            $s['groups'][$gi]['members'] = $members;
            $this->save($s);

            return [];
        }
        if (preg_match('#^/groups/([^/]+)/owners(/([^/]+))?/\$ref$#', $p, $m)) {
            $gi = collect($s['groups'] ?? [])->search(fn ($g) => $g['id'] === $m[1]);
            $gi === false && $fail('Group not found', 404);
            $owners = $s['groups'][$gi]['owners'] ?? [];
            if ($method === 'POST') {
                $uid = self::refId($body);
                in_array($uid, $owners, true) && $fail('One or more added object references already exist for the following modified properties: \'owners\'.');
                $owners[] = $uid;
            } elseif ($method === 'DELETE') {
                in_array($m[3], $owners, true) || $fail('Owner not found.', 404);
                count($owners) === 1 && ($s['groups'][$gi]['groupTypes'] ?? []) === ['Unified']
                    && $fail('The group must have at least one owner. Add another owner before removing this one.');
                $owners = array_values(array_diff($owners, [$m[3]]));
            }
            $s['groups'][$gi]['owners'] = $owners;
            $this->save($s);

            return [];
        }
        if ($method === 'GET' && preg_match('#^/groups/([^/]+)/(members|owners)$#', $p, $m)) {
            $grp = collect($s['groups'] ?? [])->firstWhere('id', $m[1]) ?? $fail('Group not found', 404);
            $ids = $grp[$m[2]] ?? [];

            return ['value' => array_values(array_map(fn ($u) => ['@odata.type' => '#microsoft.graph.user'] + array_intersect_key($u, array_flip(['id', 'displayName', 'userPrincipalName', 'mail', 'userType', 'accountEnabled', 'jobTitle'])),
                array_filter($s['users'], fn ($u) => in_array($u['id'], $ids, true))))];
        }
        if (str_starts_with($p, '/groups')) {
            return null; // generic collection handles group create/list for Intune
        }

        // ---- users ----
        if ($p === '/users' && $method === 'GET') {
            return ['value' => $s['users']];
        }
        if ($p === '/users' && $method === 'POST') {
            foreach (['accountEnabled', 'displayName', 'mailNickname', 'userPrincipalName', 'passwordProfile'] as $req) {
                array_key_exists($req, $body) || $fail("Property '{$req}' is required when creating the user.");
            }
            $this->findUser($s, $body['userPrincipalName']) !== false && $fail('Another object with the same value for property userPrincipalName already exists.');
            $domain = substr(strrchr($body['userPrincipalName'], '@'), 1);
            collect($s['domains'])->contains('id', $domain) || $fail('The domain portion of the userPrincipalName property is invalid. You must use one of the verified domain names in your organization.');
            $user = array_diff_key($body, ['passwordProfile' => 1]) + [
                'id' => (string) Str::uuid(), 'userType' => 'Member', 'createdDateTime' => now()->toIso8601ZuluString(),
                'assignedLicenses' => [], 'onPremisesSyncEnabled' => null, 'signInActivity' => ['lastSignInDateTime' => null],
                'authMethods' => [['id' => '28c10230-6103-485e-b985-444c60001490', '@odata.type' => '#microsoft.graph.passwordAuthenticationMethod']],
            ];
            $s['users'][] = $user;
            $s['registration'][] = ['id' => $user['id'], 'userPrincipalName' => $user['userPrincipalName'], 'userDisplayName' => $user['displayName'], 'userType' => 'member', 'isAdmin' => false, 'isMfaRegistered' => false, 'isMfaCapable' => false, 'methodsRegistered' => []];
            $this->save($s);

            return $user;
        }
        if (! preg_match('#^/users/([^/]+)(/(.+))?$#', $p, $m)) {
            return null;
        }
        $ui = $this->findUser($s, $m[1]);
        $ui === false && $fail("Resource '{$m[1]}' does not exist or one of its queried reference-property objects are not present.", 404);
        $user = $s['users'][$ui];
        $sub = $m[3] ?? '';

        if ($sub === '' && $method === 'GET') {
            return $user;
        }
        if ($sub === '' && $method === 'PATCH') {
            $s['users'][$ui] = self::merge($user, array_diff_key($body, ['passwordProfile' => 1]));
            $this->save($s);

            return [];
        }
        if ($sub === '' && $method === 'DELETE') {
            $s['deletedUsers'][] = $user;
            array_splice($s['users'], $ui, 1);
            foreach ($s['groups'] ?? [] as $gi => $g) {
                $s['groups'][$gi]['members'] = array_values(array_diff($g['members'] ?? [], [$user['id']]));
            }
            $this->save($s);

            return [];
        }
        if ($sub === 'assignLicense' && $method === 'POST') {
            if (! empty($body['addLicenses']) && empty($user['usageLocation'])) {
                $fail('License assignment cannot be done for user with invalid usage location.');
            }
            $lic = collect($user['assignedLicenses'] ?? [])->reject(fn ($l) => in_array($l['skuId'], $body['removeLicenses'] ?? [], true))->values()->all();
            foreach ($body['addLicenses'] ?? [] as $add) {
                $sku = collect(self::DEMO_SKUS)->firstWhere('skuId', $add['skuId']) ?? $fail('License '.$add['skuId'].' does not correspond to a valid company License.');
                $used = collect($s['users'])->filter(fn ($u) => collect($u['assignedLicenses'] ?? [])->contains('skuId', $sku['skuId']))->count();
                $used >= $sku['enabled'] && $fail("Subscription {$sku['skuPartNumber']} does not have any available licenses.");
                if (! collect($lic)->contains('skuId', $add['skuId'])) {
                    $lic[] = ['skuId' => $add['skuId'], 'disabledPlans' => []];
                }
            }
            $s['users'][$ui]['assignedLicenses'] = $lic;
            $this->save($s);

            return $s['users'][$ui];
        }
        if ($sub === 'revokeSignInSessions' && $method === 'POST') {
            $s['users'][$ui]['sessionsRevokedAt'] = now()->toIso8601ZuluString();
            $this->save($s);

            return ['value' => true];
        }
        if ($sub === 'manager/$ref' && $method === 'PUT') {
            $s['users'][$ui]['managerId'] = self::refId($body);
            $this->save($s);

            return [];
        }
        if ($sub === 'manager' && $method === 'GET') {
            $mi = isset($user['managerId']) ? $this->findUser($s, $user['managerId']) : false;
            $mi === false && $fail('Resource manager does not exist.', 404);

            return $s['users'][$mi];
        }
        if ($sub === 'memberOf' && $method === 'GET') {
            $groups = array_map(fn ($g) => ['@odata.type' => '#microsoft.graph.group', 'id' => $g['id'], 'displayName' => $g['displayName'], 'groupTypes' => $g['groupTypes'] ?? [], 'mailEnabled' => $g['mailEnabled'] ?? false, 'securityEnabled' => $g['securityEnabled'] ?? true],
                array_values(array_filter($s['groups'] ?? [], fn ($g) => in_array($user['id'], $g['members'] ?? [], true))));
            $roles = array_map(fn ($r) => ['@odata.type' => '#microsoft.graph.directoryRole', 'id' => $r['id'], 'displayName' => $r['displayName'], 'roleTemplateId' => $r['roleTemplateId']],
                array_values(array_filter($s['directoryRoles'], fn ($r) => collect($r['members'])->contains('id', $user['id']))));

            return ['value' => array_merge($groups, $roles)];
        }
        if ($sub === 'ownedObjects' && $method === 'GET') {
            return ['value' => array_map(fn ($g) => ['@odata.type' => '#microsoft.graph.group', 'id' => $g['id'], 'displayName' => $g['displayName']],
                array_values(array_filter($s['groups'] ?? [], fn ($g) => in_array($user['id'], $g['owners'] ?? [], true))))];
        }
        if ($sub === 'mailFolders/inbox/messageRules' && $method === 'GET') {
            return ['value' => $user['inboxRules'] ?? []];
        }
        if ($method === 'DELETE' && preg_match('#^mailFolders/inbox/messageRules/([^/]+)$#', $sub, $mm)) {
            collect($user['inboxRules'] ?? [])->contains('id', $mm[1]) || $fail('The specified object was not found in the store.', 404);
            $s['users'][$ui]['inboxRules'] = array_values(array_filter($user['inboxRules'], fn ($r) => $r['id'] !== $mm[1]));
            $this->save($s);

            return [];
        }
        if ($sub === 'mailboxSettings' && $method === 'PATCH') {
            $s['users'][$ui]['mailboxSettings'] = self::merge($user['mailboxSettings'] ?? [], $body);
            $this->save($s);

            return [];
        }
        if ($sub === 'authentication/methods' && $method === 'GET') {
            $methods = $user['authMethods'] ?? [['id' => '28c10230-6103-485e-b985-444c60001490', '@odata.type' => '#microsoft.graph.passwordAuthenticationMethod']];
            $reg = collect($s['registration'])->firstWhere('id', $user['id']);
            if (! isset($user['authMethods']) && ($reg['isMfaRegistered'] ?? false)) {
                $methods[] = ['id' => 'auth-'.$user['id'], '@odata.type' => '#microsoft.graph.microsoftAuthenticatorAuthenticationMethod', 'displayName' => 'iPhone'];
                $methods[] = ['id' => 'phone-'.$user['id'], '@odata.type' => '#microsoft.graph.phoneAuthenticationMethod', 'phoneNumber' => '+61 4xx xxx xxx'];
            }

            return ['value' => $methods];
        }
        if ($method === 'DELETE' && preg_match('#^authentication/(\w+)/([^/]+)$#', $sub, $mm)) {
            $methods = $this->directory('GET', "/users/{$user['id']}/authentication/methods", "/users/{$user['id']}/authentication/methods", [])['value'];
            $s = $this->tenant->demo_state;
            $s['users'][$ui]['authMethods'] = array_values(array_filter($methods, fn ($x) => $x['id'] !== $mm[2]));
            foreach ($s['registration'] as $ri => $r) {
                if ($r['id'] === $user['id'] && count($s['users'][$ui]['authMethods']) <= 1) {
                    $s['registration'][$ri]['isMfaRegistered'] = false;
                }
            }
            $this->save($s);

            return [];
        }
        if ($sub === 'authentication/temporaryAccessPassMethods' && $method === 'POST') {
            $tap = ['id' => (string) Str::uuid(), 'temporaryAccessPass' => strtoupper(Str::random(4)).'-'.random_int(1000, 9999).'-'.strtolower(Str::random(4)),
                'lifetimeInMinutes' => $body['lifetimeInMinutes'] ?? 60, 'isUsableOnce' => $body['isUsableOnce'] ?? false, 'startDateTime' => now()->toIso8601ZuluString()];
            $s['users'][$ui]['authMethods'] = array_merge($user['authMethods'] ?? [], [['id' => $tap['id'], '@odata.type' => '#microsoft.graph.temporaryAccessPassAuthenticationMethod']]);
            $this->save($s);

            return $tap;
        }

        throw new GraphException("Demo tenant does not implement {$method} {$p}", 404, 'NotFound');
    }

    private function save(array $state): void
    {
        $this->tenant->demo_state = $state;
        $this->tenant->save();
    }

    /** Deep merge where associative arrays merge and lists are replaced (Graph PATCH semantics). */
    public static function merge(array $base, array $patch): array
    {
        foreach ($patch as $k => $v) {
            if (is_array($v) && ! array_is_list($v) && isset($base[$k]) && is_array($base[$k]) && ! array_is_list($base[$k])) {
                $base[$k] = self::merge($base[$k], $v);
            } else {
                $base[$k] = $v;
            }
        }

        return $base;
    }
}
