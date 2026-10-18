<?php

namespace App\Services\Purview;

use App\Models\PurviewProject;

/**
 * Generates Security & Compliance PowerShell for each phase from the approved design.
 * Scripts are run interactively by an administrator (delegated, MFA) so TenantsGuard needs
 * no standing compliance rights. Every script: refuses the wrong tenant, is idempotent,
 * supports -WhatIf, writes a transcript and prints a pass/fail summary.
 */
final class Scripts
{
    public const RIGHTS = 'VIEW,VIEWRIGHTSDATA,DOCEDIT,EDIT,PRINT,EXTRACT,REPLY,REPLYALL,FORWARD,OBJMODEL';

    public static function q(?string $s): string
    {
        return "'".str_replace("'", "''", (string) $s)."'";
    }

    private static function arr(array $items): string
    {
        return '@('.implode(', ', array_map([self::class, 'q'], $items)).')';
    }

    public static function build(PurviewProject $project, string $phase, array $domains): string
    {
        $d = $project->design;
        $body = match ($phase) {
            'foundations' => self::foundations($project, $d),
            'discover' => self::discover($d, $domains),
            'pilot' => self::pilot($project, $d),
            'production' => self::production($d),
            'verify' => self::verify(),
            default => throw new \InvalidArgumentException('Unknown phase'),
        };

        return self::header($project, $phase, in_array($phase, ['foundations', 'verify'], true)).$body.self::footer($phase);
    }

    private static function header(PurviewProject $p, string $phase, bool $exchange): string
    {
        $t = $p->tenant;
        $title = $phase === 'verify' ? 'verification export' : (Catalog::STAGES[$phase][0] ?? $phase).' phase';

        return "<#\n"
            ."    TenantsGuard - Microsoft Purview {$title}\n"
            ."    Client:   {$t->name} ({$t->domain})\n"
            ."    Project:  {$p->name}\n"
            .'    Design:   approved by '.($p->approvedBy->name ?? 'not approved').' on '.($p->design_approved_at?->toDateTimeString() ?? '-').' (design hash '.substr((string) $p->design_hash, 0, 12).")\n"
            .'    Generated '.now()->toDateTimeString()."\n\n"
            ."    Run in PowerShell 7 as a Purview / Compliance administrator of this client.\n"
            .'    Preview first:  .\\'.self::filename($p, $phase)." -AdminUpn admin@{$t->domain} -WhatIf\n"
            ."    Requires:       Install-Module ExchangeOnlineManagement -Scope CurrentUser\n"
            ."    Safe to re-run: every step checks what already exists.\n"
            ."#>\n"
            ."[CmdletBinding(SupportsShouldProcess = \$true)]\n"
            ."param(\n    [Parameter(Mandatory = \$true)][string]\$AdminUpn\n)\n\n"
            ."\$ErrorActionPreference = 'Stop'\n"
            .'$ExpectedTenantId = '.self::q($t->ms_tenant_id)."\n"
            .'$PilotGroup = '.self::q(($p->design['pilot']['mail_nickname'] ?? 'purviewpilot').'@'.$t->domain)."\n"
            .'$IncidentEmail = '.self::q($p->requirements['owner_email'] ?? '')."\n"
            ."\$log = Join-Path \$PSScriptRoot ('tenantsguard-purview-{$phase}-' + (Get-Date -Format 'yyyyMMdd-HHmmss') + '.log')\n"
            ."Start-Transcript -Path \$log | Out-Null\n"
            ."\$Results = [System.Collections.Generic.List[object]]::new()\n\n"
            ."function Step([string]\$Name, [scriptblock]\$Action) {\n"
            ."    if (-not \$PSCmdlet.ShouldProcess(\$Name)) { \$Results.Add([pscustomobject]@{ Step = \$Name; Result = 'Preview'; Detail = '' }); return }\n"
            ."    try {\n"
            ."        \$detail = & \$Action\n"
            ."        \$Results.Add([pscustomobject]@{ Step = \$Name; Result = 'OK'; Detail = \"\$detail\" })\n"
            ."        Write-Host \"OK    \$Name \$detail\" -ForegroundColor Green\n"
            ."    } catch {\n"
            ."        \$Results.Add([pscustomobject]@{ Step = \$Name; Result = 'FAILED'; Detail = \$_.Exception.Message })\n"
            ."        Write-Warning \"FAILED \$Name : \$(\$_.Exception.Message)\"\n"
            ."    }\n"
            ."}\n\n"
            ."function Get-TGSits([string[]]\$Names, [int]\$Min, [int]\$Max) {\n"
            ."    # Only SITs that exist in this tenant are used; missing ones are reported, not fatal.\n"
            ."    \$list = foreach (\$n in \$Names) {\n"
            ."        if (Get-DlpSensitiveInformationType -Identity \$n -ErrorAction SilentlyContinue) { @{ Name = \$n; minCount = \"\$Min\"; maxCount = \"\$Max\" } }\n"
            ."        else { Write-Warning \"Sensitive information type not found and skipped: \$n\" }\n"
            ."    }\n"
            ."    if (-not \$list) { throw 'None of the sensitive information types exist in this tenant.' }\n"
            ."    return ,@(\$list)\n"
            ."}\n\n"
            ."Import-Module ExchangeOnlineManagement -ErrorAction Stop\n"
            ."Connect-IPPSSession -UserPrincipalName \$AdminUpn -ShowBanner:\$false\n"
            .($exchange ? "Connect-ExchangeOnline -UserPrincipalName \$AdminUpn -ShowBanner:\$false\n" : '')
            ."\$conn = Get-ConnectionInformation | Select-Object -First 1\n"
            ."if (\$ExpectedTenantId -and \$conn.TenantID -and (\$conn.TenantID -ne \$ExpectedTenantId)) {\n"
            ."    Stop-Transcript | Out-Null\n"
            ."    throw \"Wrong tenant: connected to \$(\$conn.TenantID) but this script is for {$t->name} (\$ExpectedTenantId). Nothing was changed.\"\n"
            ."}\n"
            ."Write-Host \"Connected to {$t->name} as \$AdminUpn\" -ForegroundColor Cyan\n\n";
    }

    private static function footer(string $phase): string
    {
        if ($phase === 'verify') {
            return "Stop-Transcript | Out-Null\n";
        }

        return "\n# ------------------------------------------------------------------ summary\n"
            ."\$Results | Format-Table -AutoSize\n"
            ."\$failed = @(\$Results | Where-Object Result -eq 'FAILED').Count\n"
            ."if (\$failed) { Write-Warning \"\$failed step(s) failed. Fix them and run the script again; completed steps are skipped.\" }\n"
            ."else { Write-Host 'All steps completed. Run the verification script and upload its JSON to TenantsGuard.' -ForegroundColor Green }\n"
            ."Stop-Transcript | Out-Null\n";
    }

    public static function filename(PurviewProject $p, string $phase): string
    {
        return 'TG-Purview-'.preg_replace('/[^A-Za-z0-9]+/', '', $p->tenant->name).'-'.$phase.'.ps1';
    }

    // ------------------------------------------------------------------ phases

    private static function foundations(PurviewProject $p, array $d): string
    {
        $s = "# ------------------------------------------------------------------ audit\n"
            ."Step 'Unified audit log ingestion' {\n"
            ."    if ((Get-AdminAuditLogConfig).UnifiedAuditLogIngestionEnabled) { 'already on' }\n"
            ."    else { Set-AdminAuditLogConfig -UnifiedAuditLogIngestionEnabled \$true; 'turned on (can take up to 60 minutes)' }\n"
            ."}\n\n";
        $encrypt = collect($d['labels'])->contains(fn ($l) => $l['include'] && $l['encryption'] !== 'none');
        $containers = collect($d['labels'])->contains(fn ($l) => $l['include'] && $l['container']);
        if ($encrypt) {
            $s .= "Step 'Co-authoring for encrypted files' {\n"
                ."    if ((Get-PolicyConfig).EnableLabelCoauth) { 'already on' } else { Set-PolicyConfig -EnableLabelCoauth \$true; 'turned on' }\n"
                ."}\n\n";
        }
        $prefix = explode('.', (string) $p->tenant->domain)[0];
        $s .= "# ------------------------------------------------------------------ SharePoint and OneDrive (optional module)\n"
            ."if (Get-Module -ListAvailable Microsoft.Online.SharePoint.PowerShell) {\n"
            ."    \$spoAdmin = Read-Host 'SharePoint admin URL (for example https://{$prefix}-admin.sharepoint.com)'\n"
            ."    Step 'Sensitivity labels for Office files in SharePoint and OneDrive' {\n"
            ."        Import-Module Microsoft.Online.SharePoint.PowerShell -UseWindowsPowerShell -WarningAction SilentlyContinue\n"
            ."        Connect-SPOService -Url \$spoAdmin\n"
            ."        if ((Get-SPOTenant).EnableAIPIntegration) { 'already on' } else { Set-SPOTenant -EnableAIPIntegration \$true; 'turned on' }\n"
            ."    }\n"
            ."} else {\n"
            ."    Write-Warning 'SharePoint Online Management Shell not installed: turn on labels for files in the Purview portal (Information protection > Sensitivity labels > Turn on now).'\n"
            ."}\n\n";
        if ($containers) {
            $s .= "# ------------------------------------------------------------------ labels for Teams, groups and sites\n"
                ."if (Get-Module -ListAvailable Microsoft.Graph.Authentication) {\n"
                ."    Step 'Allow sensitivity labels on Microsoft 365 groups (EnableMIPLabels)' {\n"
                ."        Connect-MgGraph -Scopes 'Directory.ReadWrite.All' -NoWelcome\n"
                ."        \$templateId = '62375ab9-6b52-47ed-826b-58e47e0e304b'  # Group.Unified\n"
                ."        \$existing = (Invoke-MgGraphRequest -Method GET -Uri 'https://graph.microsoft.com/v1.0/groupSettings').value | Where-Object templateId -eq \$templateId\n"
                ."        if (\$existing) {\n"
                ."            \$values = @(\$existing.values | Where-Object name -ne 'EnableMIPLabels') + @(@{ name = 'EnableMIPLabels'; value = 'True' })\n"
                ."            Invoke-MgGraphRequest -Method PATCH -Uri \"https://graph.microsoft.com/v1.0/groupSettings/\$(\$existing.id)\" -Body (@{ values = \$values } | ConvertTo-Json -Depth 5) -ContentType 'application/json'\n"
                ."            'updated'\n"
                ."        } else {\n"
                ."            \$body = @{ templateId = \$templateId; values = @(@{ name = 'EnableMIPLabels'; value = 'True' }) } | ConvertTo-Json -Depth 5\n"
                ."            Invoke-MgGraphRequest -Method POST -Uri 'https://graph.microsoft.com/v1.0/groupSettings' -Body \$body -ContentType 'application/json'\n"
                ."            'created'\n"
                ."        }\n"
                ."    }\n"
                ."    Step 'Synchronise labels to Entra ID' { Execute-AzureAdLabelSync; 'requested' }\n"
                ."} else {\n"
                ."    Write-Warning 'Microsoft.Graph.Authentication not installed: Install-Module Microsoft.Graph.Authentication, then re-run to enable labels for groups and sites.'\n"
                ."}\n";
        }

        return $s;
    }

    private static function discover(array $d, array $domains): string
    {
        $s = "# ------------------------------------------------------------------ sensitivity labels (created, not yet published)\n";
        $rights = implode(';', array_map(fn ($dom) => $dom.':'.self::RIGHTS, $domains ?: ['contoso.onmicrosoft.com']));
        foreach (collect($d['labels'])->where('include', true)->sortBy('priority') as $l) {
            $contentType = $l['container'] ? 'File, Email, Site, UnifiedGroup' : 'File, Email';
            $p = ['DisplayName = '.self::q($l['display']), 'Tooltip = '.self::q($l['tooltip']), "Comment = 'Created by TenantsGuard'", 'ContentType = '.self::q($contentType),
                'AdvancedSettings = @{ color = '.self::q($l['color']).' }'];
            if ($l['header']) {
                $p[] = 'ApplyContentMarkingHeaderEnabled = $true';
                $p[] = 'ApplyContentMarkingHeaderText = '.self::q($l['header']);
                $p[] = 'ApplyContentMarkingHeaderFontColor = '.self::q($l['color']);
                $p[] = "ApplyContentMarkingHeaderAlignment = 'Center'";
            }
            if ($l['watermark']) {
                $p[] = 'ApplyWaterMarkingEnabled = $true';
                $p[] = 'ApplyWaterMarkingText = '.self::q($l['watermark']);
            }
            if ($l['encryption'] === 'org') {
                $p[] = 'EncryptionEnabled = $true';
                $p[] = "EncryptionProtectionType = 'Template'";
                $p[] = 'EncryptionRightsDefinitions = '.self::q($rights);
                $p[] = "EncryptionContentExpiredOnDateInDaysOrNever = 'Never'";
                $p[] = 'EncryptionOfflineAccessDays = 7';
            } elseif ($l['encryption'] === 'user') {
                $p[] = 'EncryptionEnabled = $true';
                $p[] = "EncryptionProtectionType = 'UserDefined'";
                $p[] = 'EncryptionPromptUser = $true';
                $p[] = 'EncryptionDoNotForward = $true';
            }
            if ($l['container']) {
                $p[] = 'SiteAndGroupProtectionEnabled = $true';
                $p[] = 'SiteAndGroupProtectionPrivacy = '.self::q($l['container']['privacy']);
                $p[] = 'SiteAndGroupProtectionAllowAccessToGuestUsers = $'.($l['container']['guests'] ? 'true' : 'false');
                $p[] = 'SiteExternalSharingControlType = '.self::q($l['container']['sharing']);
            }
            if ($l['parent']) {
                $p[] = 'ParentId = (Get-Label -Identity '.self::q($l['parent']).').Guid';
            }
            $name = $l['parent'] ? $l['display'].' ('.$l['parent'].')' : $l['display'];
            $s .= 'Step '.self::q("Label: {$name}")." {\n"
                ."    \$p = @{\n        ".implode("\n        ", $p)."\n    }\n"
                .'    if (Get-Label -Identity '.self::q($l['key']).' -ErrorAction SilentlyContinue) { Set-Label -Identity '.self::q($l['key'])." @p; 'updated' }\n"
                .'    else { New-Label -Name '.self::q($l['key'])." @p | Out-Null; 'created' }\n"
                ."}\n";
        }

        $s .= "\n# ------------------------------------------------------------------ DLP in simulation (everyone, no notifications)\n";
        foreach (collect($d['dlp'])->where('include', true) as $x) {
            $s .= self::dlpPolicy($x, $x['name'], 'TestWithoutNotifications', false);
        }

        if ($auto = collect($d['autolabel'])->where('include', true)->filter(fn ($a) => ! empty($a['sits']))) {
            $s .= "\n# ------------------------------------------------------------------ service-side auto-labelling in simulation\n";
            foreach ($auto as $a) {
                $s .= 'Step '.self::q('Auto-labelling policy: '.$a['name'])." {\n"
                    .'    if (Get-AutoSensitivityLabelPolicy -Identity '.self::q($a['name'])." -ErrorAction SilentlyContinue) { 'exists' }\n"
                    ."    else {\n"
                    .'        New-AutoSensitivityLabelPolicy -Name '.self::q($a['name']).' -ApplySensitivityLabel '.self::q($a['label'])." -ExchangeLocation All -SharePointLocation All -OneDriveLocation All -Mode TestWithoutNotifications | Out-Null\n"
                    ."        foreach (\$w in 'Exchange', 'SharePoint', 'OneDriveForBusiness') {\n"
                    .'            New-AutoSensitivityLabelRule -Name ('.self::q($a['name'])." + ' - ' + \$w) -Policy ".self::q($a['name']).' -Workload $w -ContentContainsSensitiveInformation (Get-TGSits '.self::arr($a['sits'])." {$a['min_count']} -1) | Out-Null\n"
                    ."        }\n"
                    ."        'created in simulation'\n"
                    ."    }\n"
                    ."}\n";
            }
        }

        return $s;
    }

    private static function dlpPolicy(array $x, string $name, string $mode, bool $pilot): string
    {
        $loc = [];
        foreach ($x['locations'] as $l) {
            $loc[] = match ($l) {
                'Exchange' => '-ExchangeLocation All'.($pilot ? ' -ExchangeSenderMemberOf $PilotGroup' : ''),
                'SharePoint' => $pilot ? null : '-SharePointLocation All',
                'OneDrive' => '-OneDriveLocation All'.($pilot ? ' -OneDriveSharedByMemberOf $PilotGroup' : ''),
                'Teams' => $pilot ? '-TeamsLocation $PilotGroup' : '-TeamsLocation All',
                'Devices' => $pilot ? null : '-EndpointDlpLocation All',
                default => null,
            };
        }
        $loc = implode(' ', array_filter($loc));
        if ($loc === '') {
            return "# {$name}: no locations in scope.\n";
        }
        $block = $x['style'] !== 'notify';
        $high = max(1, (int) $x['high_threshold']);
        $tip = 'This content contains sensitive information ('.str_replace(Designer::PREFIX, '', $x['name']).'). Share it only with people who need it.';
        $rules = '';
        if ($high > 1) {
            $rules .= '    if (-not (Get-DlpComplianceRule -Identity '.self::q($name.' - Low volume')." -ErrorAction SilentlyContinue)) {\n"
                .'        New-DlpComplianceRule -Name '.self::q($name.' - Low volume').' -Policy '.self::q($name).' -ContentContainsSensitiveInformation (Get-TGSits '.self::arr($x['sits']).' 1 '.($high - 1).") `\n"
                .'            -NotifyUser Owner, LastModifier -NotifyPolicyTipCustomText '.self::q($tip).' -ReportSeverityLevel Low'.($x['incident_email'] ? ' -GenerateIncidentReport $IncidentEmail -IncidentReportContent All' : '')." | Out-Null\n"
                ."    }\n";
        }
        $rules .= '    if (-not (Get-DlpComplianceRule -Identity '.self::q($name.' - High volume')." -ErrorAction SilentlyContinue)) {\n"
            .'        New-DlpComplianceRule -Name '.self::q($name.' - High volume').' -Policy '.self::q($name).' -ContentContainsSensitiveInformation (Get-TGSits '.self::arr($x['sits'])." {$high} -1) `\n"
            .'            -NotifyUser Owner, LastModifier -NotifyPolicyTipCustomText '.self::q($tip).' -ReportSeverityLevel High'
            .($block ? ' -AccessScope NotInOrganization -BlockAccess $true'.($x['override'] ? ' -NotifyAllowOverride WithJustification' : '') : '')
            .($x['incident_email'] ? ' -GenerateIncidentReport $IncidentEmail -IncidentReportContent All -GenerateAlert $IncidentEmail' : '')." | Out-Null\n"
            ."    }\n";

        return 'Step '.self::q('DLP policy: '.$name)." {\n"
            .'    if (-not (Get-DlpCompliancePolicy -Identity '.self::q($name)." -ErrorAction SilentlyContinue)) {\n"
            .'        New-DlpCompliancePolicy -Name '.self::q($name).' -Comment '.self::q($x['purpose'].' Created by TenantsGuard.')." -Mode {$mode} {$loc} | Out-Null\n"
            ."    }\n"
            .$rules
            ."    'mode {$mode}'\n"
            ."}\n";
    }

    private static function pilot(PurviewProject $p, array $d): string
    {
        $labels = collect($d['labels'])->where('include', true)->sortBy('priority')->pluck('key')->all();
        $s = "# ------------------------------------------------------------------ publish labels to the pilot group\n"
            .self::labelPolicy(Designer::PREFIX.'Sensitivity labels (pilot)', $labels, '$PilotGroup', $d['label_policy'], false);

        if ($dlp = collect($d['dlp'])->where('include', true)) {
            $s .= "\n# ------------------------------------------------------------------ DLP policy tips for the pilot group\n";
            foreach ($dlp as $x) {
                $s .= self::dlpPolicy($x, $x['name'].' (pilot)', 'TestWithNotifications', true);
            }
        }
        if ($rl = collect($d['retention']['labels'])->where('include', true)->pluck('name')->all()) {
            $s .= "\n# ------------------------------------------------------------------ publish retention labels to the pilot group (Exchange)\n"
                .self::retentionLabelPolicy(Designer::PREFIX.'Retention labels (pilot)', $rl, '-ExchangeLocation $PilotGroup');
        }

        return $s;
    }

    private static function production(array $d): string
    {
        $labels = collect($d['labels'])->where('include', true)->sortBy('priority')->pluck('key')->all();
        $s = "# ------------------------------------------------------------------ labels for everyone\n"
            .self::labelPolicy(Designer::PREFIX.'Sensitivity labels (all users)', $labels, "'All'", $d['label_policy'], true)
            ."Step 'Remove the pilot label policy' {\n"
            .'    if (Get-LabelPolicy -Identity '.self::q(Designer::PREFIX.'Sensitivity labels (pilot)').' -ErrorAction SilentlyContinue) { Remove-LabelPolicy -Identity '.self::q(Designer::PREFIX.'Sensitivity labels (pilot)')." -Confirm:\$false; 'removed' } else { 'not present' }\n"
            ."}\n";

        if ($dlp = collect($d['dlp'])->where('include', true)) {
            $s .= "\n# ------------------------------------------------------------------ enforce DLP\n";
            foreach ($dlp as $x) {
                $s .= 'Step '.self::q('Enforce '.$x['name'])." {\n"
                    .'    Set-DlpCompliancePolicy -Identity '.self::q($x['name'])." -Mode Enable\n"
                    .'    if (Get-DlpCompliancePolicy -Identity '.self::q($x['name'].' (pilot)').' -ErrorAction SilentlyContinue) { Remove-DlpCompliancePolicy -Identity '.self::q($x['name'].' (pilot)')." -Confirm:\$false }\n"
                    ."    'enforced'\n"
                    ."}\n";
            }
        }
        if ($auto = collect($d['autolabel'])->where('include', true)->filter(fn ($a) => ! empty($a['sits']))) {
            $s .= "\n# ------------------------------------------------------------------ turn on auto-labelling (only after the simulation was reviewed)\n";
            foreach ($auto as $a) {
                $s .= 'Step '.self::q('Enable '.$a['name']).' { Set-AutoSensitivityLabelPolicy -Identity '.self::q($a['name'])." -Mode Enable; 'enabled' }\n";
            }
        }
        $r = $d['retention'];
        if ($r['baseline']['include']) {
            $days = $r['baseline']['years'] * 365;
            $action = $r['baseline']['delete'] ? 'KeepAndDelete' : 'Keep';
            $s .= "\n# ------------------------------------------------------------------ baseline retention\n"
                .'Step '.self::q(Designer::PREFIX.'Baseline retention ('.$r['baseline']['years'].' years)')." {\n"
                .'    $n = '.self::q(Designer::PREFIX.'Baseline retention')."\n"
                ."    if (-not (Get-RetentionCompliancePolicy -Identity \$n -ErrorAction SilentlyContinue)) {\n"
                ."        New-RetentionCompliancePolicy -Name \$n -Comment 'Created by TenantsGuard' -ExchangeLocation All -SharePointLocation All -OneDriveLocation All -ModernGroupLocation All | Out-Null\n"
                ."        New-RetentionComplianceRule -Name (\$n + ' rule') -Policy \$n -RetentionDuration {$days} -RetentionComplianceAction {$action} -ExpirationDateOption ModificationAgeInDays | Out-Null\n"
                ."        'created'\n"
                ."    } else { 'exists' }\n"
                ."}\n";
        }
        if ($r['teams']['include']) {
            $days = $r['teams']['years'] * 365;
            $s .= 'Step '.self::q(Designer::PREFIX.'Teams retention ('.$r['teams']['years'].' years)')." {\n"
                .'    $n = '.self::q(Designer::PREFIX.'Teams retention')."\n"
                ."    if (-not (Get-RetentionCompliancePolicy -Identity \$n -ErrorAction SilentlyContinue)) {\n"
                ."        New-RetentionCompliancePolicy -Name \$n -Comment 'Created by TenantsGuard' -TeamsChatLocation All -TeamsChannelLocation All | Out-Null\n"
                ."        New-RetentionComplianceRule -Name (\$n + ' rule') -Policy \$n -RetentionDuration {$days} -RetentionComplianceAction Keep | Out-Null\n"
                ."        'created'\n"
                ."    } else { 'exists' }\n"
                ."}\n";
        }
        if ($rl = collect($r['labels'])->where('include', true)->pluck('name')->all()) {
            $s .= "\n# ------------------------------------------------------------------ retention labels for everyone\n"
                .self::retentionLabelPolicy(Designer::PREFIX.'Retention labels (all users)', $rl, '-ExchangeLocation All -SharePointLocation All -OneDriveLocation All -ModernGroupLocation All')
                ."Step 'Remove the pilot retention label policy' {\n"
                .'    if (Get-RetentionCompliancePolicy -Identity '.self::q(Designer::PREFIX.'Retention labels (pilot)').' -ErrorAction SilentlyContinue) { Remove-RetentionCompliancePolicy -Identity '.self::q(Designer::PREFIX.'Retention labels (pilot)')." -Confirm:\$false; 'removed' } else { 'not present' }\n"
                ."}\n";
        }

        return $s;
    }

    private static function labelPolicy(string $name, array $labels, string $scope, array $lp, bool $production): string
    {
        $settings = ["requiredowngradejustification = '".($lp['justification'] ? 'true' : 'false')."'"];
        if ($production && $lp['mandatory']) {
            $settings[] = "mandatory = 'true'";
        }

        return 'Step '.self::q('Label policy: '.$name)." {\n"
            .'    $settings = @{ '.implode('; ', $settings)." }\n"
            .'    $default = (Get-Label -Identity '.self::q($lp['default_document']).").Guid\n"
            ."    if (\$default) { \$settings['defaultlabelid'] = \"\$default\"; \$settings['outlookdefaultlabel'] = \"\$default\" }\n"
            .'    if (Get-LabelPolicy -Identity '.self::q($name)." -ErrorAction SilentlyContinue) {\n"
            .'        Set-LabelPolicy -Identity '.self::q($name)." -Settings \$settings; 'updated'\n"
            ."    } else {\n"
            .'        New-LabelPolicy -Name '.self::q($name).' -Labels '.self::arr($labels)." -ExchangeLocation {$scope} -Settings \$settings -Comment 'Created by TenantsGuard' | Out-Null; 'created'\n"
            ."    }\n"
            ."}\n";
    }

    private static function retentionLabelPolicy(string $name, array $labels, string $locations): string
    {
        $s = 'Step '.self::q('Retention label policy: '.$name)." {\n"
            .'    if (-not (Get-RetentionCompliancePolicy -Identity '.self::q($name)." -ErrorAction SilentlyContinue)) {\n"
            .'        New-RetentionCompliancePolicy -Name '.self::q($name)." -Comment 'Created by TenantsGuard' {$locations} | Out-Null\n"
            ."    }\n";
        foreach ($labels as $l) {
            $s .= '    if (-not (Get-RetentionComplianceRule -Identity '.self::q($name.' - '.$l)." -ErrorAction SilentlyContinue)) {\n"
                .'        New-RetentionComplianceRule -Name '.self::q($name.' - '.$l).' -Policy '.self::q($name).' -PublishComplianceTag '.self::q($l)." | Out-Null\n"
                ."    }\n";
        }

        return $s."    'published'\n}\n";
    }

    private static function verify(): string
    {
        return "# Read-only: exports the current Purview configuration for TenantsGuard to compare with the design.\n"
            ."\$export = [ordered]@{\n"
            ."    kind = 'tenantsguard-purview-verification'; version = 1\n"
            ."    tenantId = \$conn.TenantID; generatedAt = (Get-Date).ToUniversalTime().ToString('o')\n"
            ."    labels = @(Get-Label | Select-Object Name, DisplayName, ParentId, @{ n = 'Encryption'; e = { [bool]\$_.EncryptionEnabled } })\n"
            ."    labelPolicies = @(Get-LabelPolicy | Select-Object Name, @{ n = 'Labels'; e = { @(\$_.Labels) } }, @{ n = 'Scope'; e = { @(\$_.ExchangeLocation | ForEach-Object { \$_.Name }) } })\n"
            ."    dlpPolicies = @(Get-DlpCompliancePolicy | Select-Object Name, Mode)\n"
            ."    autoLabelPolicies = @(Get-AutoSensitivityLabelPolicy | Select-Object Name, Mode)\n"
            ."    retentionPolicies = @(Get-RetentionCompliancePolicy | Select-Object Name, Enabled)\n"
            ."    retentionLabels = @(Get-ComplianceTag | Select-Object Name)\n"
            ."    labelCoauth = [bool](Get-PolicyConfig).EnableLabelCoauth\n"
            ."    auditEnabled = [bool](Get-AdminAuditLogConfig).UnifiedAuditLogIngestionEnabled\n"
            ."}\n"
            ."\$file = Join-Path \$PSScriptRoot 'tenantsguard-purview-verification.json'\n"
            ."\$export | ConvertTo-Json -Depth 6 | Set-Content -Path \$file -Encoding utf8\n"
            ."Write-Host \"Saved \$file. Upload it on the Implement tab in TenantsGuard.\" -ForegroundColor Green\n";
    }
}
