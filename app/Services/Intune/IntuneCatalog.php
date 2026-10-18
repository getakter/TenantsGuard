<?php

namespace App\Services\Intune;

use App\Models\Tenant;
use Carbon\Carbon;

/**
 * Library of deployable Intune / Zero Trust templates for Windows, iOS/iPadOS and Android.
 *
 * Each template:
 *  key, platform (windows|ios|android|cross), category, name, summary, rationale
 *  kind: compliance | configuration | mam_ios | mam_android | feature_update | autopilot | ca | manual
 *  odata: Graph @odata.type of the created object (where applicable)
 *  license: required licences (intune, p1, mde)
 *  ownership: corporate and/or byod
 *  impact: end-user impact low|medium|high
 *  zt: Zero Trust pillars
 *  frameworks: tag => reference
 *  params: tunable settings [key => [label, type(int|select|bool|text), default, options?, min?, max?, help?]]
 *  targets: allowed assignment targets; default_target
 *  build(params, tenant): Graph payload (without displayName/description)
 *  verify(context): for manual prerequisites, ['ok' => ?bool, 'detail' => string]
 *
 * Payloads follow the Microsoft Graph Intune schema (v1.0 unless noted). Framework references
 * are best-effort mappings; validate them against the framework versions you attest to.
 */
final class IntuneCatalog
{
    public const PREFIX = 'TG - ';

    public const PLATFORMS = ['windows' => 'Windows', 'ios' => 'iOS / iPadOS', 'android' => 'Android', 'cross' => 'Cross-platform'];

    public const CATEGORIES = ['Prerequisite', 'Enrollment', 'Compliance', 'Configuration', 'Endpoint security', 'Update management', 'App protection', 'Conditional Access'];

    public const FRAMEWORKS = [
        'nist80053' => 'NIST SP 800-53 r5',
        'nistcsf' => 'NIST CSF 2.0',
        'cis' => 'CIS Benchmarks',
        'cisv8' => 'CIS Controls v8.1',
        'hipaa' => 'HIPAA Security Rule',
        'iso27001' => 'ISO/IEC 27001:2022',
        'pci' => 'PCI DSS v4.0',
        'e8' => 'Essential Eight',
        'ce' => 'Cyber Essentials',
    ];

    public const ZT_PILLARS = ['Identities', 'Devices', 'Applications', 'Data', 'Networks'];

    public const TARGETS = [
        'pilot' => 'Pilot group (TenantsGuard Pilot)',
        'all_users' => 'All users',
        'all_devices' => 'All devices',
        'group' => 'Named group',
        'none' => 'Create without assignment',
    ];

    public const LICENSES = ['intune' => 'Microsoft Intune Plan 1', 'p1' => 'Microsoft Entra ID P1', 'mde' => 'Microsoft Defender for Endpoint'];

    private static ?array $cache = null;

    /** @return array<string, array> */
    public static function all(): array
    {
        return self::$cache ??= collect(self::definitions())->keyBy('key')->all();
    }

    public static function find(string $key): ?array
    {
        return self::all()[$key] ?? null;
    }

    /** Merge stored params over defaults, coercing types. */
    public static function params(array $template, array $values = []): array
    {
        $out = [];
        foreach ($template['params'] ?? [] as $key => $def) {
            $v = $values[$key] ?? $def['default'];
            $out[$key] = match ($def['type']) {
                'int' => max($def['min'] ?? PHP_INT_MIN, min($def['max'] ?? PHP_INT_MAX, (int) $v)),
                'bool' => filter_var($v, FILTER_VALIDATE_BOOLEAN),
                'select' => array_key_exists((string) $v, $def['options']) ? (string) $v : $def['default'],
                default => trim((string) $v),
            };
        }

        return $out;
    }

    // ------------------------------------------------------------------ shared builders

    private static function scheduledBlock(int $graceHours): array
    {
        return [[
            'ruleName' => 'PasswordRequired',
            'scheduledActionConfigurations' => [[
                'actionType' => 'block',
                'gracePeriodHours' => $graceHours,
                'notificationTemplateId' => '',
                'notificationMessageCCList' => [],
            ]],
        ]];
    }

    private static function threat(string $level): array
    {
        return $level === 'none'
            ? ['deviceThreatProtectionEnabled' => false]
            : ['deviceThreatProtectionEnabled' => true, 'deviceThreatProtectionRequiredSecurityLevel' => $level];
    }

    /** Windows custom profile from OMA-URI settings. */
    private static function oma(array $settings): array
    {
        return [
            '@odata.type' => '#microsoft.graph.windows10CustomConfiguration',
            'omaSettings' => array_map(fn ($s) => [
                '@odata.type' => '#microsoft.graph.omaSetting'.ucfirst($s[2]),
                'displayName' => $s[0],
                'omaUri' => $s[1],
                'value' => $s[3],
            ], $settings),
        ];
    }

    /**
     * Microsoft App Protection data protection framework.
     * Level 1 = Enterprise basic, Level 2 = Enterprise enhanced, Level 3 = Enterprise high.
     */
    private static function appProtection(string $platform, array $p): array
    {
        $level = (int) $p['level'];
        $common = [
            'periodOfflineBeforeAccessCheck' => 'PT720M',
            'periodOnlineBeforeAccessCheck' => 'PT30M',
            'allowedInboundDataTransferSources' => $level >= 3 ? 'managedApps' : 'allApps',
            'allowedOutboundDataTransferDestinations' => $level >= 2 ? 'managedApps' : 'allApps',
            'allowedOutboundClipboardSharingLevel' => $level >= 2 ? 'managedAppsWithPasteIn' : 'allApps',
            'organizationalCredentialsRequired' => false,
            'dataBackupBlocked' => $level >= 2,
            'deviceComplianceRequired' => true,
            'managedBrowserToOpenLinksRequired' => $level >= 2,
            'managedBrowser' => $level >= 2 ? 'microsoftEdge' : 'notConfigured',
            'saveAsBlocked' => $level >= 2,
            'allowedDataStorageLocations' => $level >= 2 ? ['oneDriveForBusiness', 'sharePoint'] : [],
            'periodOfflineBeforeWipeIsEnforced' => 'P'.$p['offline_wipe_days'].'D',
            'pinRequired' => true,
            'maximumPinRetries' => 5,
            'simplePinBlocked' => $level >= 2,
            'minimumPinLength' => $level >= 2 ? max(6, $p['pin_length']) : $p['pin_length'],
            'pinCharacterSet' => 'numeric',
            'periodBeforePinReset' => 'PT0S',
            'contactSyncBlocked' => $level >= 2,
            'printBlocked' => $level >= 3,
            'fingerprintBlocked' => false,
            'disableAppPinIfDevicePinIsSet' => false,
            'minimumRequiredOsVersion' => $p['min_os'],
            'appActionIfDeviceComplianceRequired' => $level >= 3 ? 'wipe' : 'block',
            'appActionIfMaximumPinRetriesExceeded' => 'block',
        ];

        return $platform === 'ios'
            ? ['@odata.type' => '#microsoft.graph.iosManagedAppProtection', 'appDataEncryptionType' => 'whenDeviceLocked', 'faceIdBlocked' => false] + $common
            : ['@odata.type' => '#microsoft.graph.androidManagedAppProtection', 'encryptAppData' => true, 'screenCaptureBlocked' => $level >= 2, 'disableAppEncryptionIfDeviceEncryptionIsEnabled' => false] + $common;
    }

    private static function caUsers(array $ctx): array
    {
        return isset($ctx['group_id'])
            ? ['includeGroups' => [$ctx['group_id']], 'excludeUsers' => []]
            : ['includeUsers' => ['All'], 'excludeUsers' => []];
    }

    // ------------------------------------------------------------------ catalog

    private static function definitions(): array
    {
        $winOs = ['label' => 'Minimum Windows build', 'type' => 'text', 'default' => '10.0.22631', 'help' => '10.0.22631 = Windows 11 23H2, 10.0.26100 = 24H2, 10.0.19045 = Windows 10 22H2'];
        $grace = ['label' => 'Grace period before non-compliant (hours)', 'type' => 'int', 'default' => 24, 'min' => 0, 'max' => 720];
        $mde = ['label' => 'Require Defender for Endpoint risk score', 'type' => 'select', 'default' => 'none',
            'options' => ['none' => 'Not required', 'secured' => 'Clear', 'low' => 'Low or below', 'medium' => 'Medium or below', 'high' => 'High or below'],
            'help' => 'Needs Defender for Endpoint and its Intune connector.'];
        $mamParams = [
            'level' => ['label' => 'Data protection level', 'type' => 'select', 'default' => '2',
                'options' => ['1' => 'Level 1: Enterprise basic', '2' => 'Level 2: Enterprise enhanced (recommended)', '3' => 'Level 3: Enterprise high (regulated data)']],
            'pin_length' => ['label' => 'Minimum app PIN length', 'type' => 'int', 'default' => 6, 'min' => 4, 'max' => 8],
            'offline_wipe_days' => ['label' => 'Wipe org data after offline (days)', 'type' => 'int', 'default' => 90, 'min' => 1, 'max' => 90],
            'apps' => ['label' => 'Protected apps', 'type' => 'select', 'default' => 'allCoreMicrosoftApps',
                'options' => ['allCoreMicrosoftApps' => 'Core Microsoft apps (Outlook, Teams, Office, OneDrive, Edge…)', 'allMicrosoftApps' => 'All Microsoft apps', 'allApps' => 'All apps with the Intune SDK']],
        ];
        $mamFrameworks = ['nist80053' => 'AC-4, AC-19, SC-28', 'nistcsf' => 'PR.DS-01, PR.DS-02', 'cisv8' => '3.3, 3.6, 3.12', 'hipaa' => '§164.312(a)(1), §164.312(a)(2)(iv), §164.312(c)(1)', 'iso27001' => 'A.8.1, A.8.12', 'pci' => '3.4, 8.3', 'ce' => 'Secure configuration'];

        return [
            // ======================================================= PREREQUISITES
            [
                'key' => 'PRE-01', 'platform' => 'ios', 'category' => 'Prerequisite', 'kind' => 'manual',
                'name' => 'Apple MDM push certificate',
                'summary' => 'Required before any iOS/iPadOS device can enroll.',
                'rationale' => 'Without a valid APNs certificate, Intune cannot manage Apple devices. An expired certificate forces re-enrollment of every device.',
                'license' => ['intune'], 'ownership' => ['corporate', 'byod'], 'impact' => 'low', 'zt' => ['Devices'],
                'frameworks' => ['nistcsf' => 'ID.AM-01'],
                'steps' => ['Intune admin center > Devices > iOS/iPadOS > Enrollment > Apple MDM Push certificate', 'Download the CSR, create the certificate at identity.apple.com with a shared company Apple ID, and upload it.', 'Record the Apple ID and the renewal date (yearly). Renew the same certificate, never create a new one.'],
                'verify' => function (IntuneContext $c) {
                    $cert = $c->tryGet('/deviceManagement/applePushNotificationCertificate');
                    if (! $cert || empty($cert['expirationDateTime'])) {
                        return ['ok' => false, 'detail' => 'No Apple MDM push certificate is configured.'];
                    }
                    $exp = Carbon::parse($cert['expirationDateTime']);

                    return $exp->isPast()
                        ? ['ok' => false, 'detail' => 'Certificate expired '.$exp->toFormattedDateString().'.']
                        : ['ok' => true, 'detail' => 'Valid until '.$exp->toFormattedDateString().($exp->lt(now()->addDays(30)) ? ' (renew soon)' : '').' · '.($cert['appleIdentifier'] ?? '')];
                },
            ],
            [
                'key' => 'PRE-02', 'platform' => 'android', 'category' => 'Prerequisite', 'kind' => 'manual',
                'name' => 'Managed Google Play connection',
                'summary' => 'Required for Android Enterprise (work profile and fully managed).',
                'rationale' => 'Android Enterprise is the only supported, secure Android management mode. Device administrator is deprecated.',
                'license' => ['intune'], 'ownership' => ['corporate', 'byod'], 'impact' => 'low', 'zt' => ['Devices'],
                'frameworks' => ['nistcsf' => 'ID.AM-01'],
                'steps' => ['Intune admin center > Devices > Android > Enrollment > Managed Google Play', 'Sign in with a company Google account (not personal) and accept the agreement.'],
                'verify' => function (IntuneContext $c) {
                    $s = $c->tryGet('/deviceManagement/androidManagedStoreAccountEnterpriseSettings');

                    return ($s['bindStatus'] ?? null) === 'boundAndValidated'
                        ? ['ok' => true, 'detail' => 'Bound to '.($s['ownerUserPrincipalName'] ?? 'a Google account').'.']
                        : ['ok' => false, 'detail' => 'Not connected ('.($s['bindStatus'] ?? 'unknown').').'];
                },
            ],
            [
                'key' => 'PRE-03', 'platform' => 'windows', 'category' => 'Prerequisite', 'kind' => 'manual',
                'name' => 'Windows automatic MDM enrollment',
                'summary' => 'Entra-joined Windows devices enroll into Intune automatically.',
                'rationale' => 'Automatic enrollment ensures every corporate Windows device is managed and evaluated for compliance.',
                'license' => ['intune', 'p1'], 'ownership' => ['corporate'], 'impact' => 'low', 'zt' => ['Devices'],
                'frameworks' => ['cisv8' => '1.1', 'nistcsf' => 'ID.AM-01'],
                'steps' => ['Intune admin center > Devices > Windows > Enrollment > Automatic Enrollment', 'Set MDM user scope to All (or a pilot group first) and MAM user scope to None.'],
                'verify' => function (IntuneContext $c) {
                    $p = $c->tryGetAll('/beta/policies/mobileDeviceManagementPolicies');
                    $intune = collect($p)->first(fn ($x) => str_contains(strtolower($x['displayName'] ?? ''), 'intune'));
                    if ($intune === null) {
                        return ['ok' => null, 'detail' => 'Could not read the MDM user scope. Verify it in the portal.'];
                    }

                    return in_array($intune['appliesTo'] ?? 'none', ['all', 'selected'], true)
                        ? ['ok' => true, 'detail' => 'MDM user scope: '.$intune['appliesTo'].'.']
                        : ['ok' => false, 'detail' => 'MDM user scope is None.'];
                },
            ],
            [
                'key' => 'PRE-04', 'platform' => 'windows', 'category' => 'Prerequisite', 'kind' => 'manual',
                'name' => 'Microsoft Defender for Endpoint connector',
                'summary' => 'Lets compliance policies use the device risk score.',
                'rationale' => 'Zero Trust needs real-time device health signals: a compromised device should lose access automatically.',
                'license' => ['intune', 'mde'], 'ownership' => ['corporate'], 'impact' => 'low', 'zt' => ['Devices'],
                'frameworks' => ['nist80053' => 'SI-4', 'nistcsf' => 'DE.CM-09', 'cisv8' => '10.1, 13.2'],
                'steps' => ['Defender portal > Settings > Endpoints > Advanced features > Microsoft Intune connection: On', 'Intune admin center > Endpoint security > Microsoft Defender for Endpoint: connect Windows (and iOS/Android if licensed)'],
                'verify' => function (IntuneContext $c) {
                    $conn = collect($c->tryGetAll('/deviceManagement/mobileThreatDefenseConnectors'))
                        ->first(fn ($x) => in_array($x['partnerState'] ?? '', ['enabled', 'available'], true));

                    return $conn
                        ? ['ok' => true, 'detail' => 'Threat defense connector is '.$conn['partnerState'].'.']
                        : ['ok' => false, 'detail' => 'No enabled Defender connector.'];
                },
            ],
            [
                'key' => 'PRE-05', 'platform' => 'windows', 'category' => 'Prerequisite', 'kind' => 'manual',
                'name' => 'Entra Local Administrator Password Solution (LAPS) enabled',
                'summary' => 'Tenant switch required before the Windows LAPS policy works.',
                'rationale' => 'Unique, rotated local admin passwords stop lateral movement with a shared local admin password.',
                'license' => ['intune'], 'ownership' => ['corporate'], 'impact' => 'low', 'zt' => ['Identities', 'Devices'],
                'frameworks' => ['e8' => 'Restrict administrative privileges'],
                'steps' => ['Entra admin center > Devices > Device settings > Enable Microsoft Entra Local Administrator Password Solution (LAPS): Yes'],
                'verify' => function (IntuneContext $c) {
                    $p = $c->tryGet('/beta/policies/deviceRegistrationPolicy');
                    if (! $p) {
                        return ['ok' => null, 'detail' => 'Could not read device registration settings. Verify in the portal.'];
                    }

                    return ($p['localAdminPassword']['isEnabled'] ?? false)
                        ? ['ok' => true, 'detail' => 'LAPS is enabled in Entra.']
                        : ['ok' => false, 'detail' => 'LAPS is disabled in Entra device settings.'];
                },
            ],
            [
                'key' => 'ENR-01', 'platform' => 'cross', 'category' => 'Enrollment', 'kind' => 'manual',
                'name' => 'Enrollment restrictions for personal devices',
                'summary' => 'Decide which platforms may enroll and block personally owned full enrollment where BYOD uses app protection.',
                'rationale' => 'Zero Trust BYOD: personal phones get app protection (or a work profile) instead of full device control; personal Windows PCs should not join the corporate MDM.',
                'license' => ['intune'], 'ownership' => ['corporate', 'byod'], 'impact' => 'medium', 'zt' => ['Devices'],
                'frameworks' => ['nist80053' => 'AC-19, CM-8', 'cisv8' => '1.1, 2.3', 'iso27001' => 'A.8.1'],
                'steps' => ['Intune admin center > Devices > Enrollment > Device platform restriction', 'Windows: block personally owned. Android: allow Android Enterprise work profile, block Android device administrator.', 'iOS/iPadOS: allow, and choose whether personally owned devices may enroll (User Enrollment) or use app protection only.', 'Enrollment > Device limit restriction: 5 or fewer per user.'],
                'verify' => null,
            ],

            // ======================================================= WINDOWS
            [
                'key' => 'WIN-ENR-01', 'platform' => 'windows', 'category' => 'Enrollment', 'kind' => 'autopilot',
                'name' => 'Windows Autopilot: user-driven, Entra joined, standard user',
                'summary' => 'Zero-touch provisioning with no local admin rights for the user.',
                'rationale' => 'Devices ship straight to users and arrive managed, compliant and without local admin, which removes imaging and closes a major privilege escalation path.',
                'odata' => '#microsoft.graph.azureADWindowsAutopilotDeploymentProfile',
                'license' => ['intune', 'p1'], 'ownership' => ['corporate'], 'impact' => 'low', 'zt' => ['Devices', 'Identities'],
                'frameworks' => ['nist80053' => 'CM-2, CM-8, AC-6', 'nistcsf' => 'ID.AM-01, PR.PS-01', 'cisv8' => '1.1, 4.1, 5.4', 'iso27001' => 'A.5.9, A.8.1', 'e8' => 'Restrict administrative privileges'],
                'params' => [
                    'name_template' => ['label' => 'Device name template', 'type' => 'text', 'default' => 'CORP-%SERIAL%', 'help' => 'Max 15 characters after expansion. %SERIAL% or %RAND:x%.'],
                    'user_type' => ['label' => 'User account type', 'type' => 'select', 'default' => 'standard', 'options' => ['standard' => 'Standard user (recommended)', 'administrator' => 'Administrator']],
                    'pre_provision' => ['label' => 'Allow pre-provisioning (white glove)', 'type' => 'bool', 'default' => true],
                ],
                'targets' => ['all_devices', 'group', 'pilot', 'none'], 'default_target' => 'all_devices',
                'build' => fn (array $p) => [
                    '@odata.type' => '#microsoft.graph.azureADWindowsAutopilotDeploymentProfile',
                    'language' => 'os-default',
                    'deviceNameTemplate' => $p['name_template'],
                    'deviceType' => 'windowsPc',
                    'enableWhiteGlove' => $p['pre_provision'],
                    'extractHardwareHash' => true,
                    'outOfBoxExperienceSettings' => [
                        'hidePrivacySettings' => true,
                        'hideEULA' => true,
                        'userType' => $p['user_type'],
                        'deviceUsageType' => 'singleUser',
                        'skipKeyboardSelectionPage' => true,
                        'hideEscapeLink' => true,
                    ],
                ],
            ],
            [
                'key' => 'WIN-CMP-01', 'platform' => 'windows', 'category' => 'Compliance', 'kind' => 'compliance',
                'name' => 'Windows compliance: device health and security',
                'summary' => 'BitLocker, Secure Boot, code integrity, TPM, firewall, Defender antivirus with real-time protection, minimum OS build.',
                'rationale' => 'The compliance verdict is the device signal Conditional Access uses to grant or block access, the core of Zero Trust device trust.',
                'odata' => '#microsoft.graph.windows10CompliancePolicy',
                'license' => ['intune'], 'ownership' => ['corporate'], 'impact' => 'medium', 'zt' => ['Devices'],
                'frameworks' => ['nist80053' => 'CM-6, SC-28, SI-2, SI-3, AC-19', 'nistcsf' => 'PR.PS-01, PR.PS-02, PR.DS-01', 'cis' => 'CIS Microsoft Intune for Windows 11 Benchmark', 'cisv8' => '3.6, 4.1, 7.3, 10.1', 'hipaa' => '§164.308(a)(5)(ii)(B), §164.312(a)(2)(iv)', 'iso27001' => 'A.8.1, A.8.7, A.8.8', 'pci' => '5.2, 6.3.3', 'e8' => 'Patch operating systems', 'ce' => 'Secure configuration, Malware protection, Security update management'],
                'params' => ['min_os' => $winOs, 'grace_hours' => $grace, 'mde_level' => $mde],
                'targets' => ['all_users', 'all_devices', 'pilot', 'group', 'none'], 'default_target' => 'pilot',
                'build' => fn (array $p) => [
                    '@odata.type' => '#microsoft.graph.windows10CompliancePolicy',
                    'passwordRequired' => false,
                    'osMinimumVersion' => $p['min_os'],
                    'bitLockerEnabled' => true,
                    'secureBootEnabled' => true,
                    'codeIntegrityEnabled' => true,
                    'storageRequireEncryption' => true,
                    'tpmRequired' => true,
                    'activeFirewallRequired' => true,
                    'defenderEnabled' => true,
                    'antivirusRequired' => true,
                    'antiSpywareRequired' => true,
                    'rtpEnabled' => true,
                    'signatureOutOfDate' => true,
                    'scheduledActionsForRule' => self::scheduledBlock($p['grace_hours']),
                ] + self::threat($p['mde_level']),
            ],
            [
                'key' => 'WIN-SEC-01', 'platform' => 'windows', 'category' => 'Endpoint security', 'kind' => 'configuration',
                'name' => 'Microsoft Defender Antivirus: next-generation protection',
                'summary' => 'Real-time, behaviour, cloud-delivered protection with high block level, PUA blocking, network protection and frequent signature updates.',
                'rationale' => 'Baseline malware defence aligned with the Microsoft security baseline. Cloud protection stops most new malware within seconds.',
                'odata' => '#microsoft.graph.windows10CustomConfiguration',
                'license' => ['intune'], 'ownership' => ['corporate'], 'impact' => 'low', 'zt' => ['Devices'],
                'frameworks' => ['nist80053' => 'SI-3, SI-4, SI-8', 'nistcsf' => 'DE.CM-09, PR.PS-01', 'cis' => 'CIS Windows 11 Benchmark: Microsoft Defender Antivirus', 'cisv8' => '10.1, 10.2, 10.7', 'hipaa' => '§164.308(a)(5)(ii)(B)', 'iso27001' => 'A.8.7', 'pci' => '5.2, 5.3', 'ce' => 'Malware protection'],
                'params' => [
                    'cloud_level' => ['label' => 'Cloud block level', 'type' => 'select', 'default' => '2', 'options' => ['2' => 'High (recommended)', '4' => 'High+', '6' => 'Zero tolerance']],
                    'pua' => ['label' => 'Potentially unwanted apps', 'type' => 'select', 'default' => '1', 'options' => ['1' => 'Block', '2' => 'Audit']],
                    'network_protection' => ['label' => 'Network protection', 'type' => 'select', 'default' => '1', 'options' => ['1' => 'Block', '2' => 'Audit']],
                ],
                'targets' => ['all_devices', 'pilot', 'group', 'none'], 'default_target' => 'pilot',
                'build' => fn (array $p) => self::oma([
                    ['Real-time monitoring', './Device/Vendor/MSFT/Policy/Config/Defender/AllowRealtimeMonitoring', 'integer', 1],
                    ['Behaviour monitoring', './Device/Vendor/MSFT/Policy/Config/Defender/AllowBehaviorMonitoring', 'integer', 1],
                    ['Scan downloads and attachments (IOAV)', './Device/Vendor/MSFT/Policy/Config/Defender/AllowIOAVProtection', 'integer', 1],
                    ['Script scanning', './Device/Vendor/MSFT/Policy/Config/Defender/AllowScriptScanning', 'integer', 1],
                    ['Archive scanning', './Device/Vendor/MSFT/Policy/Config/Defender/AllowArchiveScanning', 'integer', 1],
                    ['Cloud-delivered protection', './Device/Vendor/MSFT/Policy/Config/Defender/AllowCloudProtection', 'integer', 1],
                    ['Cloud block level', './Device/Vendor/MSFT/Policy/Config/Defender/CloudBlockLevel', 'integer', (int) $p['cloud_level']],
                    ['Cloud extended timeout (s)', './Device/Vendor/MSFT/Policy/Config/Defender/CloudExtendedTimeout', 'integer', 50],
                    ['Automatic sample submission (safe samples)', './Device/Vendor/MSFT/Policy/Config/Defender/SubmitSamplesConsent', 'integer', 1],
                    ['PUA protection', './Device/Vendor/MSFT/Policy/Config/Defender/PUAProtection', 'integer', (int) $p['pua']],
                    ['Network protection', './Device/Vendor/MSFT/Policy/Config/Defender/EnableNetworkProtection', 'integer', (int) $p['network_protection']],
                    ['Signature update interval (h)', './Device/Vendor/MSFT/Policy/Config/Defender/SignatureUpdateInterval', 'integer', 4],
                ]),
            ],
            [
                'key' => 'WIN-SEC-02', 'platform' => 'windows', 'category' => 'Endpoint security', 'kind' => 'configuration',
                'name' => 'BitLocker: XTS-AES 256 with TPM, keys escrowed to Entra',
                'summary' => 'Silent OS and fixed drive encryption, recovery passwords escrowed to Entra ID and rotated after use. Optional write block for unencrypted USB.',
                'rationale' => 'A lost or stolen laptop must not become a data breach. Encryption is the safe-harbour control for most breach notification laws, including HIPAA.',
                'odata' => '#microsoft.graph.windows10EndpointProtectionConfiguration',
                'license' => ['intune'], 'ownership' => ['corporate'], 'impact' => 'low', 'zt' => ['Data', 'Devices'],
                'frameworks' => ['nist80053' => 'SC-28, SC-28(1), MP-5', 'nistcsf' => 'PR.DS-01', 'cis' => 'CIS Windows 11 Benchmark: BitLocker Drive Encryption', 'cisv8' => '3.6, 3.9', 'hipaa' => '§164.312(a)(2)(iv), §164.310(d)(1)', 'iso27001' => 'A.8.24, A.7.10', 'pci' => '3.5.1', 'ce' => 'Secure configuration'],
                'params' => [
                    'block_usb_write' => ['label' => 'Block write access to unencrypted USB drives', 'type' => 'bool', 'default' => false, 'help' => 'Recommended for regulated data. Can disrupt users who rely on USB sticks.'],
                ],
                'targets' => ['all_devices', 'pilot', 'group', 'none'], 'default_target' => 'pilot',
                'build' => fn (array $p) => [
                    '@odata.type' => '#microsoft.graph.windows10EndpointProtectionConfiguration',
                    'bitLockerEncryptDevice' => true,
                    'bitLockerDisableWarningForOtherDiskEncryption' => true,
                    'bitLockerAllowStandardUserEncryption' => true,
                    'bitLockerRecoveryPasswordRotation' => 'enabledForAzureAd',
                    'bitLockerSystemDrivePolicy' => [
                        'encryptionMethod' => 'xtsAes256',
                        'startupAuthenticationRequired' => true,
                        'startupAuthenticationBlockWithoutTpmChip' => true,
                        'startupAuthenticationTpmUsage' => 'allowed',
                        'recoveryOptions' => [
                            'blockDataRecoveryAgent' => true,
                            'recoveryPasswordUsage' => 'allowed',
                            'recoveryKeyUsage' => 'allowed',
                            'hideRecoveryOptions' => true,
                            'enableRecoveryInformationSaveToStore' => true,
                            'recoveryInformationToStore' => 'passwordAndKey',
                            'enableBitLockerAfterRecoveryInformationToStore' => true,
                        ],
                    ],
                    'bitLockerFixedDrivePolicy' => ['encryptionMethod' => 'xtsAes256'],
                    'bitLockerRemovableDrivePolicy' => ['encryptionMethod' => 'aesCbc256', 'requireEncryptionForWriteAccess' => $p['block_usb_write']],
                ],
            ],
            [
                'key' => 'WIN-SEC-03', 'platform' => 'windows', 'category' => 'Endpoint security', 'kind' => 'configuration',
                'name' => 'Windows Firewall: enabled on all profiles',
                'summary' => 'Firewall on for domain, private and public networks. Unsolicited inbound traffic is blocked on public and private networks.',
                'rationale' => 'Host firewalls limit lateral movement and exposure on untrusted networks. In Zero Trust no network is trusted.',
                'odata' => '#microsoft.graph.windows10EndpointProtectionConfiguration',
                'license' => ['intune'], 'ownership' => ['corporate'], 'impact' => 'low', 'zt' => ['Networks', 'Devices'],
                'frameworks' => ['nist80053' => 'SC-7, SC-7(12)', 'nistcsf' => 'PR.IR-01', 'cis' => 'CIS Windows 11 Benchmark: Windows Defender Firewall', 'cisv8' => '4.4, 4.5', 'hipaa' => '§164.312(e)(1)', 'iso27001' => 'A.8.20', 'pci' => '1.5.1', 'ce' => 'Firewalls'],
                'params' => [],
                'targets' => ['all_devices', 'pilot', 'group', 'none'], 'default_target' => 'pilot',
                'build' => function () {
                    $profile = fn (bool $blockInbound) => [
                        'firewallEnabled' => 'allowed',
                        'inboundConnectionsBlocked' => $blockInbound,
                        'outboundConnectionsBlocked' => false,
                        'stealthModeBlocked' => false,
                        'unicastResponsesToMulticastBroadcastsBlocked' => true,
                    ];

                    return [
                        '@odata.type' => '#microsoft.graph.windows10EndpointProtectionConfiguration',
                        'firewallBlockStatefulFTP' => true,
                        'firewallProfileDomain' => $profile(false),
                        'firewallProfilePrivate' => $profile(true),
                        'firewallProfilePublic' => $profile(true),
                    ];
                },
            ],
            [
                'key' => 'WIN-SEC-04', 'platform' => 'windows', 'category' => 'Endpoint security', 'kind' => 'configuration',
                'name' => 'Attack surface reduction (ASR) rules',
                'summary' => '12 Microsoft "standard protection" ASR rules: Office macros and child processes, script obfuscation, email executables, LSASS credential theft, USB, WMI persistence, vulnerable drivers, ransomware.',
                'rationale' => 'ASR rules block the techniques commodity malware and ransomware use. Run in audit mode first, review the Defender reports, then block.',
                'odata' => '#microsoft.graph.windows10CustomConfiguration',
                'license' => ['intune'], 'ownership' => ['corporate'], 'impact' => 'medium', 'zt' => ['Devices', 'Applications'],
                'frameworks' => ['nist80053' => 'SI-3, SI-16, CM-7', 'nistcsf' => 'PR.PS-01, DE.CM-09', 'cis' => 'CIS Windows 11 Benchmark: Attack Surface Reduction', 'cisv8' => '10.5, 2.7', 'hipaa' => '§164.308(a)(5)(ii)(B)', 'iso27001' => 'A.8.7', 'pci' => '5.2', 'e8' => 'Configure Microsoft Office macro settings, User application hardening'],
                'params' => [
                    'mode' => ['label' => 'Rule mode', 'type' => 'select', 'default' => '2', 'options' => ['2' => 'Audit (start here)', '6' => 'Warn', '1' => 'Block']],
                ],
                'targets' => ['all_devices', 'pilot', 'group', 'none'], 'default_target' => 'pilot',
                'build' => function (array $p) {
                    $rules = [
                        'BE9BA2D9-53EA-4CDC-84E5-9B1EEEE46550', // executable content from email
                        'D4F940AB-401B-4EFC-AADC-AD5F3C50688A', // Office child processes
                        '3B576869-A4EC-4529-8536-B80A7769E899', // Office creating executable content
                        '75668C1F-73B5-4CF0-BB93-3ECF5CB7CC84', // Office code injection
                        'D3E037E1-3EB8-44C8-A917-57927947596D', // JS/VBS launching downloads
                        '5BEB7EFE-FD9A-4556-801D-275E5FFC04CC', // obfuscated scripts
                        '92E97FA1-2EDF-4476-BDD6-9DD0B4DDDC7B', // Win32 API from Office macros
                        '9E6C4E1F-7D60-472F-BA1A-A39EF669E4B2', // credential stealing from LSASS
                        'B2B3F03D-6A65-4F7B-A9C7-1C7EF74A9BA4', // untrusted USB processes
                        'E6DB77E5-3DF2-4CF1-B95A-636979351E5B', // WMI event persistence
                        '56A863A9-875E-4185-98A7-B882C64B5CE5', // vulnerable signed drivers
                        'C1DB55AB-C21A-4637-BB3F-A12568109D35', // advanced ransomware protection
                    ];
                    $value = implode('|', array_map(fn ($g) => $g.'='.$p['mode'], $rules));

                    return self::oma([['Attack surface reduction rules', './Device/Vendor/MSFT/Policy/Config/Defender/AttackSurfaceReductionRules', 'string', $value]]);
                },
            ],
            [
                'key' => 'WIN-SEC-05', 'platform' => 'windows', 'category' => 'Endpoint security', 'kind' => 'configuration',
                'name' => 'Windows LAPS: rotated local administrator password in Entra',
                'summary' => 'Back up the built-in admin password to Entra ID, 14+ characters, rotated every 30 days and after each use.',
                'rationale' => 'A shared local admin password is a classic lateral-movement path. LAPS makes every device unique and audited.',
                'odata' => '#microsoft.graph.windows10CustomConfiguration',
                'license' => ['intune'], 'ownership' => ['corporate'], 'impact' => 'low', 'zt' => ['Identities', 'Devices'],
                'frameworks' => ['nist80053' => 'AC-2, IA-5, AC-6', 'nistcsf' => 'PR.AA-01, PR.AA-05', 'cis' => 'CIS Windows 11 Benchmark: LAPS', 'cisv8' => '4.7, 5.2, 5.4', 'hipaa' => '§164.312(a)(2)(i), §164.312(d)', 'iso27001' => 'A.5.17, A.8.2', 'pci' => '2.2.2, 8.3.6', 'e8' => 'Restrict administrative privileges', 'ce' => 'User access control'],
                'params' => [
                    'age' => ['label' => 'Password age (days)', 'type' => 'int', 'default' => 30, 'min' => 1, 'max' => 365],
                    'length' => ['label' => 'Password length', 'type' => 'int', 'default' => 14, 'min' => 8, 'max' => 64],
                ],
                'targets' => ['all_devices', 'pilot', 'group', 'none'], 'default_target' => 'pilot',
                'build' => fn (array $p) => self::oma([
                    ['Backup directory: Entra ID', './Device/Vendor/MSFT/LAPS/Policies/BackupDirectory', 'integer', 1],
                    ['Password age (days)', './Device/Vendor/MSFT/LAPS/Policies/PasswordAgeDays', 'integer', $p['age']],
                    ['Complexity: upper, lower, numbers, specials', './Device/Vendor/MSFT/LAPS/Policies/PasswordComplexity', 'integer', 4],
                    ['Password length', './Device/Vendor/MSFT/LAPS/Policies/PasswordLength', 'integer', $p['length']],
                    ['After use: reset password and sign out', './Device/Vendor/MSFT/LAPS/Policies/PostAuthenticationActions', 'integer', 3],
                    ['Reset delay after use (hours)', './Device/Vendor/MSFT/LAPS/Policies/PostAuthenticationResetDelay', 'integer', 8],
                ]),
            ],
            [
                'key' => 'WIN-SEC-06', 'platform' => 'windows', 'category' => 'Endpoint security', 'kind' => 'configuration',
                'name' => 'Credential Guard and LSA protection',
                'summary' => 'Virtualization-based security with Credential Guard (UEFI lock) and LSASS running as a protected process.',
                'rationale' => 'Stops pass-the-hash and credential dumping (Mimikatz-style) even after an attacker gains admin on the device.',
                'odata' => '#microsoft.graph.windows10CustomConfiguration',
                'license' => ['intune'], 'ownership' => ['corporate'], 'impact' => 'low', 'zt' => ['Identities', 'Devices'],
                'frameworks' => ['nist80053' => 'SC-39, IA-5(13), SI-7', 'nistcsf' => 'PR.PS-01, PR.AA-01', 'cis' => 'CIS Windows 11 Benchmark: Device Guard', 'cisv8' => '4.1, 10.5', 'iso27001' => 'A.8.9', 'e8' => 'User application hardening'],
                'params' => [],
                'targets' => ['all_devices', 'pilot', 'group', 'none'], 'default_target' => 'pilot',
                'build' => fn () => self::oma([
                    ['Virtualization-based security', './Device/Vendor/MSFT/Policy/Config/DeviceGuard/EnableVirtualizationBasedSecurity', 'integer', 1],
                    ['Require Secure Boot for VBS', './Device/Vendor/MSFT/Policy/Config/DeviceGuard/RequirePlatformSecurityFeatures', 'integer', 1],
                    ['Credential Guard with UEFI lock', './Device/Vendor/MSFT/Policy/Config/DeviceGuard/LsaCfgFlags', 'integer', 1],
                    ['LSASS as protected process', './Device/Vendor/MSFT/Policy/Config/LocalSecurityAuthority/ConfigureLsaProtectedProcess', 'integer', 1],
                ]),
            ],
            [
                'key' => 'WIN-CFG-01', 'platform' => 'windows', 'category' => 'Configuration', 'kind' => 'configuration',
                'name' => 'Windows hardening: screen lock, SmartScreen, consumer features',
                'summary' => 'Machine inactivity lock, SmartScreen for apps and files with no override, Microsoft consumer experiences (suggested apps) turned off.',
                'rationale' => 'Unattended unlocked sessions and drive-by downloads are common incident causes. The automatic logoff requirement appears in HIPAA and PCI.',
                'odata' => '#microsoft.graph.windows10CustomConfiguration',
                'license' => ['intune'], 'ownership' => ['corporate'], 'impact' => 'low', 'zt' => ['Devices'],
                'frameworks' => ['nist80053' => 'AC-11, AC-12, CM-6, CM-7', 'nistcsf' => 'PR.PS-01', 'cis' => 'CIS Windows 11 Benchmark: Interactive logon, SmartScreen', 'cisv8' => '4.1, 4.3, 9.3', 'hipaa' => '§164.312(a)(2)(iii)', 'iso27001' => 'A.8.9', 'pci' => '8.2.8', 'e8' => 'User application hardening', 'ce' => 'Secure configuration'],
                'params' => [
                    'lock_seconds' => ['label' => 'Lock after inactivity (seconds)', 'type' => 'int', 'default' => 900, 'min' => 60, 'max' => 900, 'help' => 'CIS and PCI: 900 seconds (15 minutes) or less.'],
                ],
                'targets' => ['all_devices', 'pilot', 'group', 'none'], 'default_target' => 'pilot',
                'build' => fn (array $p) => self::oma([
                    ['Machine inactivity limit', './Device/Vendor/MSFT/Policy/Config/LocalPoliciesSecurityOptions/InteractiveLogon_MachineInactivityLimit', 'integer', $p['lock_seconds']],
                    ['SmartScreen in Windows shell', './Device/Vendor/MSFT/Policy/Config/SmartScreen/EnableSmartScreenInShell', 'integer', 1],
                    ['Prevent SmartScreen override', './Device/Vendor/MSFT/Policy/Config/SmartScreen/PreventOverrideForFilesInShell', 'integer', 1],
                    ['Turn off consumer experiences', './Device/Vendor/MSFT/Policy/Config/Experience/AllowWindowsConsumerFeatures', 'integer', 0],
                ]),
            ],
            [
                'key' => 'WIN-CFG-02', 'platform' => 'windows', 'category' => 'Configuration', 'kind' => 'configuration',
                'name' => 'Windows Hello for Business',
                'summary' => 'Phishing-resistant, TPM-backed sign-in with a 6+ digit PIN and PIN recovery.',
                'rationale' => 'Replaces passwords on the device with a hardware-bound credential, the device side of phishing-resistant MFA.',
                'odata' => '#microsoft.graph.windows10CustomConfiguration',
                'license' => ['intune'], 'ownership' => ['corporate'], 'impact' => 'medium', 'zt' => ['Identities', 'Devices'],
                'frameworks' => ['nist80053' => 'IA-2(1), IA-2(2), IA-5', 'nistcsf' => 'PR.AA-03', 'cisv8' => '6.3, 6.5', 'hipaa' => '§164.312(d)', 'iso27001' => 'A.8.5', 'pci' => '8.4', 'e8' => 'Multi-factor authentication', 'ce' => 'User access control'],
                'params' => [
                    'pin_length' => ['label' => 'Minimum PIN length', 'type' => 'int', 'default' => 6, 'min' => 4, 'max' => 127],
                ],
                'targets' => ['all_devices', 'all_users', 'pilot', 'group', 'none'], 'default_target' => 'pilot',
                'build' => fn (array $p, Tenant $t) => self::oma([
                    ['Use Windows Hello for Business', "./Device/Vendor/MSFT/PassportForWork/{$t->ms_tenant_id}/Policies/UsePassportForWork", 'boolean', true],
                    ['Require TPM', "./Device/Vendor/MSFT/PassportForWork/{$t->ms_tenant_id}/Policies/RequireSecurityDevice", 'boolean', true],
                    ['Minimum PIN length', "./Device/Vendor/MSFT/PassportForWork/{$t->ms_tenant_id}/Policies/PINComplexity/MinimumPINLength", 'integer', $p['pin_length']],
                    ['PIN recovery', "./Device/Vendor/MSFT/PassportForWork/{$t->ms_tenant_id}/Policies/EnablePinRecovery", 'boolean', true],
                ]),
            ],
            [
                'key' => 'WIN-UPD-01', 'platform' => 'windows', 'category' => 'Update management', 'kind' => 'configuration',
                'name' => 'Windows Update ring: Pilot (IT and early adopters)',
                'summary' => 'No deferral. Quality updates installed within 2 days, feature updates within 7. Users cannot pause.',
                'rationale' => 'Ring-based patching: IT validates every monthly update before the broad ring, keeping time-to-patch low with controlled risk.',
                'odata' => '#microsoft.graph.windowsUpdateForBusinessConfiguration',
                'license' => ['intune'], 'ownership' => ['corporate'], 'impact' => 'medium', 'zt' => ['Devices'],
                'frameworks' => ['nist80053' => 'SI-2, SI-2(2), CM-3', 'nistcsf' => 'PR.PS-02, ID.RA-01', 'cis' => 'CIS Windows 11 Benchmark: Windows Update', 'cisv8' => '7.3, 7.4', 'hipaa' => '§164.308(a)(1)(ii)(B), §164.308(a)(5)(ii)(B)', 'iso27001' => 'A.8.8', 'pci' => '6.3.3', 'e8' => 'Patch operating systems', 'ce' => 'Security update management'],
                'params' => self::ringParams(0, 0, 2, 7),
                'targets' => ['pilot', 'group', 'all_devices', 'none'], 'default_target' => 'pilot',
                'build' => fn (array $p) => self::updateRing($p),
            ],
            [
                'key' => 'WIN-UPD-02', 'platform' => 'windows', 'category' => 'Update management', 'kind' => 'configuration',
                'name' => 'Windows Update ring: Broad (everyone else)',
                'summary' => 'Quality updates deferred 5 days, installed within 3 more. Feature updates deferred 30 days.',
                'rationale' => 'Most devices patch within about 10 days of Patch Tuesday, which meets Essential Eight ML2 and the CIS 7.3 targets after pilot validation.',
                'odata' => '#microsoft.graph.windowsUpdateForBusinessConfiguration',
                'license' => ['intune'], 'ownership' => ['corporate'], 'impact' => 'medium', 'zt' => ['Devices'],
                'frameworks' => ['nist80053' => 'SI-2, SI-2(2), CM-3', 'nistcsf' => 'PR.PS-02', 'cis' => 'CIS Windows 11 Benchmark: Windows Update', 'cisv8' => '7.3, 7.4', 'hipaa' => '§164.308(a)(1)(ii)(B)', 'iso27001' => 'A.8.8', 'pci' => '6.3.3', 'e8' => 'Patch operating systems', 'ce' => 'Security update management'],
                'params' => self::ringParams(5, 30, 3, 7),
                'targets' => ['all_devices', 'group', 'pilot', 'none'], 'default_target' => 'all_devices',
                'build' => fn (array $p) => self::updateRing($p),
            ],
            [
                'key' => 'WIN-UPD-03', 'platform' => 'windows', 'category' => 'Update management', 'kind' => 'feature_update',
                'name' => 'Windows feature update: pin to a supported Windows 11 version',
                'summary' => 'Holds devices on a chosen Windows 11 release and upgrades older devices to it.',
                'rationale' => 'Keeps the estate on a supported, consistent release instead of drifting across versions, which is part of OS lifecycle management.',
                'license' => ['intune'], 'ownership' => ['corporate'], 'impact' => 'high', 'zt' => ['Devices'],
                'frameworks' => ['nist80053' => 'SI-2, CM-2, SA-22', 'nistcsf' => 'PR.PS-02', 'cisv8' => '2.2, 7.3', 'iso27001' => 'A.8.8', 'e8' => 'Patch operating systems', 'ce' => 'Security update management'],
                'params' => [
                    'version' => ['label' => 'Windows version', 'type' => 'select', 'default' => 'Windows 11, version 24H2', 'options' => ['Windows 11, version 24H2' => 'Windows 11 24H2', 'Windows 11, version 23H2' => 'Windows 11 23H2', 'Windows 11, version 25H2' => 'Windows 11 25H2']],
                ],
                'targets' => ['pilot', 'group', 'none'], 'default_target' => 'pilot',
                'build' => fn (array $p) => ['featureUpdateVersion' => $p['version'], 'installLatestWindows10OnWindows11IneligibleDevice' => false],
            ],

            // ======================================================= iOS / iPadOS
            [
                'key' => 'IOS-CMP-01', 'platform' => 'ios', 'category' => 'Compliance', 'kind' => 'compliance',
                'name' => 'iOS/iPadOS compliance: passcode, jailbreak, minimum OS',
                'summary' => '6-digit non-simple passcode, auto-lock 5 min, jailbroken devices blocked, minimum iOS version, optional Defender risk.',
                'rationale' => 'Enrolled Apple devices must prove health before reaching corporate data through Conditional Access.',
                'odata' => '#microsoft.graph.iosCompliancePolicy',
                'license' => ['intune'], 'ownership' => ['corporate', 'byod'], 'impact' => 'medium', 'zt' => ['Devices'],
                'frameworks' => ['nist80053' => 'AC-19, AC-11, IA-5, SI-2', 'nistcsf' => 'PR.PS-01, PR.AA-03', 'cis' => 'CIS Apple iOS/iPadOS Benchmark', 'cisv8' => '4.1, 4.3, 4.10, 7.3', 'hipaa' => '§164.312(a)(2)(iii), §164.312(d)', 'iso27001' => 'A.8.1', 'pci' => '8.2.8, 8.3.6', 'ce' => 'Secure configuration, Security update management'],
                'params' => [
                    'min_os' => ['label' => 'Minimum iOS version', 'type' => 'text', 'default' => '17.0'],
                    'passcode_length' => ['label' => 'Minimum passcode length', 'type' => 'int', 'default' => 6, 'min' => 4, 'max' => 16],
                    'grace_hours' => $grace,
                    'mde_level' => $mde,
                ],
                'targets' => ['all_users', 'pilot', 'group', 'none'], 'default_target' => 'pilot',
                'build' => fn (array $p) => [
                    '@odata.type' => '#microsoft.graph.iosCompliancePolicy',
                    'passcodeRequired' => true,
                    'passcodeBlockSimple' => true,
                    'passcodeMinimumLength' => $p['passcode_length'],
                    'passcodeRequiredType' => 'numeric',
                    'passcodeMinutesOfInactivityBeforeLock' => 5,
                    'osMinimumVersion' => $p['min_os'],
                    'securityBlockJailbrokenDevices' => true,
                    'managedEmailProfileRequired' => false,
                    'scheduledActionsForRule' => self::scheduledBlock($p['grace_hours']),
                ] + self::threat($p['mde_level']),
            ],
            [
                'key' => 'IOS-CFG-01', 'platform' => 'ios', 'category' => 'Configuration', 'kind' => 'configuration',
                'name' => 'iOS/iPadOS device restrictions (corporate devices)',
                'summary' => 'Enforced passcode, wipe after 10 failed attempts, managed documents kept out of unmanaged apps.',
                'rationale' => 'For company-owned Apple devices: enforce device-level controls and managed open-in to prevent data leakage.',
                'odata' => '#microsoft.graph.iosGeneralDeviceConfiguration',
                'license' => ['intune'], 'ownership' => ['corporate'], 'impact' => 'medium', 'zt' => ['Devices', 'Data'],
                'frameworks' => ['nist80053' => 'AC-7, AC-11, AC-19, AC-4', 'nistcsf' => 'PR.PS-01, PR.DS-01', 'cis' => 'CIS Apple iOS/iPadOS Benchmark (Institutionally owned)', 'cisv8' => '4.1, 4.10, 3.3', 'hipaa' => '§164.312(a)(2)(iii), §164.310(d)(1)', 'iso27001' => 'A.8.1, A.8.12', 'pci' => '8.3.4'],
                'params' => [
                    'wipe_after' => ['label' => 'Wipe after failed passcode attempts', 'type' => 'int', 'default' => 10, 'min' => 4, 'max' => 11],
                ],
                'targets' => ['all_devices', 'pilot', 'group', 'none'], 'default_target' => 'pilot',
                'build' => fn (array $p) => [
                    '@odata.type' => '#microsoft.graph.iosGeneralDeviceConfiguration',
                    'passcodeRequired' => true,
                    'passcodeBlockSimple' => true,
                    'passcodeMinimumLength' => 6,
                    'passcodeRequiredType' => 'numeric',
                    'passcodeMinutesOfInactivityBeforeLock' => 5,
                    'passcodeMinutesOfInactivityBeforeScreenTimeout' => 5,
                    'passcodeSignInFailureCountBeforeWipe' => $p['wipe_after'],
                    'documentsBlockManagedDocumentsInUnmanagedApps' => true,
                ],
            ],
            [
                'key' => 'IOS-APP-01', 'platform' => 'ios', 'category' => 'App protection', 'kind' => 'mam_ios',
                'name' => 'iOS/iPadOS app protection (BYOD and corporate)',
                'summary' => 'Microsoft data protection framework level 1, 2 or 3 for Outlook, Teams, Office, OneDrive, Edge: app PIN, encryption, no copy/save to personal apps, offline wipe.',
                'rationale' => 'Protects corporate data inside apps without managing the personal device. This is the Zero Trust answer to BYOD privacy concerns.',
                'odata' => '#microsoft.graph.iosManagedAppProtection',
                'license' => ['intune'], 'ownership' => ['byod', 'corporate'], 'impact' => 'medium', 'zt' => ['Data', 'Applications'],
                'frameworks' => $mamFrameworks + ['cis' => 'CIS Apple iOS/iPadOS Benchmark (BYOD)'],
                'params' => $mamParams + ['min_os' => ['label' => 'Minimum iOS version', 'type' => 'text', 'default' => '17.0']],
                'targets' => ['all_users', 'pilot', 'group', 'none'], 'default_target' => 'pilot',
                'build' => fn (array $p) => self::appProtection('ios', $p),
            ],

            // ======================================================= ANDROID
            [
                'key' => 'AND-CMP-01', 'platform' => 'android', 'category' => 'Compliance', 'kind' => 'compliance',
                'name' => 'Android work profile compliance (personally owned)',
                'summary' => 'Device PIN, Play Integrity (basic and device), encryption, no root, Google Play services, recent security patch.',
                'rationale' => 'BYOD Android with a work profile: corporate data only flows to devices that pass Google hardware attestation.',
                'odata' => '#microsoft.graph.androidWorkProfileCompliancePolicy',
                'license' => ['intune'], 'ownership' => ['byod'], 'impact' => 'medium', 'zt' => ['Devices'],
                'frameworks' => ['nist80053' => 'AC-19, IA-5, SI-2, SI-7', 'nistcsf' => 'PR.PS-01, PR.PS-02', 'cis' => 'CIS Google Android Benchmark', 'cisv8' => '4.1, 4.10, 7.3', 'hipaa' => '§164.312(a)(2)(iii), §164.312(a)(2)(iv)', 'iso27001' => 'A.8.1', 'pci' => '8.2.8', 'ce' => 'Secure configuration'],
                'params' => self::androidCompliance(),
                'targets' => ['all_users', 'pilot', 'group', 'none'], 'default_target' => 'pilot',
                'build' => fn (array $p) => [
                    '@odata.type' => '#microsoft.graph.androidWorkProfileCompliancePolicy',
                    'passwordRequired' => true,
                    'passwordMinimumLength' => $p['pin_length'],
                    'passwordRequiredType' => 'numericComplex',
                    'passwordMinutesOfInactivityBeforeLock' => 5,
                    'osMinimumVersion' => $p['min_os'],
                    'minAndroidSecurityPatchLevel' => $p['patch_level'],
                    'securityBlockJailbrokenDevices' => true,
                    'securityRequireSafetyNetAttestationBasicIntegrity' => true,
                    'securityRequireSafetyNetAttestationCertifiedDevice' => true,
                    'securityRequireGooglePlayServices' => true,
                    'securityRequireUpToDateSecurityProviders' => true,
                    'storageRequireEncryption' => true,
                    'scheduledActionsForRule' => self::scheduledBlock($p['grace_hours']),
                ] + self::threat($p['mde_level']),
            ],
            [
                'key' => 'AND-CMP-02', 'platform' => 'android', 'category' => 'Compliance', 'kind' => 'compliance',
                'name' => 'Android Enterprise fully managed compliance (corporate)',
                'summary' => 'Same health bar for company-owned, fully managed Android devices.',
                'rationale' => 'Corporate Android devices are held to the same attestation, encryption and patch requirements.',
                'odata' => '#microsoft.graph.androidDeviceOwnerCompliancePolicy',
                'license' => ['intune'], 'ownership' => ['corporate'], 'impact' => 'medium', 'zt' => ['Devices'],
                'frameworks' => ['nist80053' => 'AC-19, IA-5, SI-2', 'nistcsf' => 'PR.PS-01, PR.PS-02', 'cis' => 'CIS Google Android Benchmark', 'cisv8' => '4.1, 4.10, 7.3', 'hipaa' => '§164.312(a)(2)(iii), §164.312(a)(2)(iv)', 'iso27001' => 'A.8.1', 'pci' => '8.2.8'],
                'params' => self::androidCompliance(false),
                'targets' => ['all_users', 'all_devices', 'pilot', 'group', 'none'], 'default_target' => 'pilot',
                'build' => fn (array $p) => [
                    '@odata.type' => '#microsoft.graph.androidDeviceOwnerCompliancePolicy',
                    'passwordRequired' => true,
                    'passwordMinimumLength' => $p['pin_length'],
                    'passwordRequiredType' => 'numericComplex',
                    'passwordMinutesOfInactivityBeforeLock' => 5,
                    'osMinimumVersion' => $p['min_os'],
                    'minAndroidSecurityPatchLevel' => $p['patch_level'],
                    'securityRequireSafetyNetAttestationBasicIntegrity' => true,
                    'securityRequireSafetyNetAttestationCertifiedDevice' => true,
                    'storageRequireEncryption' => true,
                    'scheduledActionsForRule' => self::scheduledBlock($p['grace_hours']),
                ],
            ],
            [
                'key' => 'AND-CFG-01', 'platform' => 'android', 'category' => 'Configuration', 'kind' => 'configuration',
                'name' => 'Android work profile restrictions (BYOD)',
                'summary' => 'Work and personal data separated: no cross-profile sharing or copy-paste, no work screenshots, work profile passcode.',
                'rationale' => 'The work profile is a container. These settings stop data crossing into personal apps while leaving the personal side private.',
                'odata' => '#microsoft.graph.androidWorkProfileGeneralDeviceConfiguration',
                'license' => ['intune'], 'ownership' => ['byod'], 'impact' => 'medium', 'zt' => ['Data', 'Devices'],
                'frameworks' => ['nist80053' => 'AC-4, AC-19, SC-4', 'nistcsf' => 'PR.DS-01, PR.DS-02', 'cis' => 'CIS Google Android Benchmark (work profile)', 'cisv8' => '3.3, 4.1', 'hipaa' => '§164.312(a)(1), §164.312(c)(1)', 'iso27001' => 'A.8.1, A.8.12'],
                'params' => [
                    'sharing' => ['label' => 'Cross-profile data sharing', 'type' => 'select', 'default' => 'preventAny', 'options' => ['preventAny' => 'Block all sharing (recommended)', 'allowPersonalToWork' => 'Allow personal to work only']],
                ],
                'targets' => ['all_users', 'pilot', 'group', 'none'], 'default_target' => 'pilot',
                'build' => fn (array $p) => [
                    '@odata.type' => '#microsoft.graph.androidWorkProfileGeneralDeviceConfiguration',
                    'workProfileDataSharingType' => $p['sharing'],
                    'workProfileBlockCrossProfileCopyPaste' => true,
                    'workProfileBlockScreenCapture' => true,
                    'workProfileBlockCrossProfileContactsSearch' => true,
                    'workProfileRequirePassword' => true,
                    'workProfilePasswordMinimumLength' => 6,
                    'workProfilePasswordRequiredType' => 'numericComplex',
                    'workProfilePasswordMinutesOfInactivityBeforeScreenTimeout' => 5,
                    'securityRequireVerifyApps' => true,
                ],
            ],
            [
                'key' => 'AND-CFG-02', 'platform' => 'android', 'category' => 'Configuration', 'kind' => 'configuration',
                'name' => 'Android fully managed device restrictions (corporate)',
                'summary' => 'Strong device PIN, no screen capture, no USB file transfer, no debugging, no external storage.',
                'rationale' => 'Company-owned Android devices locked down to prevent data exfiltration and tampering.',
                'odata' => '#microsoft.graph.androidDeviceOwnerGeneralDeviceConfiguration',
                'license' => ['intune'], 'ownership' => ['corporate'], 'impact' => 'high', 'zt' => ['Devices', 'Data'],
                'frameworks' => ['nist80053' => 'AC-19, MP-7, CM-7', 'nistcsf' => 'PR.PS-01, PR.DS-01', 'cis' => 'CIS Google Android Benchmark', 'cisv8' => '4.1, 3.9', 'hipaa' => '§164.310(d)(1)', 'iso27001' => 'A.8.1, A.7.10'],
                'params' => [],
                'targets' => ['all_devices', 'pilot', 'group', 'none'], 'default_target' => 'pilot',
                'build' => fn () => [
                    '@odata.type' => '#microsoft.graph.androidDeviceOwnerGeneralDeviceConfiguration',
                    'passwordRequiredType' => 'numericComplex',
                    'passwordMinimumLength' => 6,
                    'passwordMinutesOfInactivityBeforeScreenTimeout' => 5,
                    'screenCaptureBlocked' => true,
                    'usbFileTransferBlocked' => true,
                    'securityAllowDebuggingFeatures' => false,
                    'storageBlockExternalMedia' => true,
                ],
            ],
            [
                'key' => 'AND-APP-01', 'platform' => 'android', 'category' => 'App protection', 'kind' => 'mam_android',
                'name' => 'Android app protection (BYOD and corporate)',
                'summary' => 'Microsoft data protection framework level 1, 2 or 3: app PIN, encryption, no copy/save to personal apps, screenshots blocked from L2, offline wipe.',
                'rationale' => 'Protects corporate data in Microsoft apps on any Android device, enrolled or not.',
                'odata' => '#microsoft.graph.androidManagedAppProtection',
                'license' => ['intune'], 'ownership' => ['byod', 'corporate'], 'impact' => 'medium', 'zt' => ['Data', 'Applications'],
                'frameworks' => $mamFrameworks + ['cis' => 'CIS Google Android Benchmark (BYOD)'],
                'params' => $mamParams + ['min_os' => ['label' => 'Minimum Android version', 'type' => 'text', 'default' => '12.0']],
                'targets' => ['all_users', 'pilot', 'group', 'none'], 'default_target' => 'pilot',
                'build' => fn (array $p) => self::appProtection('android', $p),
            ],

            // ======================================================= ZERO TRUST CONDITIONAL ACCESS
            [
                'key' => 'ZT-CA-01', 'platform' => 'cross', 'category' => 'Conditional Access', 'kind' => 'ca',
                'name' => 'Conditional Access: require compliant device (Windows)',
                'summary' => 'Windows access to all cloud apps requires an Intune-compliant (or hybrid-joined) device. Created in report-only mode.',
                'rationale' => 'Verify explicitly: identity alone is not enough, the device must be known and healthy. This turns the compliance policies into enforcement.',
                'license' => ['intune', 'p1'], 'ownership' => ['corporate'], 'impact' => 'high', 'zt' => ['Identities', 'Devices'],
                'frameworks' => ['nist80053' => 'AC-3, AC-19, IA-3', 'nistcsf' => 'PR.AA-05, PR.AA-06', 'cisv8' => '6.7, 13.5', 'hipaa' => '§164.312(a)(1)', 'iso27001' => 'A.5.15, A.8.1', 'pci' => '7.2'],
                'params' => [],
                'targets' => ['pilot', 'all_users', 'group'], 'default_target' => 'pilot',
                'build' => fn (array $p, Tenant $t, array $ctx) => [
                    'state' => 'enabledForReportingButNotEnforced',
                    'conditions' => [
                        'users' => self::caUsers($ctx),
                        'applications' => ['includeApplications' => ['All']],
                        'clientAppTypes' => ['all'],
                        'platforms' => ['includePlatforms' => ['windows']],
                    ],
                    'grantControls' => ['operator' => 'OR', 'builtInControls' => ['compliantDevice', 'domainJoinedDevice']],
                ],
            ],
            [
                'key' => 'ZT-CA-02', 'platform' => 'cross', 'category' => 'Conditional Access', 'kind' => 'ca',
                'name' => 'Conditional Access: require app protection on iOS and Android',
                'summary' => 'Mobile access to Microsoft 365 only through apps with an Intune app protection policy. Created in report-only mode.',
                'rationale' => 'Closes the BYOD gap: without this, users can bypass app protection with the native mail app or a personal browser.',
                'license' => ['intune', 'p1'], 'ownership' => ['byod', 'corporate'], 'impact' => 'high', 'zt' => ['Data', 'Applications', 'Identities'],
                'frameworks' => ['nist80053' => 'AC-19, AC-4, AC-3', 'nistcsf' => 'PR.AA-05, PR.DS-02', 'cisv8' => '3.3, 6.7', 'hipaa' => '§164.312(a)(1)', 'iso27001' => 'A.5.15, A.8.1'],
                'params' => [],
                'targets' => ['pilot', 'all_users', 'group'], 'default_target' => 'pilot',
                'build' => fn (array $p, Tenant $t, array $ctx) => [
                    'state' => 'enabledForReportingButNotEnforced',
                    'conditions' => [
                        'users' => self::caUsers($ctx),
                        'applications' => ['includeApplications' => ['Office365']],
                        'clientAppTypes' => ['browser', 'mobileAppsAndDesktopClients'],
                        'platforms' => ['includePlatforms' => ['android', 'iOS']],
                    ],
                    'grantControls' => ['operator' => 'OR', 'builtInControls' => ['compliantApplication']],
                ],
            ],
            [
                'key' => 'ZT-CA-03', 'platform' => 'cross', 'category' => 'Conditional Access', 'kind' => 'ca',
                'name' => 'Conditional Access: block unknown or unsupported device platforms',
                'summary' => 'Blocks sign-ins from platforms you do not manage (for example Linux or spoofed agents). Created in report-only mode.',
                'rationale' => 'Assume breach: anything you cannot evaluate should not get access by default.',
                'license' => ['p1'], 'ownership' => ['corporate', 'byod'], 'impact' => 'medium', 'zt' => ['Devices', 'Identities'],
                'frameworks' => ['nist80053' => 'AC-3, AC-19, CM-7', 'nistcsf' => 'PR.AA-05', 'cisv8' => '2.3, 6.7', 'iso27001' => 'A.5.15'],
                'params' => [
                    'allow_macos' => ['label' => 'Allow macOS', 'type' => 'bool', 'default' => true],
                ],
                'targets' => ['pilot', 'all_users', 'group'], 'default_target' => 'pilot',
                'build' => fn (array $p, Tenant $t, array $ctx) => [
                    'state' => 'enabledForReportingButNotEnforced',
                    'conditions' => [
                        'users' => self::caUsers($ctx),
                        'applications' => ['includeApplications' => ['All']],
                        'clientAppTypes' => ['all'],
                        'platforms' => ['includePlatforms' => ['all'], 'excludePlatforms' => array_values(array_filter(['android', 'iOS', 'windows', $p['allow_macos'] ? 'macOS' : null]))],
                    ],
                    'grantControls' => ['operator' => 'OR', 'builtInControls' => ['block']],
                ],
            ],
        ];
    }

    private static function ringParams(int $quality, int $feature, int $qDeadline, int $fDeadline): array
    {
        return [
            'quality_deferral' => ['label' => 'Quality update deferral (days)', 'type' => 'int', 'default' => $quality, 'min' => 0, 'max' => 30],
            'feature_deferral' => ['label' => 'Feature update deferral (days)', 'type' => 'int', 'default' => $feature, 'min' => 0, 'max' => 365],
            'quality_deadline' => ['label' => 'Quality update deadline (days)', 'type' => 'int', 'default' => $qDeadline, 'min' => 0, 'max' => 30],
            'feature_deadline' => ['label' => 'Feature update deadline (days)', 'type' => 'int', 'default' => $fDeadline, 'min' => 0, 'max' => 30],
            'active_start' => ['label' => 'Active hours start', 'type' => 'select', 'default' => '08', 'options' => array_combine(array_map(fn ($h) => sprintf('%02d', $h), range(5, 12)), array_map(fn ($h) => sprintf('%02d:00', $h), range(5, 12)))],
            'active_end' => ['label' => 'Active hours end', 'type' => 'select', 'default' => '18', 'options' => array_combine(array_map(fn ($h) => sprintf('%02d', $h), range(15, 22)), array_map(fn ($h) => sprintf('%02d:00', $h), range(15, 22)))],
        ];
    }

    private static function updateRing(array $p): array
    {
        return [
            '@odata.type' => '#microsoft.graph.windowsUpdateForBusinessConfiguration',
            'deliveryOptimizationMode' => 'httpWithPeeringNat',
            'prereleaseFeatures' => 'notAllowed',
            'automaticUpdateMode' => 'autoInstallAtMaintenanceTime',
            'microsoftUpdateServiceAllowed' => true,
            'driversExcluded' => false,
            'qualityUpdatesDeferralPeriodInDays' => $p['quality_deferral'],
            'featureUpdatesDeferralPeriodInDays' => $p['feature_deferral'],
            'qualityUpdatesPaused' => false,
            'featureUpdatesPaused' => false,
            'businessReadyUpdatesOnly' => 'all',
            'skipChecksBeforeRestart' => false,
            'featureUpdatesRollbackWindowInDays' => 10,
            'deadlineForQualityUpdatesInDays' => $p['quality_deadline'],
            'deadlineForFeatureUpdatesInDays' => $p['feature_deadline'],
            'deadlineGracePeriodInDays' => 2,
            'postponeRebootUntilAfterDeadline' => false,
            'userPauseAccess' => 'disabled',
            'userWindowsUpdateScanAccess' => 'enabled',
            'updateNotificationLevel' => 'defaultNotifications',
            'installationSchedule' => [
                '@odata.type' => '#microsoft.graph.windowsUpdateActiveHoursInstall',
                'activeHoursStart' => $p['active_start'].':00:00.0000000',
                'activeHoursEnd' => $p['active_end'].':00:00.0000000',
            ],
        ];
    }

    private static function androidCompliance(bool $withMde = true): array
    {
        $params = [
            'min_os' => ['label' => 'Minimum Android version', 'type' => 'text', 'default' => '12.0'],
            'patch_level' => ['label' => 'Minimum security patch level (YYYY-MM-DD)', 'type' => 'text', 'default' => now()->subMonths(6)->startOfMonth()->format('Y-m-d')],
            'pin_length' => ['label' => 'Minimum PIN length', 'type' => 'int', 'default' => 6, 'min' => 4, 'max' => 16],
            'grace_hours' => ['label' => 'Grace period before non-compliant (hours)', 'type' => 'int', 'default' => 24, 'min' => 0, 'max' => 720],
        ];
        if ($withMde) {
            $params['mde_level'] = ['label' => 'Require Defender for Endpoint risk score', 'type' => 'select', 'default' => 'none',
                'options' => ['none' => 'Not required', 'secured' => 'Clear', 'low' => 'Low or below', 'medium' => 'Medium or below', 'high' => 'High or below']];
        }

        return $params;
    }

    /** Licences a template needs that the tenant lacks. */
    public static function missingLicenses(array $template, array $licenses): array
    {
        // Defender for Endpoint only becomes a requirement when a risk level is chosen (checked at deploy time).
        $needs = $template['license'] ?? [];

        return array_values(array_filter($needs, fn ($l) => ! ($licenses[$l] ?? true)));
    }

    /** Union of framework tags covered by a list of template keys. */
    public static function coverage(array $keys): array
    {
        $out = [];
        foreach ($keys as $k) {
            foreach (self::find($k)['frameworks'] ?? [] as $fw => $ref) {
                $out[$fw] = ($out[$fw] ?? 0) + 1;
            }
        }

        return $out;
    }
}
