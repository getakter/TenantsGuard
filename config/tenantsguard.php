<?php

return [
    'admin' => [
        'name' => env('ADMIN_NAME', 'MSP Admin'),
        'email' => env('ADMIN_EMAIL', 'admin@tenantsguard.local'),
        'password' => env('ADMIN_PASSWORD'),
    ],

    // Multi-tenant Microsoft Entra app registration used to read (and optionally fix) customer tenants.
    'microsoft' => [
        'client_id' => env('MS_CLIENT_ID'),
        'client_secret' => env('MS_CLIENT_SECRET'),
        'redirect_uri' => env('MS_REDIRECT_URI') ?: rtrim((string) env('APP_URL', 'http://127.0.0.1:8000'), '/').'/consent/callback',
        'graph_base' => 'https://graph.microsoft.com',
        'login_base' => 'https://login.microsoftonline.com',
        'app_name' => env('MS_APP_NAME', 'TenantsGuard'),
        // Public client used only for the one-time automatic app registration (device code sign-in).
        // Default: Microsoft Graph Command Line Tools, the client behind Connect-MgGraph.
        'setup_client_id' => env('MS_SETUP_CLIENT_ID', '14d82eec-204b-4c2f-b7e8-296a70dab67e'),
    ],

    'scan_interval_hours' => (int) env('SCAN_INTERVAL_HOURS', 24),
    'retention_days' => (int) env('SCAN_RETENTION_DAYS', 120),

    'severity_weights' => ['critical' => 10, 'high' => 6, 'medium' => 3, 'low' => 1],

    // PowerShell script runner. Execution is also switched on/off in the app (Tools > PowerShell scripts).
    'scripts' => [
        'pwsh' => env('TG_PWSH'),                                  // path to pwsh.exe; auto-detected when empty
        'dispatch' => env('TG_SCRIPTS_DISPATCH', 'spawn'),         // spawn: start a background worker on submit; scheduler: wait for schedule:run; none: tests
        'timeout_default' => 300,                                  // seconds per tenant
        'timeout_max' => 1800,
        'output_max_kb' => (int) env('TG_SCRIPTS_OUTPUT_KB', 512), // per tenant; the process is stopped when exceeded
        'max_tenants' => (int) env('TG_SCRIPTS_MAX_TENANTS', 50),  // per run
        'max_graph_calls' => 2000,                                 // per tenant per run, enforced by Invoke-TGGraph
        'max_body_kb' => 64,
        'retention_days' => (int) env('TG_SCRIPTS_RETENTION_DAYS', 90),
    ],

    // Application permissions to add to the Entra app registration (Microsoft Graph).
    'intune' => [
        // spawn: start a background worker on submit; scheduler: wait for schedule:run; sync: run in the request (tests)
        'dispatch' => env('TG_INTUNE_DISPATCH', 'spawn'),
    ],

    'permissions' => [
        'read' => [
            'Organization.Read.All' => 'Tenant name and licences',
            'Directory.Read.All' => 'Users, guests, groups and directory roles',
            'Policy.Read.All' => 'Conditional Access, security defaults, authorization and auth-method policies',
            'AuditLog.Read.All' => 'MFA registration details and last sign-in activity',
            'RoleManagement.Read.Directory' => 'Privileged role assignments',
            'Domain.Read.All' => 'Domains and password expiry settings',
            'Application.Read.All' => 'App registrations and their credentials',
            'SecurityEvents.Read.All' => 'Microsoft Secure Score',
            'IdentityRiskyUser.Read.All' => 'Risky users (Entra ID P2)',
            'SharePointTenantSettings.Read.All' => 'SharePoint and OneDrive sharing settings',
            'DeviceManagementConfiguration.Read.All' => 'Intune compliance policies',
            'DeviceManagementManagedDevices.Read.All' => 'Intune managed device compliance',
            'DeviceManagementApps.Read.All' => 'Intune app protection policies',
            'DeviceManagementServiceConfig.Read.All' => 'Intune enrollment, Apple push certificate, Managed Google Play, Defender connector',
            'InformationProtectionPolicy.Read.All' => 'Purview: read published sensitivity labels',
            'Reports.Read.All' => 'Copilot: usage reports (active users, last activity per app)',
        ],
        'write' => [
            'Policy.ReadWrite.ConditionalAccess' => 'Create and enforce Conditional Access policies',
            'Policy.ReadWrite.Authorization' => 'Harden user/guest defaults and app consent',
            'Policy.ReadWrite.AuthenticationMethod' => 'Disable weak MFA methods, enable FIDO2',
            'Policy.ReadWrite.ConsentRequest' => 'Turn on the admin consent workflow',
            'Policy.ReadWrite.SecurityDefaults' => 'Turn on security defaults',
            'Domain.ReadWrite.All' => 'Set passwords to never expire',
            'SharePointTenantSettings.ReadWrite.All' => 'Restrict external sharing',
            'DeviceManagementConfiguration.ReadWrite.All' => 'Intune: compliance, configuration, update rings, feature updates',
            'DeviceManagementApps.ReadWrite.All' => 'Intune: app protection policies',
            'DeviceManagementServiceConfig.ReadWrite.All' => 'Intune: Windows Autopilot profiles',
            'Group.ReadWrite.All' => 'Create the pilot and named assignment groups',
            'RecordsManagement.ReadWrite.All' => 'Purview: create retention labels from the approved retention schedule',
            'User.ReadWrite.All' => 'Staff onboarding/offboarding: create, update, disable users, assign licences, revoke sessions',
            'UserAuthenticationMethod.ReadWrite.All' => 'Issue Temporary Access Passes, remove MFA methods of leavers',
            'MailboxSettings.ReadWrite' => 'Set the out-of-office reply for leavers',
            'RoleManagement.ReadWrite.Directory' => 'Remove admin roles from leavers',
            'DeviceManagementManagedDevices.PrivilegedOperations.All' => 'Retire or wipe a leaver\'s Intune devices',
        ],
    ],
];
