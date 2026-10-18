<?php

namespace App\Services\SecureScore;

/**
 * Maps Microsoft Secure Score improvement actions to TenantsGuard controls, which in turn map to
 * compliance frameworks. Exact control names are matched first; otherwise keyword rules on the
 * action title are used, so new or renamed Microsoft actions are still recognised.
 */
final class SecureScoreMap
{
    /** Secure Score controlName => TenantsGuard control IDs */
    private const BY_NAME = [
        'AdminMFAV2' => ['ID-03', 'ID-09'],
        'MFARegistrationV2' => ['ID-08', 'ID-02'],
        'BlockLegacyAuthentication' => ['ID-04'],
        'OneAdmin' => ['ID-10'],
        'RoleOverlap' => ['ID-10'],
        'aad_limited_administrative_roles' => ['ID-10'],
        'aad_admin_accounts_separate_unassigned_cloud_only' => ['ID-11'],
        'IntegratedApps' => ['APP-02', 'APP-03'],
        'PWAgePolicyNew' => ['ID-15'],
        'SigninRiskPolicy' => ['ID-06'],
        'UserRiskPolicy' => ['ID-07'],
        'aad_phishing_MFA_strength' => ['ID-14'],
        'aad_sign_in_freq_session_timeout' => ['ID-05'],
        'mip_search_auditlog' => ['TEN-02'],
    ];

    /** Keyword rules on the lower-cased action title => controls (first match wins). */
    private const RULES = [
        ['/phishing.resistant|fido|passkey/', ['ID-14']],
        ['/multifactor|multi-factor|\bmfa\b/', ['ID-02', 'ID-08']],
        ['/legacy authentication|basic authentication/', ['ID-04']],
        ['/global admin|least privileged administrative|administrative roles/', ['ID-10']],
        ['/consent to (unreliable|unmanaged)|user consent|third.party (integrated )?app/', ['APP-02']],
        ['/password.*(expire|expiration)/', ['ID-15']],
        ['/sign-in risk/', ['ID-06']],
        ['/user risk/', ['ID-07']],
        ['/dmarc/', ['EML-03']],
        ['/dkim/', ['EML-02']],
        ['/\bspf\b/', ['EML-01']],
        ['/audit (log|data)|auditing/', ['TEN-02']],
        ['/external (sender|email).*(tag|identif)/', ['TEN-03']],
        ['/guest (user )?access|guest invit/', ['COL-01', 'COL-02']],
        // SharePoint/OneDrive only: Teams "anonymous join" and calendar "external sharing" are different settings.
        ['/(sharepoint|onedrive).*(anonymous|anyone|external sharing|sharing)|(anonymous|anyone).*(sharing )?links?/', ['COL-03']],
        ['/bitlocker|encrypt.*(drive|disk)/', ['WIN-SEC-02']],
        ['/firewall/', ['WIN-SEC-03']],
        ['/attack surface reduction|\basr\b|block (office|executable|javascript|credential stealing|untrusted)/', ['WIN-SEC-04']],
        ['/local administrator password|\blaps\b/', ['WIN-SEC-05']],
        ['/credential guard|lsa protection|lsass/', ['WIN-SEC-06']],
        ['/windows hello/', ['WIN-CFG-02']],
        ['/smartscreen|screen saver|inactivity|machine inactivity/', ['WIN-CFG-01']],
        ['/real-time protection|cloud-delivered protection|potentially unwanted|network protection|antivirus|behavior monitoring/', ['WIN-SEC-01']],
        ['/update .*(windows|operating system)|operating system.*update|security updates/', ['WIN-UPD-01', 'WIN-UPD-02']],
        ['/compliance polic/', ['DEV-01']],
        ['/app protection|managed apps/', ['IOS-APP-01', 'AND-APP-01']],
    ];

    /** @return string[] TenantsGuard control IDs addressing this Secure Score action */
    public static function controlsFor(string $name, string $title): array
    {
        if (isset(self::BY_NAME[$name])) {
            return self::BY_NAME[$name];
        }
        $t = strtolower($title);
        foreach (self::RULES as [$re, $controls]) {
            if (preg_match($re, $t)) {
                return $controls;
            }
        }

        return [];
    }
}
