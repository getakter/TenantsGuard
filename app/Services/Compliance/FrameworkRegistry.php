<?php

namespace App\Services\Compliance;

use App\Services\Checks\Catalog;
use App\Services\Intune\IntuneCatalog;

/**
 * Compliance frameworks and how their requirements map to TenantsGuard controls.
 *
 * Controls = Microsoft 365 posture checks (Catalog) + Intune templates (IntuneCatalog).
 *
 * Two kinds of framework:
 *  - curated: an explicit requirement list with mapped controls (Essential Eight with maturity
 *    levels, ASD ISM guideline areas, HIPAA Security Rule). Requirements without automated
 *    controls are answered by manual attestation.
 *  - derived: requirements are built from the reference tags on each control (CIS, NIST,
 *    ISO 27001, PCI DSS, Cyber Essentials).
 *
 * Mappings are best-effort guidance for MSP assessments and must be validated before formal attestation.
 */
final class FrameworkRegistry
{
    private static ?array $frameworks = null;

    private static ?array $controls = null;

    // ------------------------------------------------------------------ control index

    /** @return array<string, array{id: string, title: string, source: string, tags: array}> */
    public static function controls(): array
    {
        if (self::$controls !== null) {
            return self::$controls;
        }
        $out = [];
        $extra = self::postureExtraTags();
        foreach (Catalog::all() as $id => $c) {
            $f = $c['frameworks'] ?? [];
            $tags = array_filter([
                'cis_m365' => $f['cis'] ?? null,
                'cisv8' => $f['cisv8'] ?? null,
                'nistcsf' => $f['nist'] ?? null,
            ]) + ($extra[$id] ?? []);
            $out[$id] = ['id' => $id, 'title' => $c['title'], 'source' => 'posture', 'category' => $c['category'], 'tags' => $tags];
        }
        foreach (IntuneCatalog::all() as $id => $t) {
            $f = $t['frameworks'] ?? [];
            $tags = array_filter([
                'cis_bench' => $f['cis'] ?? null,
                'cisv8' => $f['cisv8'] ?? null,
                'nistcsf' => $f['nistcsf'] ?? null,
                'nist80053' => $f['nist80053'] ?? null,
                'iso27001' => $f['iso27001'] ?? null,
                'pci' => $f['pci'] ?? null,
                'ce' => $f['ce'] ?? null,
            ]);
            $out[$id] = ['id' => $id, 'title' => $t['name'], 'source' => 'intune', 'category' => $t['category'], 'tags' => $tags];
        }

        return self::$controls = $out;
    }

    public static function control(string $id): ?array
    {
        return self::controls()[$id] ?? null;
    }

    // ------------------------------------------------------------------ frameworks

    /** @return array<string, array> */
    public static function all(): array
    {
        if (self::$frameworks !== null) {
            return self::$frameworks;
        }
        $fw = [
            'e8' => self::essentialEight(),
            'asd_ism' => self::asdIsm(),
            'hipaa' => self::hipaa(),
            'cis_m365' => self::derived('cis_m365', 'CIS Microsoft 365 Foundations Benchmark', 'Center for Internet Security', 'Global', 'v4.0', 'Prescriptive Microsoft 365 configuration recommendations.', []),
            'cisv8' => self::derived('cisv8', 'CIS Critical Security Controls', 'Center for Internet Security', 'Global', 'v8.1', '18 controls / 153 safeguards prioritised by implementation group.', self::CISV8_TITLES),
            'cis_bench' => self::derived('cis_bench', 'CIS Benchmarks (endpoint)', 'Center for Internet Security', 'Global', 'Windows 11, iOS, Android', 'Platform hardening benchmarks applied through Intune.', []),
            'nistcsf' => self::derived('nistcsf', 'NIST Cybersecurity Framework', 'NIST', 'US', '2.0', 'Govern, Identify, Protect, Detect, Respond, Recover outcomes.', self::CSF_TITLES),
            'nist80053' => self::derived('nist80053', 'NIST SP 800-53', 'NIST', 'US', 'Rev. 5', 'Security and privacy control catalogue (federal, FedRAMP, CMMC baseline).', self::SP80053_TITLES),
            'iso27001' => self::derived('iso27001', 'ISO/IEC 27001 Annex A', 'ISO/IEC', 'Global', '2022', 'Information security management system controls.', self::ISO_TITLES),
            'pci' => self::derived('pci', 'PCI DSS', 'PCI Security Standards Council', 'Global', 'v4.0', 'Payment card data security requirements.', self::PCI_TITLES),
            'ce' => self::derived('ce', 'Cyber Essentials', 'UK NCSC / IASME', 'UK', '2025 (Willow)', 'Five technical controls for UK government-backed certification.', self::CE_TITLES),
        ];

        return self::$frameworks = $fw;
    }

    public static function find(string $key): ?array
    {
        return self::all()[$key] ?? null;
    }

    /** Framework keys in which a control participates. */
    public static function frameworksForControl(string $id): array
    {
        $out = [];
        foreach (self::all() as $key => $fw) {
            foreach ($fw['requirements'] as $r) {
                if (in_array($id, $r['controls'], true)) {
                    $out[] = $key;
                    break;
                }
            }
        }

        return $out;
    }

    /** Requirements (with framework) referencing a control: [[framework, requirement], ...] */
    public static function requirementsForControl(string $id): array
    {
        $out = [];
        foreach (self::all() as $key => $fw) {
            foreach ($fw['requirements'] as $r) {
                if (in_array($id, $r['controls'], true)) {
                    $out[] = ['framework' => $key, 'requirement' => $r];
                }
            }
        }

        return $out;
    }

    /** Short label for chips. */
    public const SHORT = [
        'e8' => 'Essential Eight', 'asd_ism' => 'ASD ISM', 'hipaa' => 'HIPAA', 'cis_m365' => 'CIS M365', 'cisv8' => 'CIS v8.1',
        'cis_bench' => 'CIS Benchmarks', 'nistcsf' => 'NIST CSF', 'nist80053' => 'NIST 800-53', 'iso27001' => 'ISO 27001', 'pci' => 'PCI DSS', 'ce' => 'Cyber Essentials',
    ];

    // ------------------------------------------------------------------ derived frameworks

    private static function derived(string $key, string $name, string $publisher, string $region, string $version, string $about, array $titles): array
    {
        $reqs = [];
        foreach (self::controls() as $id => $c) {
            if (empty($c['tags'][$key])) {
                continue;
            }
            $tokens = $key === 'cis_bench' ? [trim($c['tags'][$key])] : array_map('trim', explode(',', $c['tags'][$key]));
            foreach ($tokens as $tok) {
                $tok = ltrim($tok, '§ ');
                if ($tok === '') {
                    continue;
                }
                $base = preg_replace('/\(.*$/', '', $tok); // SC-28(1) -> SC-28 for the title lookup
                $reqs[$tok] ??= [
                    'id' => $tok,
                    'title' => $titles[$tok] ?? $titles[$base] ?? ($key === 'cis_bench' ? $tok : self::SHORT[$key].' '.$tok),
                    'group' => self::groupFor($key, $tok),
                    'level' => null,
                    'controls' => [],
                ];
                $reqs[$tok]['controls'][] = $id;
            }
        }
        uksort($reqs, 'strnatcmp');

        return [
            'key' => $key, 'name' => $name, 'publisher' => $publisher, 'region' => $region, 'version' => $version, 'about' => $about,
            'kind' => 'derived', 'levels' => null, 'requirements' => array_values($reqs),
        ];
    }

    private static function groupFor(string $key, string $id): string
    {
        return match ($key) {
            'nistcsf' => ['GV' => 'Govern', 'ID' => 'Identify', 'PR' => 'Protect', 'DE' => 'Detect', 'RS' => 'Respond', 'RC' => 'Recover'][substr($id, 0, 2)] ?? 'Other',
            'nist80053' => strtok($id, '-').' family',
            'cisv8' => 'Control '.strtok($id, '.'),
            'cis_m365' => 'Section '.strtok($id, '.'),
            'iso27001' => ['A.5' => 'Organisational controls', 'A.6' => 'People controls', 'A.7' => 'Physical controls', 'A.8' => 'Technological controls'][substr($id, 0, 3)] ?? 'Annex A',
            'pci' => 'Requirement '.strtok($id, '.'),
            'ce' => 'Technical controls',
            default => 'Benchmarks',
        };
    }

    // ------------------------------------------------------------------ Essential Eight

    private static function essentialEight(): array
    {
        $r = fn (string $id, string $group, int $level, string $title, array $controls = [], string $guidance = '') => compact('id', 'group', 'level', 'title', 'controls', 'guidance');

        return [
            'key' => 'e8', 'name' => 'Essential Eight', 'publisher' => 'Australian Signals Directorate (ASD)', 'region' => 'AU', 'version' => 'Maturity Model (November 2023)',
            'about' => 'Eight mitigation strategies assessed at Maturity Levels 1–3. A strategy reaches a level only when every requirement up to that level is met.',
            'kind' => 'curated', 'levels' => [1, 2, 3],
            'requirements' => [
                // Patch applications
                $r('E8-PA-1.1', 'Patch applications', 1, 'Asset discovery and vulnerability scanning identify missing patches for online services and applications', [], 'Use Defender Vulnerability Management or your RMM scanner.'),
                $r('E8-PA-1.2', 'Patch applications', 1, 'Patches for office productivity suites, web browsers, email clients and PDF software are applied within two weeks', [], 'Microsoft 365 Apps update channel + Edge/Chrome auto-update via Intune; evidence from update compliance reports.'),
                $r('E8-PA-1.3', 'Patch applications', 1, 'Unsupported office suites, browsers, email clients and PDF software are removed', []),
                $r('E8-PA-2.1', 'Patch applications', 2, 'Vulnerability scanning runs at least weekly for office suites, browsers, email clients and PDF software', []),
                $r('E8-PA-3.1', 'Patch applications', 3, 'Patches for other applications are applied within one month; unsupported applications are removed', []),
                // Patch operating systems
                $r('E8-PO-1.1', 'Patch operating systems', 1, 'Operating system patches for workstations are applied within one month', ['WIN-UPD-01', 'WIN-UPD-02', 'IOS-CMP-01', 'AND-CMP-01']),
                $r('E8-PO-1.2', 'Patch operating systems', 1, 'Operating systems no longer supported by vendors are replaced', ['WIN-CMP-01', 'WIN-UPD-03', 'AND-CMP-02']),
                $r('E8-PO-2.1', 'Patch operating systems', 2, 'Operating system patches for workstations are applied within two weeks', ['WIN-UPD-02', 'DEV-02']),
                $r('E8-PO-3.1', 'Patch operating systems', 3, 'Patches are applied within 48 hours when a working exploit exists', [], 'Use expedited quality updates in Intune and record the process.'),
                $r('E8-PO-3.2', 'Patch operating systems', 3, 'The latest or previous release of operating systems is used', ['WIN-UPD-03']),
                // Multi-factor authentication
                $r('E8-MFA-1.1', 'Multi-factor authentication', 1, 'MFA is used to authenticate users to their organisation\'s online services (Microsoft 365)', ['ID-01', 'ID-02', 'ID-08']),
                $r('E8-MFA-1.2', 'Multi-factor authentication', 1, 'Authentication paths that cannot do MFA (legacy protocols) are blocked', ['ID-04']),
                $r('E8-MFA-2.1', 'Multi-factor authentication', 2, 'MFA is used to authenticate privileged users of systems', ['ID-03', 'ID-09']),
                $r('E8-MFA-2.2', 'Multi-factor authentication', 2, 'Successful and unsuccessful MFA events are centrally logged', ['TEN-02']),
                $r('E8-MFA-3.1', 'Multi-factor authentication', 3, 'MFA is phishing-resistant (passkeys/FIDO2, Windows Hello for Business); weak methods are disabled', ['ID-14', 'ID-13', 'WIN-CFG-02']),
                // Restrict administrative privileges
                $r('E8-RA-1.1', 'Restrict administrative privileges', 1, 'Privileged access is validated and limited to what duties require', ['ID-10', 'TEN-04']),
                $r('E8-RA-1.2', 'Restrict administrative privileges', 1, 'Privileged accounts are prevented from accessing the internet, email and web services', [], 'Separate admin accounts without mailboxes/licences; Conditional Access for admin portals.'),
                $r('E8-RA-1.3', 'Restrict administrative privileges', 1, 'Users run as standard users; local administrator passwords are unique and managed', ['WIN-ENR-01', 'WIN-SEC-05']),
                $r('E8-RA-2.1', 'Restrict administrative privileges', 2, 'Privileged and inactive accounts are disabled after 45 days of inactivity', ['ID-12']),
                $r('E8-RA-2.2', 'Restrict administrative privileges', 2, 'Privileged accounts are cloud-only, session-limited and their credentials are protected', ['ID-11', 'ID-05', 'WIN-SEC-06']),
                $r('E8-RA-3.1', 'Restrict administrative privileges', 3, 'Just-in-time administration is used (Privileged Identity Management)', [], 'Requires Entra ID P2 PIM; attest with the role settings export.'),
                // Application control
                $r('E8-AC-1.1', 'Application control', 1, 'Application control restricts execution of executables, installers, scripts and HTAs in user profiles and temporary folders', [], 'App Control for Business (WDAC) or AppLocker deployed through Intune.'),
                $r('E8-AC-1.2', 'Application control', 1, 'Users cannot register or consent to unapproved cloud applications (supporting control)', ['APP-01', 'APP-02', 'APP-03']),
                $r('E8-AC-2.1', 'Application control', 2, 'Microsoft\'s recommended application blocklist and driver blocklist are implemented', ['WIN-SEC-04']),
                $r('E8-AC-3.1', 'Application control', 3, 'Application control rulesets are validated annually and cover drivers and servers', []),
                // Restrict Microsoft Office macros
                $r('E8-OM-1.1', 'Restrict Microsoft Office macros', 1, 'Macros are disabled for users without a business requirement; macros from the internet are blocked', [], 'Office cloud policy or Intune settings catalog "Block macros from running in Office files from the Internet".'),
                $r('E8-OM-1.2', 'Restrict Microsoft Office macros', 1, 'Microsoft Office macro antivirus scanning is enabled', ['WIN-SEC-01']),
                $r('E8-OM-2.1', 'Restrict Microsoft Office macros', 2, 'Microsoft Office macros are blocked from making Win32 API calls', ['WIN-SEC-04']),
                $r('E8-OM-3.1', 'Restrict Microsoft Office macros', 3, 'Only macros from trusted locations or signed by trusted publishers can run', []),
                // User application hardening
                $r('E8-UH-1.1', 'User application hardening', 1, 'Browsers do not process Java or web advertisements; Internet Explorer 11 is disabled', []),
                $r('E8-UH-1.2', 'User application hardening', 1, 'Browser and SmartScreen security settings cannot be changed by users', ['WIN-CFG-01']),
                $r('E8-UH-2.1', 'User application hardening', 2, 'Office and PDF software are blocked from creating child processes, executable content and injecting code', ['WIN-SEC-04']),
                $r('E8-UH-2.2', 'User application hardening', 2, 'PowerShell module and script block logging are enabled and centrally logged', []),
                $r('E8-UH-3.1', 'User application hardening', 3, '.NET Framework 3.5 and below and Windows PowerShell 2.0 are removed; credential theft protections enabled', ['WIN-SEC-06']),
                // Regular backups
                $r('E8-RB-1.1', 'Regular backups', 1, 'Backups of data, applications and settings (including Microsoft 365) are performed and retained', [], 'Third-party Microsoft 365 backup or Microsoft 365 Backup; attest with retention settings.'),
                $r('E8-RB-1.2', 'Regular backups', 1, 'Restoration of systems, applications and data from backups is tested', []),
                $r('E8-RB-2.1', 'Regular backups', 2, 'Unprivileged accounts cannot access, modify or delete backups', []),
            ],
        ];
    }

    // ------------------------------------------------------------------ ASD ISM

    private static function asdIsm(): array
    {
        $r = fn (string $id, string $group, string $title, array $controls = [], string $guidance = '') => compact('id', 'group', 'title', 'controls', 'guidance') + ['level' => null];

        return [
            'key' => 'asd_ism', 'name' => 'ASD Information Security Manual (Microsoft 365 & endpoint scope)', 'publisher' => 'Australian Signals Directorate (ASD)', 'region' => 'AU', 'version' => 'Guideline areas',
            'about' => 'ISM guidelines that apply to Microsoft 365 tenants and managed endpoints, grouped by ISM guideline. Map to the specific ISM control IDs in your SSP before formal assessment.',
            'kind' => 'curated', 'levels' => null,
            'requirements' => [
                $r('ISM-AUTH-1', 'Guidelines for System Hardening: Authentication', 'Multi-factor authentication for all users of online services', ['ID-01', 'ID-02', 'ID-08']),
                $r('ISM-AUTH-2', 'Guidelines for System Hardening: Authentication', 'Multi-factor authentication for privileged users', ['ID-03', 'ID-09']),
                $r('ISM-AUTH-3', 'Guidelines for System Hardening: Authentication', 'Phishing-resistant MFA; weak authenticators (SMS/voice) not used', ['ID-14', 'ID-13', 'WIN-CFG-02']),
                $r('ISM-AUTH-4', 'Guidelines for System Hardening: Authentication', 'Legacy authentication protocols disabled', ['ID-04']),
                $r('ISM-AUTH-5', 'Guidelines for System Hardening: Authentication', 'Passphrase policy without forced periodic change', ['ID-15']),
                $r('ISM-OS-1', 'Guidelines for System Hardening: Operating system', 'Supported, hardened operating system baseline (SOE) on workstations', ['WIN-CMP-01', 'WIN-CFG-01', 'WIN-UPD-03']),
                $r('ISM-OS-2', 'Guidelines for System Hardening: Operating system', 'Unique, managed local administrator credentials', ['WIN-SEC-05']),
                $r('ISM-OS-3', 'Guidelines for System Hardening: Operating system', 'Credential theft protections (Credential Guard, LSA protection)', ['WIN-SEC-06']),
                $r('ISM-OS-4', 'Guidelines for System Hardening: Operating system', 'Antivirus with real-time, cloud and reputation protection', ['WIN-SEC-01']),
                $r('ISM-OS-5', 'Guidelines for System Hardening: Operating system', 'Exploit protection and attack surface reduction', ['WIN-SEC-04']),
                $r('ISM-OS-6', 'Guidelines for System Hardening: Operating system', 'Host-based firewall enabled', ['WIN-SEC-03']),
                $r('ISM-APP-1', 'Guidelines for System Hardening: Applications', 'Users cannot install or consent to unapproved applications', ['APP-01', 'APP-02', 'APP-03', 'APP-04']),
                $r('ISM-APP-2', 'Guidelines for System Hardening: Applications', 'Application control on workstations', [], 'App Control for Business (WDAC) via Intune.'),
                $r('ISM-SM-1', 'Guidelines for System Management', 'Operating system patching within ISM timeframes', ['WIN-UPD-01', 'WIN-UPD-02', 'DEV-02']),
                $r('ISM-SM-2', 'Guidelines for System Management', 'Application patching within ISM timeframes', []),
                $r('ISM-PRIV-1', 'Guidelines for Personnel Security / Access', 'Privileged accounts minimised, cloud-only and session-limited', ['ID-10', 'ID-11', 'ID-05']),
                $r('ISM-PRIV-2', 'Guidelines for Personnel Security / Access', 'Break-glass accounts managed and monitored', ['TEN-04']),
                $r('ISM-PRIV-3', 'Guidelines for Personnel Security / Access', 'Inactive accounts disabled', ['ID-12']),
                $r('ISM-PRIV-4', 'Guidelines for Personnel Security / Access', 'Risky sign-ins and compromised accounts responded to', ['ID-06', 'ID-07']),
                $r('ISM-EMAIL-1', 'Guidelines for Email', 'SPF, DKIM and DMARC (reject/quarantine) for all sending domains', ['EML-01', 'EML-02', 'EML-03']),
                $r('ISM-EMAIL-2', 'Guidelines for Email', 'External email is identified to users', ['TEN-03']),
                $r('ISM-LOG-1', 'Guidelines for System Monitoring', 'Event logging (unified audit log) enabled and retained', ['TEN-02']),
                $r('ISM-DATA-1', 'Guidelines for Data Transfers', 'External sharing restricted; anonymous links disabled', ['COL-01', 'COL-02', 'COL-03', 'COL-04']),
                $r('ISM-CRYPT-1', 'Guidelines for Cryptography', 'Encryption of data at rest on endpoints', ['WIN-SEC-02']),
                $r('ISM-MOB-1', 'Guidelines for Enterprise Mobility', 'Mobile devices managed, compliant and encrypted', ['IOS-CMP-01', 'AND-CMP-01', 'AND-CMP-02', 'IOS-CFG-01', 'AND-CFG-02', 'DEV-01']),
                $r('ISM-MOB-2', 'Guidelines for Enterprise Mobility', 'Organisational data on mobile devices protected by app protection / containerisation', ['IOS-APP-01', 'AND-APP-01', 'AND-CFG-01', 'ZT-CA-02']),
                $r('ISM-ZT-1', 'Guidelines for Networking / Zero Trust', 'Access requires compliant devices; unsupported platforms blocked', ['ZT-CA-01', 'ZT-CA-03']),
                $r('ISM-BKP-1', 'Guidelines for Business Continuity', 'Backups of Microsoft 365 data performed and tested', []),
            ],
        ];
    }

    // ------------------------------------------------------------------ HIPAA

    private static function hipaa(): array
    {
        $r = fn (string $id, string $group, string $title, array $controls = [], string $guidance = '') => compact('id', 'group', 'title', 'controls', 'guidance') + ['level' => null];
        $adm = 'Administrative safeguards (§164.308)';
        $phy = 'Physical safeguards (§164.310)';
        $tec = 'Technical safeguards (§164.312)';

        return [
            'key' => 'hipaa', 'name' => 'HIPAA Security Rule', 'publisher' => 'US HHS', 'region' => 'US', 'version' => '45 CFR Part 164 Subpart C',
            'about' => 'Administrative, physical and technical safeguards for electronic protected health information (ePHI).',
            'kind' => 'curated', 'levels' => null,
            'requirements' => [
                $r('164.308(a)(1)(ii)(A)', $adm, 'Risk analysis', ['TEN-01'], 'Annual documented risk analysis; TenantsGuard reports are supporting evidence.'),
                $r('164.308(a)(1)(ii)(B)', $adm, 'Risk management', [], 'Document risk treatment decisions (use annotations and delta reports).'),
                $r('164.308(a)(3)(ii)(C)', $adm, 'Termination procedures', ['ID-12']),
                $r('164.308(a)(4)(ii)(C)', $adm, 'Access establishment and modification', ['ID-10', 'ID-11', 'APP-03', 'COL-01', 'COL-02']),
                $r('164.308(a)(5)(ii)(B)', $adm, 'Protection from malicious software', ['WIN-SEC-01', 'WIN-SEC-04', 'WIN-CMP-01', 'EML-01', 'EML-03']),
                $r('164.308(a)(5)(ii)(C)', $adm, 'Log-in monitoring', ['ID-06', 'ID-07', 'TEN-02']),
                $r('164.308(a)(5)(ii)(D)', $adm, 'Password management', ['ID-15', 'ID-13', 'WIN-SEC-05']),
                $r('164.308(a)(6)(ii)', $adm, 'Security incident response and reporting', []),
                $r('164.308(a)(7)(ii)(A)', $adm, 'Data backup plan', []),
                $r('164.310(d)(1)', $phy, 'Device and media controls', ['WIN-SEC-02', 'DEV-01', 'DEV-02', 'IOS-CFG-01', 'AND-CFG-02']),
                $r('164.312(a)(1)', $tec, 'Access control', ['ID-01', 'ID-02', 'ZT-CA-01', 'ZT-CA-02', 'ZT-CA-03', 'COL-03']),
                $r('164.312(a)(2)(i)', $tec, 'Unique user identification', [], 'No shared accounts; attest with user list review.'),
                $r('164.312(a)(2)(ii)', $tec, 'Emergency access procedure', ['TEN-04']),
                $r('164.312(a)(2)(iii)', $tec, 'Automatic logoff', ['WIN-CFG-01', 'IOS-CMP-01', 'AND-CMP-01', 'ID-05']),
                $r('164.312(a)(2)(iv)', $tec, 'Encryption and decryption (data at rest)', ['WIN-SEC-02', 'IOS-APP-01', 'AND-APP-01', 'AND-CMP-01']),
                $r('164.312(b)', $tec, 'Audit controls', ['TEN-02']),
                $r('164.312(c)(1)', $tec, 'Integrity (protect ePHI from improper alteration or destruction)', ['COL-03', 'COL-04', 'IOS-APP-01', 'AND-APP-01', 'AND-CFG-01']),
                $r('164.312(d)', $tec, 'Person or entity authentication', ['ID-02', 'ID-03', 'ID-04', 'ID-08', 'ID-09', 'ID-14', 'WIN-CFG-02']),
                $r('164.312(e)(1)', $tec, 'Transmission security', ['WIN-SEC-03', 'EML-01', 'EML-02', 'EML-03']),
            ],
        ];
    }

    // ------------------------------------------------------------------ extra posture tags

    /** Additional framework references for Microsoft 365 posture checks. */
    private static function postureExtraTags(): array
    {
        return [
            'ID-01' => ['nist80053' => 'IA-2, IA-2(1)', 'iso27001' => 'A.8.5', 'pci' => '8.4', 'ce' => 'User access control'],
            'ID-02' => ['nist80053' => 'IA-2(1), IA-2(2)', 'iso27001' => 'A.8.5', 'pci' => '8.4', 'ce' => 'User access control'],
            'ID-03' => ['nist80053' => 'IA-2(1), AC-6', 'iso27001' => 'A.8.2, A.8.5', 'pci' => '8.4', 'ce' => 'User access control'],
            'ID-04' => ['nist80053' => 'IA-2, CM-7', 'iso27001' => 'A.8.5', 'pci' => '8.4'],
            'ID-05' => ['nist80053' => 'AC-12', 'iso27001' => 'A.8.2', 'pci' => '8.2.8'],
            'ID-06' => ['nist80053' => 'SI-4, AC-7', 'iso27001' => 'A.8.16'],
            'ID-07' => ['nist80053' => 'SI-4, IR-4', 'iso27001' => 'A.5.26'],
            'ID-08' => ['nist80053' => 'IA-2(1)', 'iso27001' => 'A.8.5', 'pci' => '8.4', 'ce' => 'User access control'],
            'ID-09' => ['nist80053' => 'IA-2(1), AC-6', 'iso27001' => 'A.8.2, A.8.5', 'pci' => '8.4'],
            'ID-10' => ['nist80053' => 'AC-6(5)', 'iso27001' => 'A.8.2', 'pci' => '7.2', 'ce' => 'User access control'],
            'ID-11' => ['nist80053' => 'AC-6', 'iso27001' => 'A.8.2'],
            'ID-12' => ['nist80053' => 'AC-2(3)', 'iso27001' => 'A.5.18', 'pci' => '8.2.6', 'ce' => 'User access control'],
            'ID-13' => ['nist80053' => 'IA-5', 'iso27001' => 'A.8.5'],
            'ID-14' => ['nist80053' => 'IA-2(6)', 'iso27001' => 'A.8.5'],
            'ID-15' => ['nist80053' => 'IA-5(1)', 'iso27001' => 'A.5.17'],
            'APP-01' => ['nist80053' => 'CM-7, CM-11', 'iso27001' => 'A.8.19'],
            'APP-02' => ['nist80053' => 'CM-11, AC-3', 'iso27001' => 'A.8.19'],
            'APP-03' => ['nist80053' => 'CM-11', 'iso27001' => 'A.8.19'],
            'APP-04' => ['nist80053' => 'CM-7', 'iso27001' => 'A.8.9'],
            'APP-05' => ['nist80053' => 'IA-5', 'iso27001' => 'A.5.17'],
            'COL-01' => ['nist80053' => 'AC-3, AC-6', 'iso27001' => 'A.5.15'],
            'COL-02' => ['nist80053' => 'AC-2', 'iso27001' => 'A.5.15'],
            'COL-03' => ['nist80053' => 'AC-3, AC-21', 'iso27001' => 'A.5.14, A.8.12'],
            'COL-04' => ['nist80053' => 'AC-21', 'iso27001' => 'A.5.14'],
            'EML-01' => ['nist80053' => 'SI-8', 'iso27001' => 'A.5.14'],
            'EML-02' => ['nist80053' => 'SI-8, SC-8', 'iso27001' => 'A.5.14'],
            'EML-03' => ['nist80053' => 'SI-8', 'iso27001' => 'A.5.14'],
            'DEV-01' => ['nist80053' => 'CM-6, AC-19', 'iso27001' => 'A.8.1', 'ce' => 'Secure configuration'],
            'DEV-02' => ['nist80053' => 'CM-6, SI-2', 'iso27001' => 'A.8.1', 'ce' => 'Security update management'],
            'TEN-01' => ['nist80053' => 'RA-3, CA-7', 'iso27001' => 'A.5.36'],
            'TEN-02' => ['nist80053' => 'AU-2, AU-12', 'iso27001' => 'A.8.15', 'pci' => '10.2'],
            'TEN-03' => ['nist80053' => 'SI-8', 'iso27001' => 'A.6.3'],
            'TEN-04' => ['nist80053' => 'AC-2, CP-2', 'iso27001' => 'A.5.30'],
        ];
    }

    // ------------------------------------------------------------------ requirement titles

    private const CSF_TITLES = [
        'ID.AM-01' => 'Inventories of hardware managed by the organisation are maintained',
        'ID.RA-01' => 'Vulnerabilities in assets are identified, validated and recorded',
        'PR.AA-01' => 'Identities and credentials for authorised users, services and hardware are managed',
        'PR.AA-03' => 'Users, services and hardware are authenticated',
        'PR.AA-05' => 'Access permissions and authorisations are managed (least privilege, separation of duties)',
        'PR.AA-06' => 'Physical access to assets is managed',
        'PR.AT-01' => 'Personnel are provided with awareness and training',
        'PR.DS-01' => 'The confidentiality, integrity and availability of data-at-rest are protected',
        'PR.DS-02' => 'The confidentiality, integrity and availability of data-in-transit are protected',
        'PR.PS-01' => 'Configuration management practices are established and applied',
        'PR.PS-02' => 'Software is maintained, replaced and removed commensurate with risk',
        'PR.IR-01' => 'Networks and environments are protected from unauthorised logical access and usage',
        'DE.CM-03' => 'Personnel activity and technology usage are monitored',
        'DE.CM-09' => 'Computing hardware and software, runtime environments and their data are monitored',
        'DE.AE-02' => 'Potentially adverse events are analysed',
    ];

    private const CISV8_TITLES = [
        '1.1' => 'Establish and maintain a detailed enterprise asset inventory',
        '2.2' => 'Ensure authorised software is currently supported',
        '2.3' => 'Address unauthorised software',
        '2.5' => 'Allowlist authorised software',
        '2.7' => 'Allowlist authorised scripts',
        '3.3' => 'Configure data access control lists',
        '3.6' => 'Encrypt data on end-user devices',
        '3.9' => 'Encrypt data on removable media',
        '3.12' => 'Segment data processing and storage based on sensitivity',
        '4.1' => 'Establish and maintain a secure configuration process',
        '4.3' => 'Configure automatic session locking on enterprise assets',
        '4.4' => 'Implement and manage a firewall on servers',
        '4.5' => 'Implement and manage a firewall on end-user devices',
        '4.7' => 'Manage default accounts on enterprise assets and software',
        '4.8' => 'Uninstall or disable unnecessary services',
        '4.10' => 'Enforce automatic device lockout on portable end-user devices',
        '5.1' => 'Establish and maintain an inventory of accounts',
        '5.2' => 'Use unique passwords',
        '5.3' => 'Disable dormant accounts',
        '5.4' => 'Restrict administrator privileges to dedicated administrator accounts',
        '6.3' => 'Require MFA for externally-exposed applications',
        '6.4' => 'Require MFA for remote network access',
        '6.5' => 'Require MFA for administrative access',
        '6.7' => 'Centralise access control',
        '7.3' => 'Perform automated operating system patch management',
        '7.4' => 'Perform automated application patch management',
        '8.2' => 'Collect audit logs',
        '9.3' => 'Maintain and enforce network-based URL filters',
        '9.5' => 'Implement DMARC',
        '9.7' => 'Deploy and maintain email server anti-malware protections',
        '10.1' => 'Deploy and maintain anti-malware software',
        '10.2' => 'Configure automatic anti-malware signature updates',
        '10.5' => 'Enable anti-exploitation features',
        '10.7' => 'Use behaviour-based anti-malware software',
        '13.2' => 'Deploy a host-based intrusion detection solution',
        '13.3' => 'Deploy a network intrusion detection solution',
        '13.5' => 'Manage access control for remote assets',
        '17.4' => 'Establish and maintain an incident response process',
    ];

    private const SP80053_TITLES = [
        'AC-2' => 'Account management', 'AC-3' => 'Access enforcement', 'AC-4' => 'Information flow enforcement', 'AC-6' => 'Least privilege',
        'AC-7' => 'Unsuccessful logon attempts', 'AC-11' => 'Device lock', 'AC-12' => 'Session termination', 'AC-19' => 'Access control for mobile devices',
        'AC-21' => 'Information sharing', 'AU-2' => 'Event logging', 'AU-12' => 'Audit record generation', 'CA-7' => 'Continuous monitoring',
        'CM-2' => 'Baseline configuration', 'CM-3' => 'Configuration change control', 'CM-6' => 'Configuration settings', 'CM-7' => 'Least functionality',
        'CM-8' => 'System component inventory', 'CM-11' => 'User-installed software', 'CP-2' => 'Contingency plan', 'IA-2' => 'Identification and authentication (organisational users)',
        'IA-3' => 'Device identification and authentication', 'IA-5' => 'Authenticator management', 'IR-4' => 'Incident handling', 'MP-5' => 'Media transport',
        'MP-7' => 'Media use', 'RA-3' => 'Risk assessment', 'SA-22' => 'Unsupported system components', 'SC-4' => 'Information in shared system resources',
        'SC-7' => 'Boundary protection', 'SC-8' => 'Transmission confidentiality and integrity', 'SC-28' => 'Protection of information at rest', 'SC-39' => 'Process isolation',
        'SI-2' => 'Flaw remediation', 'SI-3' => 'Malicious code protection', 'SI-4' => 'System monitoring', 'SI-7' => 'Software, firmware and information integrity',
        'SI-8' => 'Spam protection', 'SI-16' => 'Memory protection',
    ];

    private const ISO_TITLES = [
        'A.5.9' => 'Inventory of information and other associated assets', 'A.5.14' => 'Information transfer', 'A.5.15' => 'Access control',
        'A.5.17' => 'Authentication information', 'A.5.18' => 'Access rights', 'A.5.26' => 'Response to information security incidents',
        'A.5.30' => 'ICT readiness for business continuity', 'A.5.36' => 'Compliance with policies, rules and standards', 'A.6.3' => 'Information security awareness, education and training',
        'A.7.10' => 'Storage media', 'A.8.1' => 'User endpoint devices', 'A.8.2' => 'Privileged access rights', 'A.8.5' => 'Secure authentication',
        'A.8.7' => 'Protection against malware', 'A.8.8' => 'Management of technical vulnerabilities', 'A.8.9' => 'Configuration management',
        'A.8.12' => 'Data leakage prevention', 'A.8.15' => 'Logging', 'A.8.16' => 'Monitoring activities', 'A.8.19' => 'Installation of software on operational systems',
        'A.8.20' => 'Networks security', 'A.8.24' => 'Use of cryptography',
    ];

    private const PCI_TITLES = [
        '1.5.1' => 'Security controls on computing devices that connect to untrusted networks', '2.2.2' => 'Vendor default accounts are managed',
        '3.4' => 'Access to displays of full PAN and ability to copy cardholder data are restricted', '3.5.1' => 'PAN is rendered unreadable wherever stored',
        '5.2' => 'Malicious software is prevented, or detected and addressed', '5.3' => 'Anti-malware mechanisms and processes are active, maintained and monitored',
        '6.3.3' => 'Security patches are installed within one month of release', '7.2' => 'Access to system components and data is appropriately defined and assigned',
        '8.2.6' => 'Inactive user accounts are removed or disabled within 90 days', '8.2.8' => 'Sessions idle for more than 15 minutes require re-authentication',
        '8.3.4' => 'Invalid authentication attempts are limited', '8.3.6' => 'Passwords/passphrases meet minimum complexity', '8.4' => 'MFA is implemented to secure access into the CDE',
        '10.2' => 'Audit logs are implemented to support detection of anomalies and suspicious activity',
    ];

    private const CE_TITLES = [
        'Firewalls' => 'Firewalls: boundary and host firewalls configured',
        'Secure configuration' => 'Secure configuration: unnecessary software and accounts removed, device locking',
        'Security update management' => 'Security update management: high/critical updates within 14 days, supported software',
        'User access control' => 'User access control: least privilege, MFA for cloud services, account management',
        'Malware protection' => 'Malware protection: anti-malware or application allowlisting',
    ];
}
