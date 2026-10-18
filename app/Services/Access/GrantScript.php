<?php

namespace App\Services\Access;

use App\Models\Tenant;
use App\Support\Access;
use App\Support\MicrosoftApp;

/**
 * Least-privilege alternative to the full admin consent: a PowerShell script a client administrator
 * runs to grant TenantsGuard only the app roles of the selected capabilities, and (by default) revoke
 * the roles it no longer needs. Idempotent; supports -WhatIf. Needs only Microsoft.Graph.Authentication.
 */
final class GrantScript
{
    /** Exchange Administrator role template (only for the Exchange Online capability). */
    public const EXCHANGE_ADMIN_ROLE = '29232cdf-9323-42fd-ade2-1d097af3e4de';

    public static function generate(Tenant $tenant, array $caps, ?string $author = null): string
    {
        $caps = array_values(array_unique(array_merge(['monitor'], array_intersect($caps, array_keys(Access::CAPABILITIES)))));
        $roles = Access::roles($caps);
        $q = fn (string $v) => "'".str_replace("'", "''", $v)."'";
        $list = fn (array $xs) => $xs ? "@(\n".implode(",\n", array_map(fn ($x) => '        '.$q($x), $xs))."\n    )" : '@()';
        $capLines = implode("\n", array_map(fn ($c) => '#   - '.Access::name($c).' ('.Access::TIERS[Access::tier($c)][0].')', $caps));
        $exchange = in_array('exchange', $caps, true);
        $scopes = "'Application.Read.All', 'AppRoleAssignment.ReadWrite.All'".($exchange ? ", 'RoleManagement.ReadWrite.Directory'" : '');

        $tenantId = $q((string) $tenant->ms_tenant_id);
        $appId = $q((string) MicrosoftApp::clientId());
        $graph = $q(Access::GRAPH);
        $exo = $q(Access::EXO);
        $exoRole = $q(self::EXCHANGE_ADMIN_ROLE);
        $name = str_replace(["\r", "\n", '#>'], ' ', $tenant->name);
        $by = $author ? ' by '.str_replace(["\r", "\n", '#>'], ' ', $author) : '';
        $date = now()->toDateTimeString();
        $graphList = $list($roles['graph']);
        $exoList = $list($roles['exo']);
        $exoBlock = $exchange ? <<<PS

if (\$AssignExchangeRole) {
    # Exchange.ManageAsApp also needs an Exchange role. Exchange Administrator is the simple option;
    # for least privilege, use Exchange RBAC for applications (New-ManagementRoleAssignment with a scope) instead.
    \$has = Invoke-MgGraphRequest -Method GET -Uri "v1.0/roleManagement/directory/roleAssignments?\`\$filter=principalId eq '\$(\$sp.id)' and roleDefinitionId eq \$ExchangeAdminRole"
    if (\$has.value.Count -gt 0) {
        \$summary.Add([ordered]@{ Resource = 'Entra ID'; Permission = 'Exchange Administrator'; Result = 'Already assigned' })
    } elseif (\$PSCmdlet.ShouldProcess('Exchange Administrator', 'Assign directory role to TenantsGuard')) {
        Invoke-MgGraphRequest -Method POST -Uri 'v1.0/roleManagement/directory/roleAssignments' -Body @{ principalId = \$sp.id; roleDefinitionId = \$ExchangeAdminRole; directoryScopeId = '/' } | Out-Null
        \$summary.Add([ordered]@{ Resource = 'Entra ID'; Permission = 'Exchange Administrator'; Result = 'Assigned' })
    }
}
PS : '';

        return <<<PS
<#
    TenantsGuard least-privilege permission grant
    Client:     {$name}
    Generated:  {$date}{$by}

    Grants the TenantsGuard app ONLY the permissions of these capabilities:
{$capLines}

    By default it also REVOKES Microsoft Graph and Exchange Online permissions that are not listed,
    so the app keeps least privilege. Use -KeepOthers to only add.

    Run as a Global Administrator or Privileged Role Administrator of this client.
    Preview first:   ./Grant-TenantsGuard.ps1 -WhatIf
    Requires:        Install-Module Microsoft.Graph.Authentication -Scope CurrentUser
#>
[CmdletBinding(SupportsShouldProcess)]
param(
    [switch] \$KeepOthers,
    [switch] \$AssignExchangeRole
)
\$ErrorActionPreference = 'Stop'

\$TenantId = {$tenantId}
\$AppId = {$appId}
\$ExchangeAdminRole = {$exoRole}
\$Wanted = [ordered]@{
    {$graph} = {$graphList}
    {$exo} = {$exoList}
}

Import-Module Microsoft.Graph.Authentication
Connect-MgGraph -TenantId \$TenantId -Scopes {$scopes} -NoWelcome
if ((Get-MgContext).TenantId -ne \$TenantId) {
    throw "Signed in to tenant \$((Get-MgContext).TenantId), expected \$TenantId. Nothing was changed."
}

# The TenantsGuard service principal in this tenant.
\$sp = (Invoke-MgGraphRequest -Method GET -Uri "v1.0/servicePrincipals?\`\$filter=appId eq '\$AppId'").value | Select-Object -First 1
if (-not \$sp) {
    if (\$PSCmdlet.ShouldProcess(\$AppId, 'Create the TenantsGuard service principal')) {
        \$sp = Invoke-MgGraphRequest -Method POST -Uri 'v1.0/servicePrincipals' -Body @{ appId = \$AppId }
    } else {
        Write-Warning 'The TenantsGuard service principal does not exist yet; -WhatIf cannot show the role changes.'
        return
    }
}

\$assigned = @((Invoke-MgGraphRequest -Method GET -Uri "v1.0/servicePrincipals/\$(\$sp.id)/appRoleAssignments").value)
\$summary = [System.Collections.Generic.List[object]]::new()

foreach (\$resourceAppId in \$Wanted.Keys) {
    \$resource = (Invoke-MgGraphRequest -Method GET -Uri "v1.0/servicePrincipals?\`\$filter=appId eq '\$resourceAppId'&\`\$select=id,displayName,appRoles").value | Select-Object -First 1
    if (-not \$resource) { continue }
    \$want = @(\$Wanted[\$resourceAppId])

    foreach (\$perm in \$want) {
        \$role = \$resource.appRoles | Where-Object { \$_.value -eq \$perm -and \$_.allowedMemberTypes -contains 'Application' } | Select-Object -First 1
        if (-not \$role) {
            \$summary.Add([ordered]@{ Resource = \$resource.displayName; Permission = \$perm; Result = 'Not found' })
            continue
        }
        if (\$assigned | Where-Object { \$_.resourceId -eq \$resource.id -and \$_.appRoleId -eq \$role.id }) {
            \$summary.Add([ordered]@{ Resource = \$resource.displayName; Permission = \$perm; Result = 'Already granted' })
        } elseif (\$PSCmdlet.ShouldProcess(\$perm, 'Grant application permission')) {
            Invoke-MgGraphRequest -Method POST -Uri "v1.0/servicePrincipals/\$(\$sp.id)/appRoleAssignments" -Body @{ principalId = \$sp.id; resourceId = \$resource.id; appRoleId = \$role.id } | Out-Null
            \$summary.Add([ordered]@{ Resource = \$resource.displayName; Permission = \$perm; Result = 'Granted' })
        }
    }

    if (-not \$KeepOthers) {
        foreach (\$a in @(\$assigned | Where-Object { \$_.resourceId -eq \$resource.id })) {
            \$perm = (\$resource.appRoles | Where-Object { \$_.id -eq \$a.appRoleId } | Select-Object -First 1).value
            if (\$want -notcontains \$perm -and \$PSCmdlet.ShouldProcess("\$perm", 'Revoke application permission')) {
                Invoke-MgGraphRequest -Method DELETE -Uri "v1.0/servicePrincipals/\$(\$sp.id)/appRoleAssignments/\$(\$a.id)" | Out-Null
                \$summary.Add([ordered]@{ Resource = \$resource.displayName; Permission = \$perm; Result = 'Revoked' })
            }
        }
    }
}
{$exoBlock}
\$summary | ForEach-Object { [PSCustomObject]\$_ } | Format-Table -AutoSize
Write-Host 'Done. In TenantsGuard, open the client > Access and permissions and select Check grant.'
Disconnect-MgGraph | Out-Null

PS;
    }
}
