<?php

namespace App\Services\Demo;

use App\Models\Tenant;
use App\Services\Checks\Catalog;
use App\Services\Checks\CheckContext;
use App\Services\Intune\IntuneCatalog;
use App\Services\Scanner;
use Carbon\Carbon;

/**
 * Simulated Microsoft Secure Score for demo tenants, derived from the demo tenant's real state so
 * that fixes and Intune deployments made in TenantsGuard raise the score like they would in Microsoft 365.
 */
final class DemoSecureScore
{
    /**
     * [name, title, category, max, service, impact, cost, source]
     * source: 'check:ID' (TenantsGuard posture check passes), 'intune:KEY' (template deployed), or a fixed fraction.
     */
    private const ACTIONS = [
        ['AdminMFAV2', 'Ensure multifactor authentication is enabled for all users in administrative roles', 'Identity', 10, 'AzureAD', 'Low', 'Low', 'check:ID-09'],
        ['MFARegistrationV2', 'Ensure all users can complete multifactor authentication', 'Identity', 9, 'AzureAD', 'Moderate', 'Moderate', 'check:ID-08'],
        ['BlockLegacyAuthentication', 'Enable Conditional Access policies to block legacy authentication', 'Identity', 8, 'AzureAD', 'Moderate', 'Moderate', 'check:ID-04'],
        ['aad_phishing_MFA_strength', 'Ensure phishing-resistant MFA strength is required for administrators', 'Identity', 8, 'AzureAD', 'Moderate', 'Moderate', 'check:ID-14'],
        ['PWAgePolicyNew', "Set 'Password expiration policy' to 'Set passwords to never expire'", 'Identity', 8, 'AzureAD', 'Low', 'Low', 'check:ID-15'],
        ['SigninRiskPolicy', 'Turn on sign-in risk policy', 'Identity', 7, 'AzureAD', 'Moderate', 'Moderate', 'check:ID-06'],
        ['UserRiskPolicy', 'Turn on user risk policy', 'Identity', 7, 'AzureAD', 'Moderate', 'Moderate', 'check:ID-07'],
        ['IntegratedApps', 'Do not allow users to grant consent to unreliable applications', 'Identity', 4, 'AzureAD', 'Moderate', 'Low', 'check:APP-02'],
        ['aad_admin_accounts_separate_unassigned_cloud_only', 'Ensure administrative accounts are separate, unassigned and cloud-only', 'Identity', 3, 'AzureAD', 'Low', 'Moderate', 'check:ID-11'],
        ['RoleOverlap', 'Use least privileged administrative roles', 'Identity', 1, 'AzureAD', 'Low', 'Low', 'check:ID-10'],
        ['OneAdmin', 'Designate more than one global admin', 'Identity', 1, 'AzureAD', 'Low', 'Low', 1.0],
        ['SelfServicePasswordReset', 'Enable self-service password reset', 'Identity', 1, 'AzureAD', 'Moderate', 'Low', 0.0],
        ['mip_search_auditlog', 'Ensure audit log search is turned on', 'Data', 3, 'MIP', 'Low', 'Low', 1.0],
        ['spo_anonymous_links', "Do not allow anonymous 'Anyone' sharing links in SharePoint and OneDrive", 'Data', 3, 'SPO', 'Moderate', 'Low', 'check:COL-03'],
        ['exo_dkim', 'Ensure that DKIM is enabled for all Exchange Online domains', 'Data', 5, 'EXO', 'Low', 'Low', 'check:EML-02'],
        ['exo_dmarc', 'Ensure DMARC records with a reject or quarantine policy are published', 'Data', 5, 'EXO', 'Low', 'Low', 'check:EML-03'],
        ['mdo_safelinksforOfficeApps', 'Turn on Safe Links for Office apps', 'Apps', 9, 'MDO', 'Low', 'Low', 0.45],
        ['mdo_safeattachments', 'Ensure Safe Attachments policy is enabled', 'Apps', 8, 'MDO', 'Low', 'Low', 0.0],
        ['aad_guest_restricted', 'Restrict guest user access to directory objects', 'Apps', 2, 'AzureAD', 'Low', 'Low', 'check:COL-01'],
        ['scid_bitlocker', 'Turn on BitLocker drive encryption for fixed and OS drives', 'Device', 10, 'MDATP', 'Low', 'Moderate', 'intune:WIN-SEC-02'],
        ['scid_firewall', 'Turn on Microsoft Defender Firewall', 'Device', 8, 'MDATP', 'Low', 'Low', 'intune:WIN-SEC-03'],
        ['scid_asr_lsass', 'Block credential stealing from the Windows local security authority subsystem', 'Device', 9, 'MDATP', 'Low', 'Low', 'intune:WIN-SEC-04'],
        ['scid_laps', 'Enable Local Administrator Password Solution (LAPS)', 'Device', 8, 'MDATP', 'Low', 'Moderate', 'intune:WIN-SEC-05'],
        ['scid_rtp', 'Turn on real-time protection', 'Device', 9, 'MDATP', 'Low', 'Low', 'intune:WIN-SEC-01'],
    ];

    /** Fraction (0..1) achieved per action name. */
    private static function achieved(Tenant $tenant): array
    {
        $ctx = new CheckContext($tenant, new MockGraphClient($tenant), new MockDnsResolver($tenant));
        $s = $tenant->demo_state;
        $deployed = collect(array_merge($s['deviceConfigurations'] ?? [], $s['compliancePolicies'] ?? []))->pluck('displayName')->all();
        $out = [];
        foreach (self::ACTIONS as [$name, , , , , , , $source]) {
            if (is_float($source)) {
                $out[$name] = $source;
            } elseif (str_starts_with($source, 'check:')) {
                $check = Catalog::find(substr($source, 6));
                $status = Scanner::evaluate($check, $ctx)['status'];
                $out[$name] = $status === 'pass' ? 1.0 : 0.0;
            } else {
                $tpl = IntuneCatalog::find(substr($source, 7));
                $out[$name] = in_array(IntuneCatalog::PREFIX.$tpl['name'], $deployed, true) ? 1.0 : 0.0;
            }
        }

        return $out;
    }

    /** /security/secureScores: 90 daily entries, newest first. */
    public static function scores(Tenant $tenant): array
    {
        $achieved = self::achieved($tenant);
        $controlScores = [];
        $current = 0.0;
        $max = 0.0;
        foreach (self::ACTIONS as [$name, $title, $cat, $points]) {
            $score = round($points * $achieved[$name], 2);
            $current += $score;
            $max += $points;
            $controlScores[] = ['controlName' => $name, 'controlCategory' => $cat, 'score' => $score, 'description' => $title];
        }

        $users = count($tenant->demo_state['users'] ?? []);
        $comparatives = [
            ['basis' => 'AllTenants', 'averageScore' => round($max * 0.41, 2)],
            ['basis' => 'TotalSeats', 'averageScore' => round($max * 0.47, 2)],
            ['basis' => 'IndustryTypes', 'averageScore' => round($max * 0.44, 2)],
        ];

        $entries = [];
        $start = max(0, $current - 12);
        for ($d = 0; $d < 90; $d++) {
            $date = Carbon::today()->subDays($d);
            $value = $d === 0 ? $current : round(max(0, $start + ($current - $start) * (89 - $d) / 89 + sin(($d + $tenant->id) / 5) * 1.2), 2);
            $entries[] = [
                'id' => $tenant->ms_tenant_id.'_'.$date->format('Y-m-d'),
                'azureTenantId' => $tenant->ms_tenant_id,
                'createdDateTime' => $date->copy()->setTime(6, 0)->toIso8601ZuluString(),
                'currentScore' => min($value, $max),
                'maxScore' => $max,
                'licensedUserCount' => $users,
                'activeUserCount' => max(0, $users - 2),
                'enabledServices' => ['HasExchange', 'HasSharePoint', 'HasOD4B', 'HasMDO', 'HasMDATP'],
                'averageComparativeScores' => $comparatives,
                'controlScores' => $d === 0 ? $controlScores : [],
            ];
        }

        return $entries;
    }

    /** /security/secureScoreControlProfiles */
    public static function profiles(): array
    {
        return array_map(fn ($a) => [
            'id' => $a[0],
            'title' => $a[1],
            'controlCategory' => $a[2],
            'maxScore' => $a[3],
            'service' => $a[4],
            'userImpact' => $a[5],
            'implementationCost' => $a[6],
            'rank' => array_search($a, self::ACTIONS, true) + 1,
            'deprecated' => false,
            'actionUrl' => 'https://security.microsoft.com/securescore?viewid=actions',
            'remediation' => 'See the improvement action in the Microsoft Defender portal for step-by-step instructions.',
            'controlStateUpdates' => $a[0] === 'SelfServicePasswordReset' ? [['state' => 'Ignored', 'comment' => 'Handled by MSP helpdesk']] : [],
        ], self::ACTIONS);
    }
}
