<?php

namespace App\Services\Purview;

/**
 * Microsoft Purview knowledge used to turn requirements into a design: the questionnaire,
 * licence capabilities, sensitive information types (SITs) per data category and region,
 * regulation mappings and retention defaults. Values are sensible starting points; the
 * design document tells the customer to confirm retention periods with legal counsel.
 */
final class Catalog
{
    public const STAGES = [
        'requirements' => ['Requirements', 'Gather business, regulatory and data requirements.'],
        'design' => ['Design', 'Review and approve the generated Purview design.'],
        'foundations' => ['Foundations', 'Licensing, roles, audit, pilot group and platform settings.'],
        'discover' => ['Discover', 'DLP in simulation, content and activity explorer, tuning.'],
        'pilot' => ['Pilot', 'Labels, DLP tips, retention labels and insider risk for the pilot group.'],
        'production' => ['Production', 'Roll out to everyone and enforce.'],
        'operate' => ['Operate', 'Monitor, triage, tune and review.'],
    ];

    /** Phases that have tasks (stages after design). */
    public const PHASES = ['foundations', 'discover', 'pilot', 'production', 'operate'];

    public const INDUSTRIES = ['healthcare' => 'Healthcare', 'finance' => 'Financial services', 'legal' => 'Legal', 'education' => 'Education', 'government' => 'Government',
        'professional' => 'Professional services', 'retail' => 'Retail / e-commerce', 'manufacturing' => 'Manufacturing', 'nonprofit' => 'Not-for-profit', 'other' => 'Other'];

    public const REGIONS = ['AU' => 'Australia', 'NZ' => 'New Zealand', 'US' => 'United States', 'UK' => 'United Kingdom', 'EU' => 'European Union', 'CA' => 'Canada', 'OTHER' => 'Other / global'];

    public const REGULATIONS = [
        'au_privacy' => ['Australian Privacy Act (APPs) and NDB scheme', ['national_id', 'customer_pii', 'health', 'banking']],
        'nz_privacy' => ['New Zealand Privacy Act 2020', ['national_id', 'customer_pii', 'health']],
        'gdpr' => ['GDPR / UK GDPR', ['national_id', 'customer_pii', 'banking', 'health']],
        'hipaa' => ['HIPAA (US health information)', ['health', 'national_id']],
        'pci' => ['PCI DSS (payment cards)', ['pci']],
        'sox' => ['SOX / financial reporting', ['banking', 'finance']],
        'ccpa' => ['CCPA / CPRA (California)', ['national_id', 'customer_pii']],
        'iso27001' => ['ISO/IEC 27001', []],
        'e8' => ['ASD Essential Eight', []],
        'cmmc' => ['CMMC / NIST 800-171 (CUI)', ['ip']],
    ];

    /** key => [label, has built-in SITs] */
    public const DATA_TYPES = [
        'pci' => ['Payment card numbers', true],
        'health' => ['Health and medical information', true],
        'national_id' => ['Government identifiers (tax, passport, licence, social security)', true],
        'banking' => ['Bank account and routing numbers', true],
        'credentials' => ['Passwords, keys and secrets', true],
        'customer_pii' => ['Customer personal information (names, addresses, contacts)', false],
        'hr' => ['HR and employee records', false],
        'finance' => ['Financial statements and reporting', false],
        'legal' => ['Legal, contracts and privileged advice', false],
        'ip' => ['Intellectual property, designs, source code', false],
        'board' => ['Board, M&A and strategy papers', false],
    ];

    /** Built-in trainable classifiers that help where there are no SITs (auto-labelling, E5). */
    public const CLASSIFIERS = ['hr' => ['HR', 'Resume'], 'finance' => ['Finance', 'Tax'], 'legal' => ['Legal Affairs', 'Agreements'], 'ip' => ['Source Code', 'IP'],
        'board' => ['Corporate Sabotage'], 'customer_pii' => ['Customer Complaints'], 'health' => ['Healthcare']];

    /** Sensitive information types per data type and region ('*' applies everywhere). Names match the Purview built-in SITs. */
    public const SITS = [
        'pci' => ['*' => ['Credit Card Number'], 'EU' => ['EU Debit Card Number']],
        'health' => [
            '*' => ['International Classification of Diseases (ICD-10-CM)', 'International Classification of Diseases (ICD-9-CM)'],
            'AU' => ['Australia Medical Account Number'], 'US' => ['Drug Enforcement Agency (DEA) Number', 'U.S. Social Security Number (SSN)'],
            'UK' => ['U.K. National Health Service Number'], 'NZ' => ['New Zealand Ministry of Health Number'], 'CA' => ['Canada Health Service Number'],
        ],
        'national_id' => [
            'AU' => ['Australia Tax File Number', 'Australia Passport Number', "Australia Driver's License Number"],
            'NZ' => ['New Zealand Inland Revenue number', 'New Zealand Driver License Number'],
            'US' => ['U.S. Social Security Number (SSN)', 'U.S. / U.K. Passport Number', "U.S. Driver's License Number", 'U.S. Individual Taxpayer Identification Number (ITIN)'],
            'UK' => ['U.K. National Insurance Number (NINO)', 'U.S. / U.K. Passport Number', "U.K. Driver's License Number"],
            'EU' => ['EU National Identification Number', 'EU Passport Number', 'EU Tax Identification Number', "EU Driver's License Number"],
            'CA' => ['Canada Social Insurance Number', 'Canada Passport Number'],
        ],
        'banking' => [
            '*' => ['SWIFT Code'], 'AU' => ['Australia Bank Account Number'], 'NZ' => ['New Zealand Bank Account Number'],
            'US' => ['U.S. Bank Account Number', 'ABA Routing Number'], 'UK' => ['International Banking Account Number (IBAN)'],
            'EU' => ['International Banking Account Number (IBAN)'], 'CA' => ['Canada Bank Account Number'],
        ],
        'credentials' => ['*' => ['General Password', 'Azure Storage Account Access Key', 'Microsoft Entra client secret', 'General Symmetric Key']],
    ];

    /** Retention defaults in years per record class and region. Starting points only. */
    public const RETENTION = [
        'health' => ['Health records', ['AU' => 7, 'NZ' => 10, 'US' => 6, 'UK' => 8, 'EU' => 10, 'CA' => 10, 'OTHER' => 7], 'Health and patient records'],
        'finance' => ['Financial records', ['AU' => 7, 'NZ' => 7, 'US' => 7, 'UK' => 6, 'EU' => 10, 'CA' => 6, 'OTHER' => 7], 'Accounting, tax and financial reporting records'],
        'hr' => ['Employee records', ['AU' => 7, 'NZ' => 7, 'US' => 7, 'UK' => 6, 'EU' => 5, 'CA' => 7, 'OTHER' => 7], 'Personnel files, payroll and employment records'],
        'legal' => ['Contracts and legal', ['AU' => 7, 'NZ' => 7, 'US' => 7, 'UK' => 6, 'EU' => 10, 'CA' => 7, 'OTHER' => 7], 'Signed agreements and legal correspondence'],
        'board' => ['Board and governance', ['AU' => 10, 'NZ' => 10, 'US' => 10, 'UK' => 10, 'EU' => 10, 'CA' => 10, 'OTHER' => 10], 'Board minutes, resolutions and governance records'],
    ];

    /**
     * Capability => [label, any-of service plans, what is needed when missing].
     * Read from /subscribedSkus service plans.
     */
    public const CAPABILITIES = [
        'labels' => ['Sensitivity labels (manual)', ['MIP_S_CLP1', 'MIP_S_CLP2', 'RMS_S_PREMIUM', 'RMS_S_PREMIUM2'], 'Microsoft 365 Business Premium, E3 or E5'],
        'encryption' => ['Label encryption (Azure Rights Management)', ['RMS_S_ENTERPRISE', 'RMS_S_PREMIUM', 'RMS_S_PREMIUM2'], 'Business Premium, E3 or E5'],
        'auto_label' => ['Automatic labelling and trainable classifiers', ['MIP_S_CLP2'], 'Microsoft 365 E5, E5 Compliance or E5 Information Protection & Governance'],
        'dlp' => ['DLP for Exchange, SharePoint and OneDrive', ['EXCHANGE_S_STANDARD', 'EXCHANGE_S_ENTERPRISE', 'MIP_S_CLP1', 'MIP_S_CLP2'], 'Business Premium, E3 or E5'],
        'dlp_teams' => ['DLP for Teams chats and channels', ['MIP_S_CLP2'], 'Microsoft 365 E5 or E5 Compliance'],
        'dlp_endpoint' => ['Endpoint DLP (Windows and macOS devices)', ['MICROSOFTENDPOINTDLP'], 'Microsoft 365 E5 or E5 Compliance'],
        'retention' => ['Retention policies and labels', ['EXCHANGE_S_STANDARD', 'EXCHANGE_S_ENTERPRISE', 'SHAREPOINTSTANDARD', 'SHAREPOINTENTERPRISE'], 'Business Premium, E3 or E5'],
        'retention_teams' => ['Retention for Teams', ['TEAMS1', 'TEAMS_AR_DOD', 'TEAMS_AR_GCCHIGH'], 'Business Premium, E3 or E5'],
        'records' => ['Records management (records, disposition review)', ['RECORDS_MANAGEMENT'], 'Microsoft 365 E5 or E5 Compliance'],
        'irm' => ['Insider Risk Management', ['INSIDER_RISK', 'INSIDER_RISK_MANAGEMENT'], 'Microsoft 365 E5, E5 Compliance or the Insider Risk Management add-on'],
        'audit_premium' => ['Audit (Premium)', ['M365_ADVANCED_AUDITING'], 'Microsoft 365 E5 or E5 Compliance'],
    ];

    /** SharePoint external sharing values used by container labels, in plain words. */
    public const SHARING = [
        'ExternalUserAndGuestSharing' => 'anyone, including anonymous links',
        'ExternalUserSharingOnly' => 'new and existing guests',
        'ExistingExternalUserSharingOnly' => 'existing guests only',
        'Disabled' => 'people in the organisation only',
    ];

    public const PORTAL = [
        'roles' => 'https://purview.microsoft.com/settings/roles',
        'audit' => 'https://purview.microsoft.com/audit/auditsearch',
        'labels' => 'https://purview.microsoft.com/informationprotection/informationprotectionlabels/sensitivitylabels',
        'label_policies' => 'https://purview.microsoft.com/informationprotection/labelpolicies',
        'autolabel' => 'https://purview.microsoft.com/informationprotection/autolabeling',
        'dlp' => 'https://purview.microsoft.com/datalossprevention/policies',
        'dlp_alerts' => 'https://purview.microsoft.com/datalossprevention/alerts',
        'content_explorer' => 'https://purview.microsoft.com/dataclassification/contentexplorer',
        'activity_explorer' => 'https://purview.microsoft.com/dataclassification/activityexplorer',
        'retention' => 'https://purview.microsoft.com/datalifecyclemanagement/policies',
        'retention_labels' => 'https://purview.microsoft.com/datalifecyclemanagement/labels',
        'records' => 'https://purview.microsoft.com/recordsmanagement',
        'irm' => 'https://purview.microsoft.com/insiderriskmgmt',
        'irm_settings' => 'https://purview.microsoft.com/insiderriskmgmt/settings',
        'devices' => 'https://purview.microsoft.com/settings/deviceonboarding',
        'connectors' => 'https://purview.microsoft.com/settings/dataconnectors',
    ];

    /**
     * Requirements questionnaire: section => [title, help, fields].
     * field => [label, type (text|email|number|select|multi|bool|date|users), options|null, default, help]
     */
    public static function questionnaire(): array
    {
        return [
            'organisation' => ['Organisation', 'Who the programme is for and who owns it.', [
                'industry' => ['Industry', 'select', self::INDUSTRIES, 'professional', null],
                'region' => ['Primary jurisdiction', 'select', self::REGIONS, 'AU', 'Drives which identifiers and retention periods are proposed.'],
                'users' => ['Number of users', 'number', null, 50, null],
                'owner_name' => ['Compliance owner (approves the design)', 'text', null, '', 'The business person accountable for data protection.'],
                'owner_email' => ['Compliance owner email', 'email', null, '', 'Receives DLP incident reports.'],
                'start_date' => ['Programme start date', 'date', null, null, 'Due dates in the plan are calculated from this.'],
            ]],
            'regulations' => ['Regulations and frameworks', 'Obligations the organisation must meet.', [
                'regulations' => ['Applicable regulations', 'multi', array_map(fn ($r) => $r[0], self::REGULATIONS), [], null],
            ]],
            'data' => ['Sensitive data', 'What sensitive information the organisation creates, receives or stores.', [
                'data_types' => ['Sensitive data handled', 'multi', array_map(fn ($d) => $d[0], self::DATA_TYPES), ['credentials'], null],
                'top_risk' => ['Biggest data risk in their words', 'text', null, '', 'For example: client files emailed to personal accounts.'],
            ]],
            'workloads' => ['Where data lives and how it is shared', 'Locations in scope and collaboration patterns.', [
                'workloads' => ['Locations in scope', 'multi', ['exchange' => 'Exchange email', 'sharepoint' => 'SharePoint sites', 'onedrive' => 'OneDrive', 'teams' => 'Teams chats and channels', 'endpoint' => 'Windows / macOS devices'], ['exchange', 'sharepoint', 'onedrive', 'teams'], null],
                'external_sharing' => ['External collaboration', 'select', ['none' => 'Rare: internal only', 'partners' => 'Regular with known partners', 'broad' => 'Constant with many external parties'], 'partners', null],
                'devices_managed' => ['Devices are enrolled in Intune or Defender', 'bool', null, true, 'Needed for Endpoint DLP and device insider risk signals.'],
            ]],
            'protection' => ['Protection approach', 'How strict the controls should be.', [
                'labelling' => ['Labelling approach', 'select', ['manual' => 'Users choose labels (with a default)', 'recommend' => 'Recommend labels when sensitive data is found', 'auto' => 'Apply labels automatically where possible'], 'recommend', null],
                'mandatory' => ['Require a label on every document and email', 'bool', null, false, 'Increases coverage; plan user training first.'],
                'encryption' => ['Encrypt highly confidential content', 'bool', null, true, 'Protection travels with the file, even outside the organisation.'],
                'partners_need_access' => ['External partners must open protected files', 'bool', null, true, 'Adds an "Anyone (unrestricted)" Confidential option without encryption.'],
                'dlp_style' => ['DLP response', 'select', ['notify' => 'Educate: policy tips and reports only', 'block_override' => 'Block sharing externally, users can override with a reason', 'block' => 'Block sharing externally, no override'], 'block_override', null],
                'container_labels' => ['Use labels on Teams, groups and sites', 'bool', null, true, 'Controls privacy and guest access by label.'],
            ]],
            'retention' => ['Retention and records', 'How long content is kept.', [
                'baseline_years' => ['Keep all email and files for (years)', 'number', null, 7, 'Organisation-wide minimum. 0 means no baseline policy.'],
                'baseline_delete' => ['Delete content after the baseline period', 'bool', null, false, 'Off: content is kept at least this long (deleted items stay recoverable). On: it is also deleted at the end.'],
                'teams_years' => ['Keep Teams chats for (years)', 'number', null, 3, null],
                'record_classes' => ['Record classes needing their own retention label', 'multi', array_map(fn ($r) => $r[0], self::RETENTION), ['finance', 'hr', 'legal'], null],
                'disposition_review' => ['Review before records are deleted', 'bool', null, true, 'Needs records management licensing.'],
            ]],
            'insider' => ['Insider risk', 'Risks from people inside the organisation.', [
                'irm_concerns' => ['Concerns', 'multi', ['departing' => 'Data theft by departing employees', 'leaks' => 'Data leaks (accidental or deliberate)', 'security' => 'Security policy violations', 'priority' => 'Activity of priority users (executives, admins)'], ['departing', 'leaks'], null],
                'hr_connector' => ['HR system can send resignation and termination dates', 'bool', null, false, 'Improves departing-employee detection.'],
                'privacy_approved' => ['Legal / HR / works council approved insider risk monitoring', 'bool', null, false, 'Required before insider risk policies are switched on.'],
            ]],
            'pilot' => ['Pilot', 'Who tests first.', [
                'pilot_users' => ['Pilot members', 'users', null, [], 'Pick 5 to 20 people across departments, including a manager and someone from IT.'],
                'pilot_weeks' => ['Pilot length (weeks)', 'number', null, 4, null],
            ]],
        ];
    }

    public static function defaults(): array
    {
        $out = [];
        foreach (self::questionnaire() as [, , $fields]) {
            foreach ($fields as $k => $f) {
                $out[$k] = $f[3];
            }
        }
        $out['start_date'] = now()->addWeek()->startOfWeek()->toDateString();

        return $out;
    }

    /** SITs for the selected data types and region (deduplicated). */
    public static function sits(array $dataTypes, string $region): array
    {
        $out = [];
        foreach ($dataTypes as $dt) {
            $map = self::SITS[$dt] ?? [];
            $out = array_merge($out, $map['*'] ?? [], $map[$region] ?? []);
        }

        return array_values(array_unique($out));
    }
}
