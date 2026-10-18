<?php

namespace App\Services\Demo;

use App\Models\Tenant;
use App\Services\Checks\Roles;
use Illuminate\Support\Str;

/**
 * Builds the in-memory state of a fictional, deliberately weak Microsoft 365 tenant
 * and simulates configuration drift on it.
 */
class DemoTenant
{
    public static function create(?string $name = null): Tenant
    {
        $name ??= 'Brightwater Dental (Demo)';

        return Tenant::create([
            'name' => $name,
            'domain' => 'brightwaterdental.example',
            'ms_tenant_id' => (string) Str::uuid(),
            'mode' => 'demo',
            'consent_status' => 'granted',
            'demo_state' => self::initialState($name),
        ]);
    }

    public static function initialState(string $name): array
    {
        $now = now();
        $domain = 'brightwaterdental.example';
        $people = [
            ['Avery Patel', 'avery.patel', 'admin', true, 2, false],
            ['Jordan Lee', 'jordan.lee', 'admin', false, 1, false],
            ['Sam Rivera', 'sam.rivera', 'admin', true, 5, true],
            ['Taylor Brooks', 'taylor.brooks', 'admin', false, 40, false],
            ['Morgan Chen', 'morgan.chen', 'admin', true, 3, false],
            ['Casey Nguyen', 'casey.nguyen', 'user', true, 1, false],
            ['Riley Adams', 'riley.adams', 'user', false, 7, false],
            ['Jamie Fox', 'jamie.fox', 'user', true, 0, false],
            ['Drew Kim', 'drew.kim', 'user', false, 130, false],
            ['Quinn Harper', 'quinn.harper', 'user', true, 2, false],
            ['Reese Morgan', 'reese.morgan', 'user', false, 210, false],
            ['Front Desk', 'frontdesk', 'user', false, 4, false],
            ['Old Scanner', 'scanner', 'user', false, null, false],
        ];

        $users = [];
        $registration = [];
        $adminIds = [];
        foreach ($people as [$display, $alias, $kind, $mfa, $lastSignInDays, $synced]) {
            $id = (string) Str::uuid();
            $upn = "{$alias}@{$domain}";
            $users[] = [
                'id' => $id,
                'displayName' => $display,
                'userPrincipalName' => $upn,
                'accountEnabled' => true,
                'userType' => 'Member',
                'onPremisesSyncEnabled' => $synced ?: null,
                'createdDateTime' => $now->copy()->subDays(400)->toIso8601ZuluString(),
                'signInActivity' => ['lastSignInDateTime' => $lastSignInDays === null ? null : $now->copy()->subDays($lastSignInDays)->toIso8601ZuluString()],
            ];
            $registration[] = [
                'id' => $id,
                'userPrincipalName' => $upn,
                'userDisplayName' => $display,
                'userType' => 'member',
                'isAdmin' => $kind === 'admin',
                'isMfaRegistered' => $mfa,
                'isMfaCapable' => $mfa,
                'methodsRegistered' => $mfa ? ['microsoftAuthenticatorPush', 'mobilePhone'] : [],
            ];
            if ($kind === 'admin') {
                $adminIds[] = $id;
            }
        }
        $guestId = (string) Str::uuid();
        $users[] = [
            'id' => $guestId, 'displayName' => 'Lab Partner (Guest)', 'userPrincipalName' => 'partner_lab.example#EXT#@'.$domain,
            'accountEnabled' => true, 'userType' => 'Guest', 'onPremisesSyncEnabled' => null,
            'createdDateTime' => $now->copy()->subDays(300)->toIso8601ZuluString(), 'signInActivity' => ['lastSignInDateTime' => null],
        ];

        $member = fn ($id) => collect($users)->firstWhere('id', $id);

        return [
            'organization' => ['id' => (string) Str::uuid(), 'displayName' => $name],
            'skus' => [[
                'skuPartNumber' => 'SPB', 'capabilityStatus' => 'Enabled', 'prepaidUnits' => ['enabled' => 15],
                'servicePlans' => [
                    ['servicePlanName' => 'AAD_PREMIUM', 'provisioningStatus' => 'Success'],
                    ['servicePlanName' => 'INTUNE_A', 'provisioningStatus' => 'Success'],
                    ['servicePlanName' => 'EXCHANGE_S_STANDARD', 'provisioningStatus' => 'Success'],
                    ['servicePlanName' => 'MDE_SMB', 'provisioningStatus' => 'Success'],
                    ['servicePlanName' => 'MIP_S_CLP1', 'provisioningStatus' => 'Success'],
                    ['servicePlanName' => 'RMS_S_PREMIUM', 'provisioningStatus' => 'Success'],
                    ['servicePlanName' => 'RMS_S_ENTERPRISE', 'provisioningStatus' => 'Success'],
                    ['servicePlanName' => 'SHAREPOINTSTANDARD', 'provisioningStatus' => 'Success'],
                    ['servicePlanName' => 'TEAMS1', 'provisioningStatus' => 'Success'],
                ],
            ]],
            'securityDefaults' => ['id' => '00000000-0000-0000-0000-000000000005', 'isEnabled' => false],
            'conditionalAccess' => [[
                'id' => (string) Str::uuid(),
                'displayName' => 'Old pilot: MFA for IT',
                'state' => 'disabled',
                'conditions' => [
                    'users' => ['includeUsers' => [$adminIds[0]], 'excludeUsers' => [], 'includeRoles' => []],
                    'applications' => ['includeApplications' => ['All']],
                    'clientAppTypes' => ['all'],
                ],
                'grantControls' => ['operator' => 'OR', 'builtInControls' => ['mfa']],
            ]],
            'authorizationPolicy' => [
                'id' => 'authorizationPolicy',
                'allowInvitesFrom' => 'everyone',
                'guestUserRoleId' => 'a0b1b346-4d3e-4e8b-98f8-753987be4970',
                'defaultUserRolePermissions' => [
                    'allowedToCreateApps' => true,
                    'allowedToCreateSecurityGroups' => true,
                    'allowedToCreateTenants' => true,
                    'allowedToReadOtherUsers' => true,
                    'permissionGrantPoliciesAssigned' => ['ManagePermissionGrantsForSelf.microsoft-user-default-legacy', 'ManagePermissionGrantsForOwnedResource.microsoft-dynamically-managed-permissions-for-team'],
                ],
            ],
            'users' => $users,
            'registration' => $registration,
            'directoryRoles' => [
                ['id' => (string) Str::uuid(), 'displayName' => 'Global Administrator', 'roleTemplateId' => Roles::GLOBAL_ADMIN,
                    'members' => array_map(fn ($id) => ['@odata.type' => '#microsoft.graph.user'] + $member($id), $adminIds)],
                ['id' => (string) Str::uuid(), 'displayName' => 'Exchange Administrator', 'roleTemplateId' => '29232cdf-9323-42fd-ade2-1d097af3e4de',
                    'members' => [['@odata.type' => '#microsoft.graph.user'] + $member($registration[5]['id'])]],
            ],
            'domains' => [
                ['id' => $domain, 'isVerified' => true, 'isDefault' => true, 'authenticationType' => 'Managed', 'passwordValidityPeriodInDays' => 90, 'supportedServices' => ['Email', 'OfficeCommunicationsOnline']],
                ['id' => 'brightwaterdental.onmicrosoft.com', 'isVerified' => true, 'isDefault' => false, 'authenticationType' => 'Managed', 'passwordValidityPeriodInDays' => 2147483647, 'supportedServices' => ['Email']],
            ],
            'authenticationMethodsPolicy' => [
                'id' => 'authenticationMethodsPolicy',
                'authenticationMethodConfigurations' => [
                    ['@odata.type' => '#microsoft.graph.microsoftAuthenticatorAuthenticationMethodConfiguration', 'id' => 'MicrosoftAuthenticator', 'state' => 'enabled'],
                    ['@odata.type' => '#microsoft.graph.smsAuthenticationMethodConfiguration', 'id' => 'Sms', 'state' => 'enabled'],
                    ['@odata.type' => '#microsoft.graph.voiceAuthenticationMethodConfiguration', 'id' => 'Voice', 'state' => 'enabled'],
                    ['@odata.type' => '#microsoft.graph.fido2AuthenticationMethodConfiguration', 'id' => 'Fido2', 'state' => 'disabled'],
                ],
            ],
            'adminConsentRequestPolicy' => ['isEnabled' => false, 'notifyReviewers' => false, 'remindersEnabled' => false, 'requestDurationInDays' => 0, 'reviewers' => []],
            'sharepointSettings' => ['sharingCapability' => 'externalUserAndGuestSharing', 'isResharingByExternalUsersEnabled' => true],
            'compliancePolicies' => [],
            'managedDevices' => [
                ['id' => (string) Str::uuid(), 'deviceName' => 'FRONTDESK-PC', 'operatingSystem' => 'Windows', 'complianceState' => 'noncompliant', 'userPrincipalName' => "frontdesk@{$domain}"],
                ['id' => (string) Str::uuid(), 'deviceName' => 'OP1-LAPTOP', 'operatingSystem' => 'Windows', 'complianceState' => 'compliant', 'userPrincipalName' => "casey.nguyen@{$domain}"],
                ['id' => (string) Str::uuid(), 'deviceName' => 'Jordan iPhone', 'operatingSystem' => 'iOS', 'complianceState' => 'noncompliant', 'userPrincipalName' => "jordan.lee@{$domain}"],
            ],
            'applications' => [
                ['id' => (string) Str::uuid(), 'appId' => (string) Str::uuid(), 'displayName' => 'Practice Mgmt Sync',
                    'passwordCredentials' => [['displayName' => 'prod', 'endDateTime' => $now->copy()->subDays(12)->toIso8601ZuluString()]], 'keyCredentials' => []],
                ['id' => (string) Str::uuid(), 'appId' => (string) Str::uuid(), 'displayName' => 'Backup Connector',
                    'passwordCredentials' => [['displayName' => 'secret', 'endDateTime' => $now->copy()->addDays(18)->toIso8601ZuluString()]], 'keyCredentials' => []],
                ['id' => (string) Str::uuid(), 'appId' => (string) Str::uuid(), 'displayName' => 'Website Forms',
                    'passwordCredentials' => [['displayName' => 'secret', 'endDateTime' => $now->copy()->addDays(500)->toIso8601ZuluString()]], 'keyCredentials' => []],
            ],
            'secureScore' => ['currentScore' => 31.5, 'maxScore' => 98.0, 'createdDateTime' => $now->toIso8601ZuluString()],
            'dns' => [
                $domain => ['txt' => ['v=spf1 include:spf.protection.outlook.com ~all'], 'dmarc' => ['v=DMARC1; p=none; rua=mailto:dmarc@'.$domain], 'dkim' => false],
                'brightwaterdental.onmicrosoft.com' => ['txt' => [], 'dmarc' => [], 'dkim' => false],
            ],
        ];
    }

    /**
     * Revert up to two hardened settings to an insecure value, imitating an admin
     * or a script undoing work. Returns human readable descriptions of what changed.
     */
    public static function simulateDrift(Tenant $tenant): array
    {
        $s = $tenant->demo_state;
        $candidates = [];

        if (($s['authorizationPolicy']['defaultUserRolePermissions']['allowedToCreateApps'] ?? true) === false) {
            $candidates[] = function (&$s) {
                $s['authorizationPolicy']['defaultUserRolePermissions']['allowedToCreateApps'] = true;

                return 'Users were allowed to register applications again';
            };
        }
        if (in_array($s['authorizationPolicy']['allowInvitesFrom'] ?? '', ['adminsAndGuestInviters', 'none'], true)) {
            $candidates[] = function (&$s) {
                $s['authorizationPolicy']['allowInvitesFrom'] = 'everyone';

                return 'Guest invitations were opened to everyone';
            };
        }
        if (($s['sharepointSettings']['sharingCapability'] ?? '') !== 'externalUserAndGuestSharing') {
            $candidates[] = function (&$s) {
                $s['sharepointSettings']['sharingCapability'] = 'externalUserAndGuestSharing';

                return 'SharePoint "Anyone" links were re-enabled';
            };
        }
        foreach ($s['authenticationMethodsPolicy']['authenticationMethodConfigurations'] as $i => $cfg) {
            if ($cfg['id'] === 'Sms' && $cfg['state'] === 'disabled') {
                $candidates[] = function (&$s) use ($i) {
                    $s['authenticationMethodsPolicy']['authenticationMethodConfigurations'][$i]['state'] = 'enabled';

                    return 'SMS sign-in was re-enabled';
                };
            }
        }
        foreach ($s['conditionalAccess'] as $i => $p) {
            if ($p['state'] === 'enabled') {
                $candidates[] = function (&$s) use ($i) {
                    $s['conditionalAccess'][$i]['state'] = 'disabled';

                    return 'Conditional Access policy "'.$s['conditionalAccess'][$i]['displayName'].'" was disabled';
                };
            }
        }
        if ($s['securityDefaults']['isEnabled'] ?? false) {
            $candidates[] = function (&$s) {
                $s['securityDefaults']['isEnabled'] = false;

                return 'Security defaults were turned off';
            };
        }

        if (! $candidates) {
            return [];
        }

        shuffle($candidates);
        $changes = [];
        foreach (array_slice($candidates, 0, 2) as $apply) {
            $changes[] = $apply($s);
        }
        $tenant->demo_state = $s;
        $tenant->save();

        return $changes;
    }
}
