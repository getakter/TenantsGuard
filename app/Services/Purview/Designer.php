<?php

namespace App\Services\Purview;

/**
 * Turns requirements + licence capabilities into a Purview design that follows Microsoft's
 * deployment guidance: a small label taxonomy, DLP that starts in simulation, a baseline
 * retention policy plus a record schedule, and insider risk only after privacy approval.
 */
final class Designer
{
    public const PREFIX = 'TG - ';

    public static function design(array $req, array $caps, string $tenantName): array
    {
        $has = fn (string $c) => ($caps[$c] ?? null) !== false; // unknown (null) counts as available, flagged in readiness
        $region = $req['region'] ?? 'OTHER';
        $dataTypes = array_values(array_unique(array_merge($req['data_types'] ?? [], self::impliedByRegulations($req['regulations'] ?? []))));
        $encrypt = ($req['encryption'] ?? false) && $has('encryption');
        $containers = ($req['container_labels'] ?? false);
        $workloads = $req['workloads'] ?? [];

        return [
            'generated_at' => now()->toIso8601String(),
            'data_types' => $dataTypes,
            'labels' => self::labels($req, $encrypt, $containers, $dataTypes, $region, $has('auto_label')),
            'label_policy' => [
                'default_document' => 'General', 'default_email' => 'General',
                'mandatory' => (bool) ($req['mandatory'] ?? false), 'justification' => true,
                'help_url' => '', 'containers_mandatory' => false,
            ],
            'autolabel' => $has('auto_label') && in_array($req['labelling'] ?? 'manual', ['auto', 'recommend'], true) ? self::autolabel($dataTypes, $region) : [],
            'dlp' => self::dlp($req, $dataTypes, $region, $workloads, $has),
            'retention' => self::retention($req, $region, $has),
            'irm' => self::irm($req, $has('irm')),
            'pilot' => [
                'group_name' => 'Purview Pilot', 'mail_nickname' => 'purviewpilot',
                'members' => array_values($req['pilot_users'] ?? []), 'weeks' => max(2, (int) ($req['pilot_weeks'] ?? 4)),
            ],
            'decisions' => self::decisions($req, $has, $encrypt, $tenantName),
        ];
    }

    /** Regulations imply data types even if the questionnaire missed them. */
    private static function impliedByRegulations(array $regs): array
    {
        return collect($regs)->flatMap(fn ($r) => Catalog::REGULATIONS[$r][1] ?? [])->unique()->values()->all();
    }

    private static function labels(array $req, bool $encrypt, bool $containers, array $dataTypes, string $region, bool $auto): array
    {
        $recommendHigh = Catalog::sits(array_intersect($dataTypes, ['health', 'pci', 'national_id']), $region);
        $recommendConf = Catalog::sits(array_intersect($dataTypes, ['banking', 'credentials']), $region);
        $c = fn ($privacy, $guests, $sharing) => $containers ? ['privacy' => $privacy, 'guests' => $guests, 'sharing' => $sharing] : null;

        $labels = [
            ['key' => 'Public', 'display' => 'Public', 'parent' => null, 'color' => '#2E7D32', 'encryption' => 'none', 'header' => null, 'footer' => null, 'watermark' => null,
                'tooltip' => 'Approved for anyone, including the public. Example: published marketing material.', 'container' => $c('Public', true, 'ExternalUserAndGuestSharing'), 'recommend' => []],
            ['key' => 'General', 'display' => 'General', 'parent' => null, 'color' => '#1565C0', 'encryption' => 'none', 'header' => null, 'footer' => null, 'watermark' => null,
                'tooltip' => 'Everyday business information that is not for the public. Can be shared with partners when needed.', 'container' => $c('Private', true, 'ExistingExternalUserSharingOnly'), 'recommend' => []],
            ['key' => 'Confidential', 'display' => 'Confidential', 'parent' => null, 'color' => '#EF6C00', 'encryption' => 'none', 'header' => 'CONFIDENTIAL', 'footer' => null, 'watermark' => null,
                'tooltip' => 'Sensitive business information. Harm to the organisation or clients if shared inappropriately.', 'container' => $c('Private', false, 'ExistingExternalUserSharingOnly'),
                'recommend' => $recommendConf],
        ];
        if (($req['partners_need_access'] ?? false) || ! $encrypt) {
            $labels[] = ['key' => 'Confidential-Anyone', 'display' => 'Anyone (unrestricted)', 'parent' => 'Confidential', 'color' => '#EF6C00', 'encryption' => 'none', 'header' => 'CONFIDENTIAL', 'footer' => null, 'watermark' => null,
                'tooltip' => 'Confidential data that must be shared with external partners. Marked but not encrypted.', 'container' => null, 'recommend' => []];
        }
        if ($encrypt) {
            $labels[] = ['key' => 'Confidential-AllEmployees', 'display' => 'All Employees', 'parent' => 'Confidential', 'color' => '#EF6C00', 'encryption' => 'org', 'header' => 'CONFIDENTIAL', 'footer' => null, 'watermark' => null,
                'tooltip' => 'Confidential data for staff only. Encrypted: only people in the organisation can open it.', 'container' => null, 'recommend' => []];
        }
        $labels[] = ['key' => 'HighlyConfidential', 'display' => 'Highly Confidential', 'parent' => null, 'color' => '#C62828', 'encryption' => 'none', 'header' => 'HIGHLY CONFIDENTIAL', 'footer' => null, 'watermark' => null,
            'tooltip' => 'Most sensitive data: regulated personal, health or payment information, legal privilege, board papers.', 'container' => $c('Private', false, 'Disabled'),
            'recommend' => $recommendHigh];
        if ($encrypt) {
            $labels[] = ['key' => 'HighlyConfidential-AllEmployees', 'display' => 'All Employees', 'parent' => 'HighlyConfidential', 'color' => '#C62828', 'encryption' => 'org', 'header' => 'HIGHLY CONFIDENTIAL', 'footer' => null, 'watermark' => 'HIGHLY CONFIDENTIAL',
                'tooltip' => 'Highly confidential, staff only. Encrypted and watermarked.', 'container' => null, 'recommend' => []];
            $labels[] = ['key' => 'HighlyConfidential-SpecifiedPeople', 'display' => 'Specified People', 'parent' => 'HighlyConfidential', 'color' => '#C62828', 'encryption' => 'user', 'header' => 'HIGHLY CONFIDENTIAL', 'footer' => null, 'watermark' => 'HIGHLY CONFIDENTIAL',
                'tooltip' => 'You choose exactly who can open it, inside or outside the organisation. Encrypted.', 'container' => null, 'recommend' => []];
        }

        foreach ($labels as $i => &$l) {
            $l['include'] = true;
            $l['priority'] = $i;
            $l['auto_mode'] = $auto && ($req['labelling'] ?? 'manual') === 'auto' ? 'automatic' : 'recommend';
        }

        return $labels;
    }

    private static function autolabel(array $dataTypes, string $region): array
    {
        $out = [];
        foreach (['health' => 'Health information', 'pci' => 'Payment card data', 'national_id' => 'Government identifiers'] as $dt => $name) {
            if (in_array($dt, $dataTypes, true)) {
                $out[] = ['key' => 'auto-'.$dt, 'name' => self::PREFIX.'Auto-label '.$name, 'label' => 'HighlyConfidential', 'sits' => Catalog::sits([$dt], $region),
                    'min_count' => $dt === 'pci' ? 1 : 5, 'include' => true];
            }
        }
        $classifiers = collect($dataTypes)->flatMap(fn ($d) => Catalog::CLASSIFIERS[$d] ?? [])->unique()->values()->all();
        if ($classifiers) {
            $out[] = ['key' => 'auto-classifiers', 'name' => self::PREFIX.'Auto-label by document type', 'label' => 'Confidential', 'sits' => [], 'classifiers' => $classifiers, 'min_count' => 1, 'include' => false];
        }

        return $out;
    }

    private static function dlp(array $req, array $dataTypes, string $region, array $workloads, callable $has): array
    {
        $locations = array_values(array_filter([
            in_array('exchange', $workloads, true) ? 'Exchange' : null,
            in_array('sharepoint', $workloads, true) ? 'SharePoint' : null,
            in_array('onedrive', $workloads, true) ? 'OneDrive' : null,
            in_array('teams', $workloads, true) && $has('dlp_teams') ? 'Teams' : null,
            in_array('endpoint', $workloads, true) && ($req['devices_managed'] ?? false) && $has('dlp_endpoint') ? 'Devices' : null,
        ]));
        $style = $req['dlp_style'] ?? 'block_override';
        $defs = [
            'pci' => ['Payment card data', 'Stops card numbers leaving the organisation (PCI DSS).', 1],
            'health' => ['Health information', 'Protects patient and medical information.', 5],
            'national_id' => ['Personal identifiers', 'Protects tax, passport, licence and social security numbers (privacy law).', 5],
            'banking' => ['Bank account data', 'Protects bank account and routing details from payment-redirection fraud.', 5],
            'credentials' => ['Credentials and secrets', 'Stops passwords and keys being emailed or shared.', 1],
        ];
        $out = [];
        foreach ($defs as $dt => [$name, $purpose, $high]) {
            if (! in_array($dt, $dataTypes, true)) {
                continue;
            }
            $sits = Catalog::sits([$dt], $region);
            if (! $sits) {
                continue;
            }
            $out[] = [
                'key' => 'dlp-'.$dt, 'name' => self::PREFIX.$name, 'purpose' => $purpose, 'sits' => $sits, 'locations' => $locations,
                'high_threshold' => $high, 'style' => $style, 'override' => $style !== 'block',
                'notify_user' => true, 'incident_email' => $req['owner_email'] ?? null, 'include' => true,
            ];
        }

        return $out;
    }

    private static function retention(array $req, string $region, callable $has): array
    {
        $labels = [];
        foreach ($req['record_classes'] ?? [] as $cls) {
            if (! isset(Catalog::RETENTION[$cls])) {
                continue;
            }
            [$name, $years, $desc] = Catalog::RETENTION[$cls];
            $labels[] = [
                'key' => 'rl-'.$cls, 'name' => $name, 'years' => $years[$region] ?? $years['OTHER'], 'trigger' => 'dateModified',
                'action' => ($req['disposition_review'] ?? false) && $has('records') ? 'startDispositionReview' : 'delete',
                'record' => $has('records') && in_array($cls, ['finance', 'health', 'board'], true), 'description' => $desc, 'include' => true,
            ];
        }
        $labels[] = ['key' => 'rl-shortterm', 'name' => 'Short-term (1 year)', 'years' => 1, 'trigger' => 'dateModified', 'action' => 'delete', 'record' => false,
            'description' => 'Drafts, working notes and transient content that can be deleted after a year.', 'include' => true];

        return [
            'baseline' => ['years' => (int) ($req['baseline_years'] ?? 7), 'delete' => (bool) ($req['baseline_delete'] ?? false), 'include' => (int) ($req['baseline_years'] ?? 7) > 0],
            'teams' => ['years' => (int) ($req['teams_years'] ?? 3), 'include' => in_array('teams', $req['workloads'] ?? [], true) && $has('retention_teams') && (int) ($req['teams_years'] ?? 0) > 0],
            'labels' => $labels,
        ];
    }

    private static function irm(array $req, bool $licensed): array
    {
        $templates = [
            'departing' => ['Data theft by departing users', 'Detects downloads, copying and sharing before someone leaves. Uses the HR connector or account deletion in Entra.'],
            'leaks' => ['Data leaks', 'Uses DLP high-severity alerts as the trigger to spot risky data handling.'],
            'security' => ['Security policy violations', 'Uses Microsoft Defender for Endpoint alerts (evasion, unwanted software).'],
            'priority' => ['Data leaks by priority users', 'Closer monitoring of executives and administrators (priority user group).'],
        ];
        $policies = [];
        foreach ($req['irm_concerns'] ?? [] as $c) {
            if (isset($templates[$c])) {
                $policies[] = ['key' => 'irm-'.$c, 'template' => $templates[$c][0], 'name' => self::PREFIX.$templates[$c][0], 'purpose' => $templates[$c][1], 'include' => $licensed];
            }
        }

        return ['licensed' => $licensed, 'privacy_approved' => (bool) ($req['privacy_approved'] ?? false), 'hr_connector' => (bool) ($req['hr_connector'] ?? false),
            'pseudonymize' => true, 'policies' => $policies];
    }

    private static function decisions(array $req, callable $has, bool $encrypt, string $tenant): array
    {
        $d = [
            ['Small, ordered label taxonomy', 'Public, General, Confidential and Highly Confidential, with sub-labels only where protection differs. Fewer labels means better user choices and simpler DLP.'],
            ['Simulation before enforcement', 'Every DLP and auto-labelling policy starts in simulation for all users, then shows policy tips to the pilot group, and is enforced only after the pilot gate is signed off.'],
            ['Pilot first', 'Labels, retention labels and DLP tips go to the "Purview Pilot" group first. Production rollout needs a signed pilot exit.'],
            ['Least privilege for TenantsGuard', 'TenantsGuard creates the pilot group and retention labels through Microsoft Graph and reads labels to verify. Policies are deployed with generated Security & Compliance PowerShell that your administrator runs with their own MFA-protected account, so the TenantsGuard app never holds standing compliance administrator rights.'],
        ];
        if ($encrypt) {
            $d[] = ['Encryption only where it adds value', 'Encryption is applied to "All Employees" and "Specified People" sub-labels. Unencrypted options remain for partner sharing to avoid broken workflows. Co-authoring of encrypted files is switched on.'];
        } elseif ($req['encryption'] ?? false) {
            $d[] = ['No encryption yet', 'Encryption was requested but Azure Rights Management licensing was not found. Labels apply markings only until licensing is confirmed.'];
        }
        if (! $has('auto_label')) {
            $d[] = ['Manual labelling with defaults', 'Automatic labelling needs E5-level licensing. Users get a default label and training; DLP still detects sensitive data regardless of labels.'];
        }
        if (($req['irm_concerns'] ?? []) && ! ($req['privacy_approved'] ?? false)) {
            $d[] = ['Insider risk waits for privacy approval', 'Insider risk policies stay off until legal, HR and (where applicable) the works council approve. Usernames are pseudonymised for analysts by default.'];
        }
        $d[] = ['Retention: keep, do not over-delete', 'A baseline policy keeps email and files for '.(int) ($req['baseline_years'] ?? 7).' years so accidental or malicious deletion is recoverable. Shorter or longer periods are applied with retention labels per record class. Confirm periods with legal counsel for '.$tenant.'.'];

        return $d;
    }

    /** Licence gaps that change the design. */
    public static function gaps(array $req, array $caps): array
    {
        $need = ['labels' => true, 'dlp' => true, 'retention' => true,
            'encryption' => (bool) ($req['encryption'] ?? false),
            'auto_label' => ($req['labelling'] ?? 'manual') === 'auto',
            'dlp_teams' => in_array('teams', $req['workloads'] ?? [], true),
            'dlp_endpoint' => in_array('endpoint', $req['workloads'] ?? [], true),
            'records' => (bool) ($req['disposition_review'] ?? false),
            'irm' => ! empty($req['irm_concerns']),
        ];
        $out = [];
        foreach ($need as $cap => $wanted) {
            if ($wanted && ($caps[$cap] ?? null) === false) {
                [$label, , $sku] = Catalog::CAPABILITIES[$cap];
                $out[] = ['capability' => $cap, 'label' => $label, 'needs' => $sku];
            }
        }

        return $out;
    }
}
