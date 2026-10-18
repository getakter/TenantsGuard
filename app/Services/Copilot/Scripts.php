<?php

namespace App\Services\Copilot;

use App\Models\CopilotProject;
use App\Services\Purview\Scripts as PurviewScripts;

/**
 * SharePoint Online and Teams PowerShell for what Graph cannot configure: data access
 * governance reports, Restricted Content Discovery, Restricted SharePoint Search, default
 * sharing links and the Teams meeting policy. Run interactively by an administrator.
 */
final class Scripts
{
    public static function filename(CopilotProject $p, string $phase): string
    {
        return 'TG-Copilot-'.preg_replace('/[^A-Za-z0-9]+/', '', $p->tenant->name).'-'.$phase.'.ps1';
    }

    public static function build(CopilotProject $p, string $phase): string
    {
        $r = $p->requirements;
        $q = [PurviewScripts::class, 'q'];
        $t = $p->tenant;
        $prefix = explode('.', (string) $t->domain)[0];
        $body = match ($phase) {
            'prepare' => self::prepare($r, $q),
            'rollout' => self::section('Turn off Restricted SharePoint Search', "    Set-SPOTenantRestrictedSearchMode -Mode Disabled\n    'disabled'\n"),
            'adopt' => self::reports(),
            default => throw new \InvalidArgumentException('Unknown phase'),
        };
        $needsTeams = $phase === 'prepare' && ($r['meetings'] ?? 'with_transcript') !== 'off';

        return "<#\n    TenantsGuard - Microsoft 365 Copilot ".ucfirst($phase)." script\n    Client: {$t->name} ({$t->domain})\n    Generated ".now()->toDateTimeString()."\n\n"
            ."    Run in PowerShell 7 as a SharePoint and Teams administrator of this client.\n"
            .'    Preview first: .\\'.self::filename($p, $phase)." -SharePointAdminUrl https://{$prefix}-admin.sharepoint.com -WhatIf\n"
            .'    Requires: Install-Module Microsoft.Online.SharePoint.PowerShell'.($needsTeams ? ', MicrosoftTeams' : '')."\n"
            ."    Safe to re-run.\n#>\n"
            ."[CmdletBinding(SupportsShouldProcess = \$true)]\n"
            ."param(\n    [Parameter(Mandatory = \$true)][ValidatePattern('^https://[a-z0-9-]+-admin\\.sharepoint\\.(com|us|de|cn)/?$')][string]\$SharePointAdminUrl\n)\n\n"
            ."\$ErrorActionPreference = 'Stop'\n"
            .'$ExpectedTenantId = '.$q($t->ms_tenant_id)."\n"
            ."Start-Transcript -Path (Join-Path \$PSScriptRoot ('tenantsguard-copilot-{$phase}-' + (Get-Date -Format 'yyyyMMdd-HHmmss') + '.log')) | Out-Null\n"
            ."\$Results = [System.Collections.Generic.List[object]]::new()\n"
            ."function Step([string]\$Name, [scriptblock]\$Action) {\n"
            ."    if (-not \$PSCmdlet.ShouldProcess(\$Name)) { \$Results.Add([pscustomobject]@{ Step = \$Name; Result = 'Preview' }); return }\n"
            ."    try { \$d = & \$Action; \$Results.Add([pscustomobject]@{ Step = \$Name; Result = 'OK'; Detail = \"\$d\" }); Write-Host \"OK    \$Name \$d\" -ForegroundColor Green }\n"
            ."    catch { \$Results.Add([pscustomobject]@{ Step = \$Name; Result = 'FAILED'; Detail = \$_.Exception.Message }); Write-Warning \"FAILED \$Name : \$(\$_.Exception.Message)\" }\n"
            ."}\n\n"
            ."Import-Module Microsoft.Online.SharePoint.PowerShell -UseWindowsPowerShell -WarningAction SilentlyContinue\n"
            ."Connect-SPOService -Url \$SharePointAdminUrl\n"
            .($needsTeams ? "Import-Module MicrosoftTeams\n\$teams = Connect-MicrosoftTeams\n"
                ."if (\$ExpectedTenantId -and \$teams.TenantId -and (\$teams.TenantId -ne \$ExpectedTenantId)) { Stop-Transcript | Out-Null; throw \"Wrong tenant: connected to \$(\$teams.TenantId), this script is for {$t->name}. Nothing was changed.\" }\n" : '')
            ."Write-Host 'Connected to {$t->name}' -ForegroundColor Cyan\n\n"
            .$body
            ."\n\$Results | Format-Table -AutoSize\n"
            ."if (@(\$Results | Where-Object Result -eq 'FAILED').Count) { Write-Warning 'Some steps failed. Fix them and run the script again.' } else { Write-Host 'Done. Mark the tasks in TenantsGuard and re-run the assessment.' -ForegroundColor Green }\n"
            ."Stop-Transcript | Out-Null\n";
    }

    private static function section(string $name, string $code): string
    {
        return 'Step '.PurviewScripts::q($name)." {\n{$code}}\n";
    }

    private static function reports(): string
    {
        $s = "# Data access governance (SharePoint Advanced Management) oversharing snapshots\n";
        foreach (['EveryoneExceptExternalUsersAtSite' => 'Sites shared with Everyone except external users', 'SharingLinks_Anyone' => 'Anyone links', 'SharingLinks_PeopleInYourOrg' => 'People in your organisation links'] as $entity => $label) {
            $s .= self::section('Oversharing report: '.$label, "    Start-SPODataAccessGovernanceInsight -ReportEntity {$entity} -Workload SharePoint -ReportType Snapshot -Name ".PurviewScripts::q('TG Copilot - '.$label)." | Out-Null\n    'requested (results in SharePoint admin center > Reports > Data access governance)'\n");
        }

        return $s;
    }

    private static function prepare(array $r, callable $q): string
    {
        $s = "# ------------------------------------------------------------------ SharePoint and OneDrive\n".self::reports();
        if ($r['fix_links'] ?? true) {
            $s .= self::section('Default sharing link: people in the organisation, view only', "    Set-SPOTenant -DefaultSharingLinkType Internal -DefaultLinkPermission View\n    'set'\n");
        }
        $approach = $r['data_approach'] ?? 'balanced';
        if ($approach !== 'standard') {
            foreach (Catalog::lines($r['sensitive_sites'] ?? '') as $url) {
                $s .= self::section('Restricted Content Discovery: '.$url, '    Set-SPOSite -Identity '.$q($url)." -RestrictContentOrgWideSearch \$true\n    'hidden from Copilot and organisation-wide search'\n");
            }
        }
        if ($approach === 'strict') {
            $allowed = array_slice(Catalog::lines($r['allowed_sites'] ?? ''), 0, 100);
            $s .= self::section('Restricted SharePoint Search (pilot)', "    Set-SPOTenantRestrictedSearchMode -Mode Enabled\n"
                .($allowed ? '    Add-SPOTenantRestrictedSearchAllowedList -SitesList @('.implode(', ', array_map($q, $allowed)).")\n" : "    Write-Warning 'No allowed sites in the requirements: only users\\' own content and recently used sites are searchable.'\n")
                ."    'enabled with ".count($allowed)." allowed site(s)'\n");
        }
        if (($r['meetings'] ?? 'with_transcript') !== 'off') {
            $with = ($r['meetings'] ?? '') === 'with_transcript';
            $s .= "\n# ------------------------------------------------------------------ Microsoft Teams\n"
                .self::section('Teams meeting policy: Copilot '.($with ? 'with transcription' : 'without transcript'),
                    '    Set-CsTeamsMeetingPolicy -Identity Global -Copilot '.($with ? 'EnabledWithTranscript -AllowTranscription $true' : 'Enabled')."\n    'set on the Global policy'\n");
        }

        return $s;
    }
}
