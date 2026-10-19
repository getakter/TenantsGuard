<?php

namespace App\Support;

use App\Models\Setting;

/**
 * Capability-based permission model for the TenantsGuard multi-tenant app.
 *
 * Permissions are grouped into small capabilities in three tiers:
 *   read        - see configuration and reports (no change possible)
 *   write       - change configuration of one workload
 *   privileged  - Tier 0: can change who has admin rights or how people authenticate, or wipe devices
 *
 * Three layers decide what TenantsGuard can do in a client:
 *   1. App plan        - capabilities the app registration may request at all (Global Administrator, Settings).
 *   2. Client profile  - capabilities TenantsGuard may use in this client (policy, enforced before every write).
 *   3. Client grant    - what the client actually granted (read from the access token's roles claim).
 */
final class Access
{
    public const GRAPH = '00000003-0000-0000-c000-000000000000';

    public const EXO = '00000002-0000-0ff1-ce00-000000000000';

    /** Fallback app role IDs on the Office 365 Exchange Online resource. */
    public const EXO_ROLE_IDS = ['Exchange.ManageAsApp' => 'dc50a0fb-09a3-484d-be87-e023b12c6440'];

    public const TIERS = [
        'read' => ['Read', 's-info', 'Reads configuration and reports. Cannot change anything.'],
        'write' => ['Write', 's-manual', 'Changes the configuration of one workload.'],
        'privileged' => ['Privileged (Tier 0)', 's-fail', 'Can change admin roles or authentication methods, or wipe devices. Grant only where needed, ideally for a limited time.'],
    ];

    /** key => [name, tier, description, graph app roles, exchange app roles, features, required] */
    public const CAPABILITIES = [
        'monitor' => ['Security monitoring', 'read', 'Posture scans, Secure Score, dashboards, reports, Copilot readiness and Purview read-outs.',
            ['Organization.Read.All', 'Directory.Read.All', 'Policy.Read.All', 'AuditLog.Read.All', 'RoleManagement.Read.Directory', 'Domain.Read.All', 'Application.Read.All',
                'SecurityEvents.Read.All', 'SharePointTenantSettings.Read.All', 'Reports.Read.All', 'IdentityRiskyUser.Read.All', 'InformationProtectionPolicy.Read.All'],
            [], ['Security posture and scans', 'Secure Score', 'Client dashboard', 'Users, groups and guests', 'Copilot readiness', 'Reports'], true],
        'intune_read' => ['Intune read', 'read', 'Device compliance, Intune policies, enrollment prerequisites and app protection.',
            ['DeviceManagementConfiguration.Read.All', 'DeviceManagementManagedDevices.Read.All', 'DeviceManagementApps.Read.All', 'DeviceManagementServiceConfig.Read.All'],
            [], ['Intune and devices (status)', 'Device tests'], false],
        'threat_signals' => ['Threat alerts and service health', 'read', 'Microsoft Defender and Entra security alerts, and Microsoft 365 service incidents, for security monitoring.',
            ['SecurityAlert.Read.All', 'ServiceHealth.Read.All'],
            [], ['Security alerts: Defender alerts', 'Security alerts: service incidents'], false],
        'security_config' => ['Security configuration', 'write', 'Conditional Access (created in report-only), security defaults, authentication methods, consent and guest settings, password expiry, SharePoint sharing.',
            ['Policy.ReadWrite.ConditionalAccess', 'Policy.ReadWrite.Authorization', 'Policy.ReadWrite.AuthenticationMethod', 'Policy.ReadWrite.ConsentRequest',
                'Policy.ReadWrite.SecurityDefaults', 'Domain.ReadWrite.All', 'SharePointTenantSettings.ReadWrite.All'],
            [], ['Quick fixes', 'Microsoft 365 baselines', 'Control plan rollout'], false],
        'intune_manage' => ['Intune management', 'write', 'Create and update Intune compliance, configuration, update, Autopilot and app protection policies.',
            ['DeviceManagementConfiguration.ReadWrite.All', 'DeviceManagementApps.ReadWrite.All', 'DeviceManagementServiceConfig.ReadWrite.All'],
            [], ['Intune bundle deployment'], false],
        'groups' => ['Groups', 'write', 'Create pilot and assignment groups, change membership, ownership and privacy.',
            ['Group.ReadWrite.All'], [], ['Pilot groups (Intune, Purview, Copilot)', 'Offboarding: group removal', 'Make public groups private'], false],
        'users' => ['User lifecycle', 'write', 'Create, update and disable users, revoke sessions, remove guests, set out-of-office replies and remove inbox rules.',
            ['User.ReadWrite.All', 'MailboxSettings.ReadWrite'], [], ['Onboarding', 'Offboarding', 'Guest removal'], false],
        'licensing' => ['Licence assignment', 'write', 'Assign and remove licences. Narrower than user lifecycle when only licences are needed.',
            ['LicenseAssignment.ReadWrite.All'], [], ['Copilot licences', 'Onboarding licences'], false],
        'records' => ['Records management', 'write', 'Create retention labels from an approved retention schedule.',
            ['RecordsManagement.ReadWrite.All'], [], ['Purview retention labels'], false],
        'privileged' => ['Privileged operations', 'privileged', 'Remove admin roles, issue Temporary Access Passes, remove MFA methods, retire or wipe devices.',
            ['RoleManagement.ReadWrite.Directory', 'UserAuthenticationMethod.ReadWrite.All', 'DeviceManagementManagedDevices.PrivilegedOperations.All'],
            [], ['Offboarding: remove roles, MFA methods, wipe devices', 'Onboarding: Temporary Access Pass'], false],
        'exchange' => ['Exchange Online', 'privileged', 'Exchange Online PowerShell as the app (for approved scripts). Also needs the Exchange Administrator role on the app in the client.',
            [], ['Exchange.ManageAsApp'], ['PowerShell scripts with Exchange Online'], false],
    ];

    public const PRESETS = [
        'monitor' => ['Read-only monitoring', 'Scans, dashboards and reports. TenantsGuard cannot change anything.', ['monitor', 'intune_read', 'threat_signals']],
        'standard' => ['Standard management', 'Monitoring plus security configuration, Intune, groups, user lifecycle, licences and records. No Tier 0 rights.', ['monitor', 'intune_read', 'threat_signals', 'security_config', 'intune_manage', 'groups', 'users', 'licensing', 'records']],
        'full' => ['Full management', 'Everything in the app plan, including privileged operations.', null],
    ];

    public static function name(string $cap): string
    {
        return self::CAPABILITIES[$cap][0] ?? $cap;
    }

    public static function tier(string $cap): string
    {
        return self::CAPABILITIES[$cap][1] ?? 'write';
    }

    /** Capabilities the app registration includes (Global Administrator choice). */
    public static function appPlan(): array
    {
        $saved = json_decode((string) Setting::find('app_capabilities')?->value, true);
        $plan = is_array($saved) ? $saved : array_values(array_diff(array_keys(self::CAPABILITIES), ['exchange']));

        return array_values(array_unique(array_merge(['monitor'], array_intersect($plan, array_keys(self::CAPABILITIES)))));
    }

    /** @return array{graph: string[], exo: string[]} */
    public static function roles(array $caps): array
    {
        $out = ['graph' => [], 'exo' => []];
        foreach ($caps as $c) {
            $out['graph'] = array_merge($out['graph'], self::CAPABILITIES[$c][3] ?? []);
            $out['exo'] = array_merge($out['exo'], self::CAPABILITIES[$c][4] ?? []);
        }

        return ['graph' => array_values(array_unique($out['graph'])), 'exo' => array_values(array_unique($out['exo']))];
    }

    public static function allRoles(array $caps): array
    {
        $r = self::roles($caps);

        return array_merge($r['graph'], $r['exo']);
    }

    /**
     * Capabilities that permit a Microsoft Graph write. Any one of them is enough.
     * Unknown paths return [] and are denied (default deny).
     */
    public static function writeCapabilities(string $method, string $path): array
    {
        $p = preg_replace('#^/(beta|v1\.0)(?=/)#', '', '/'.ltrim((string) strtok($path, '?'), '/'));

        return match (true) {
            (bool) preg_match('#^/deviceManagement/managedDevices/[^/]+/(wipe|retire)$#', $p) => ['privileged'],
            str_starts_with($p, '/deviceManagement') || str_starts_with($p, '/deviceAppManagement') => ['intune_manage'],
            str_starts_with($p, '/identity/conditionalAccess') || str_starts_with($p, '/policies') || str_starts_with($p, '/domains')
                || str_starts_with($p, '/admin/sharepoint') || str_starts_with($p, '/groupSettings') => ['security_config'],
            str_starts_with($p, '/directoryRoles') || str_starts_with($p, '/roleManagement') => ['privileged'],
            (bool) preg_match('#^/users/[^/]+/authentication(/|$)#', $p) => ['privileged'],
            (bool) preg_match('#^/(users|groups)/[^/]+/assignLicense$#', $p) => ['licensing', 'users'],
            str_starts_with($p, '/users') => ['users'],
            str_starts_with($p, '/groups') => ['groups'],
            str_starts_with($p, '/security/labels') => ['records'],
            default => [],
        };
    }
}
