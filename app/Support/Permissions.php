<?php

namespace App\Support;

/** Every permission a role can grant, grouped for the role editor. */
final class Permissions
{
    public const GROUPS = [
        'Tenants' => [
            'tenants.view' => 'View tenants, findings, controls and baselines',
            'tenants.manage' => 'Connect, add, re-consent and remove tenants',
            'tenants.scan' => 'Run scans (and simulate drift on demo tenants)',
        ],
        'Remediation' => [
            'fixes.apply' => 'Apply quick fixes and create report-only Conditional Access policies',
            'fixes.enforce' => 'Enforce Conditional Access policies (step 2 of guided workflows)',
            'baselines.apply' => 'Apply baseline items to tenants',
            'baselines.manage' => 'Create and delete baselines',
            'annotations.manage' => 'Record risk acceptance, compensating controls and notes',
        ],
        'Intune' => [
            'intune.view' => 'View Intune templates, bundles, deployments and tenant Intune status',
            'intune.manage' => 'Create, edit and delete Intune bundles',
            'intune.deploy' => 'Preview and deploy Intune bundles to tenants',
        ],
        'Compliance' => [
            'compliance.manage' => 'Create compliance profiles: choose frameworks and in-scope controls, assign to tenants',
            'compliance.attest' => 'Record manual attestations for framework requirements',
        ],
        'Operations' => [
            'staff.onboard' => 'Onboard new staff in client tenants (create accounts, licences, groups)',
            'staff.offboard' => 'Offboard leavers in client tenants (block, revoke, remove access, devices)',
        ],
        'Data security (Purview)' => [
            'purview.view' => 'View Purview programmes: requirements, design, plan and progress',
            'purview.manage' => 'Capture requirements, edit designs, update tasks, run Purview automation and upload verification',
            'purview.approve' => 'Approve Purview designs and sign off phase gates (compliance owner)',
        ],
        'Microsoft 365 Copilot' => [
            'copilot.view' => 'View Copilot programmes: readiness, plan, pilot and usage',
            'copilot.manage' => 'Run assessments, update tasks, create the pilot group, assign or remove Copilot licences, fix public groups',
            'copilot.approve' => 'Sign off Copilot gates: ready for pilot, pilot exit, rollout complete',
        ],
        'PowerShell scripts' => [
            'scripts.view' => 'View the script library, run history and results (for assigned tenants)',
            'scripts.run' => 'Run approved read-only scripts against assigned tenants',
            'scripts.run_change' => 'Run approved scripts that change tenants (needs an authenticator code each time)',
            'scripts.manage' => 'Write and edit scripts and submit them for approval',
            'scripts.approve' => 'Approve or reject script versions (cannot approve own work unless self-approval is on)',
        ],
        'Monitoring' => [
            'alerts.view' => 'View security alerts and monitoring status for assigned tenants',
            'alerts.manage' => 'Triage alerts (investigate, assign, resolve), run monitoring and configure break-glass accounts',
            'licensing.view' => 'View licence optimisation: unused seats, inactive and disabled licensed users, duplicate licences',
        ],
        'Reporting' => [
            'reports.view' => 'Open executive / delta reports and export Excel',
            'activity.view' => 'View the activity and audit log',
        ],
        'Administration' => [
            'settings.manage' => 'Branding, Microsoft app setup and sign-in options',
            'users.manage' => 'Manage TenantsGuard users and roles',
        ],
    ];

    /** Built-in roles. global_admin always has every permission and cannot be edited. */
    public const SYSTEM_ROLES = [
        'global_admin' => [
            'name' => 'Global Administrator',
            'description' => 'Full access to every tenant, setting, user and role.',
            'permissions' => ['*'],
        ],
        'administrator' => [
            'name' => 'Administrator',
            'description' => 'Manages tenants, remediation and baselines. Cannot change settings, users or roles.',
            'permissions' => ['tenants.view', 'tenants.manage', 'tenants.scan', 'fixes.apply', 'fixes.enforce', 'baselines.apply', 'baselines.manage', 'annotations.manage', 'intune.view', 'intune.manage', 'intune.deploy', 'compliance.manage', 'compliance.attest', 'staff.onboard', 'staff.offboard', 'scripts.view', 'scripts.run', 'scripts.run_change', 'purview.view', 'purview.manage', 'copilot.view', 'copilot.manage', 'alerts.view', 'alerts.manage', 'licensing.view', 'reports.view', 'activity.view'],
        ],
        'technician' => [
            'name' => 'Technician',
            'description' => 'Scans and remediates assigned tenants. Cannot enforce Conditional Access or add or remove tenants.',
            'permissions' => ['tenants.view', 'tenants.scan', 'fixes.apply', 'baselines.apply', 'annotations.manage', 'intune.view', 'intune.deploy', 'compliance.attest', 'staff.onboard', 'staff.offboard', 'scripts.view', 'scripts.run', 'purview.view', 'copilot.view', 'alerts.view', 'alerts.manage', 'licensing.view', 'reports.view', 'activity.view'],
        ],
        'read_only' => [
            'name' => 'Read-only',
            'description' => 'Views findings and reports for assigned tenants. Makes no changes.',
            'permissions' => ['tenants.view', 'intune.view', 'purview.view', 'copilot.view', 'alerts.view', 'licensing.view', 'reports.view', 'activity.view'],
        ],
    ];

    public static function all(): array
    {
        return array_merge(...array_values(array_map('array_keys', self::GROUPS)));
    }

    public static function label(string $permission): string
    {
        foreach (self::GROUPS as $perms) {
            if (isset($perms[$permission])) {
                return $perms[$permission];
            }
        }

        return $permission;
    }
}
