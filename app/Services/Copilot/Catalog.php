<?php

namespace App\Services\Copilot;

/** Microsoft 365 Copilot knowledge: licensing, prerequisites, data approaches, scenarios and the questionnaire. */
final class Catalog
{
    public const STAGES = [
        'requirements' => ['Requirements', 'Goals, people, data protection approach and feature choices.'],
        'assess' => ['Assess', 'Live readiness check of licences, users, security and oversharing.'],
        'prepare' => ['Prepare', 'Fix prerequisites and reduce oversharing before anyone gets Copilot.'],
        'pilot' => ['Pilot', 'A small cohort with training, measured against success criteria.'],
        'rollout' => ['Rollout', 'Waves of users with licences, training and support.'],
        'adopt' => ['Adopt', 'Usage, value, licence reclaim and continuous governance.'],
    ];

    public const PHASES = ['prepare', 'pilot', 'rollout', 'adopt'];

    /** Base licences that qualify for the Microsoft 365 Copilot add-on (SKU part numbers). */
    public const ELIGIBLE_BASE = [
        'SPE_E5', 'SPE_E3', 'SPE_F1', 'SPE_E5_NOPSTNCONF', 'SPE_E3_USGOV_GCCHIGH', 'Microsoft_365_E5_(no_Teams)', 'Microsoft_365_E3_(no_Teams)',
        'ENTERPRISEPREMIUM', 'ENTERPRISEPACK', 'STANDARDPACK', 'ENTERPRISEPREMIUM_NOPSTNCONF', 'O365_w/o_Teams_Bundle_M3', 'O365_w/o_Teams_Bundle_M5',
        'SPB', 'O365_BUSINESS_PREMIUM', 'O365_BUSINESS_ESSENTIALS', 'SMB_BUSINESS_PREMIUM', 'SMB_BUSINESS_ESSENTIALS',
        'M365EDU_A3_FACULTY', 'M365EDU_A5_FACULTY', 'ENTERPRISEPACK_FACULTY', 'ENTERPRISEPREMIUM_FACULTY', 'DESKLESSPACK', 'M365_F1', 'M365_F1_COMM',
    ];

    /** Service plans per readiness need (any of). */
    public const PLANS = [
        'mailbox' => ['EXCHANGE_S_STANDARD', 'EXCHANGE_S_ENTERPRISE', 'EXCHANGE_S_DESKLESS'],
        'onedrive' => ['SHAREPOINTSTANDARD', 'SHAREPOINTENTERPRISE', 'SHAREPOINTDESKLESS'],
        'teams' => ['TEAMS1', 'TEAMS_AR_DOD', 'TEAMS_AR_GCCHIGH'],
        'apps' => ['OFFICESUBSCRIPTION', 'O365_BUSINESS', 'OFFICE_BUSINESS', 'OFFICESUBSCRIPTION_unattended'],
        'copilot' => ['M365_COPILOT_APPS', 'M365_COPILOT_TEAMS', 'M365_COPILOT_BUSINESS_CHAT', 'M365_COPILOT_SHAREPOINT'],
    ];

    public static function isCopilotSku(string $part): bool
    {
        return (bool) preg_match('/^(Microsoft_365_Copilot|M365_COPILOT|Microsoft_Copilot_for_M365)/i', $part);
    }

    public const DATA_APPROACHES = [
        'strict' => ['Strict: restrict search during the pilot', 'Restricted SharePoint Search limits Copilot and search to an allow-list of up to 100 curated sites while oversharing is fixed. Restricted Content Discovery hides sensitive sites. Removed before full rollout.'],
        'balanced' => ['Balanced: hide sensitive sites, fix oversharing', 'Restricted Content Discovery hides named sensitive sites from Copilot and organisation-wide search. Oversharing reports drive remediation. Labels and DLP protect regulated content.'],
        'standard' => ['Standard: fix obvious oversharing only', 'Public groups and anyone-links are fixed. Suitable only where content is already well permissioned.'],
    ];

    public const GOALS = [
        'meetings' => ['Meetings and email', 'Teams and Outlook', [
            'Summarise this meeting: decisions, open questions and who owns each action.',
            'Draft a reply to this email that accepts the proposal but asks for the delivery date in writing.',
            'What did I miss in the last week in the [project] Teams channel?',
        ]],
        'documents' => ['Drafting documents', 'Word and PowerPoint', [
            'Draft a one-page proposal for [client] based on /[existing document], in a formal tone.',
            'Rewrite this section for a non-technical audience in under 150 words.',
            'Create a 10-slide deck from /[document] with speaker notes.',
        ]],
        'analysis' => ['Data analysis', 'Excel', [
            'Which three products had the biggest change in revenue compared to last quarter?',
            'Add a column that flags invoices older than 60 days.',
            'Show this data as a chart by region and explain the trend.',
        ]],
        'knowledge' => ['Finding information', 'Copilot Chat (work)', [
            'What is our policy on [topic]? Cite the source documents.',
            'Summarise everything we have on [client] from email, chats and files this month.',
            'Prepare me for my 2 pm meeting: who is attending and what we last discussed.',
        ]],
        'service' => ['Customer service', 'Outlook and Copilot Chat', [
            'Draft a response to this complaint that apologises, explains the cause and offers the next step.',
            'Summarise this customer thread and list the commitments we made.',
        ]],
        'sales' => ['Sales', 'Outlook, Teams and Word', [
            'Create a follow-up email after this meeting with the agreed next steps and pricing.',
            'Draft a statement of work outline for [client] from our proposal template.',
        ]],
        'operations' => ['HR, finance and operations', 'Word, Excel and Copilot Chat', [
            'Turn these notes into a standard operating procedure with numbered steps.',
            'Compare these two contracts and list the differences in payment terms.',
        ]],
    ];

    public const PORTAL = [
        'm365_licenses' => 'https://admin.microsoft.com/#/licenses',
        'copilot_settings' => 'https://admin.microsoft.com/#/copilot',
        'readiness_report' => 'https://admin.microsoft.com/#/copilot/overview',
        'usage_reports' => 'https://admin.microsoft.com/#/reportsUsage/MicrosoftCopilot',
        'reports_privacy' => 'https://admin.microsoft.com/#/Settings/Services/:/Settings/L1/Reports',
        'integrated_apps' => 'https://admin.microsoft.com/#/Settings/IntegratedApps',
        'apps_admin' => 'https://config.office.com/officeSettings/serviceprofile',
        'sp_admin' => 'https://admin.microsoft.com/sharepoint',
        'purview_dspm_ai' => 'https://purview.microsoft.com/dspmforai',
        'purview_dlp' => 'https://purview.microsoft.com/datalossprevention/policies',
        'purview_retention' => 'https://purview.microsoft.com/datalifecyclemanagement/policies',
        'viva_dashboard' => 'https://analytics.cloud.microsoft/',
        'adoption' => 'https://adoption.microsoft.com/copilot/',
        'network' => 'https://learn.microsoft.com/microsoft-365/enterprise/urls-and-ip-address-ranges',
    ];

    /** section => [title, help, fields]; field => [label, type, options, default, help] */
    public static function questionnaire(): array
    {
        return [
            'programme' => ['Programme', 'Sponsors and timeline.', [
                'sponsor_name' => ['Business sponsor (approves the gates)', 'text', null, '', 'Accountable for value and adoption, not just IT.'],
                'sponsor_email' => ['Sponsor email', 'email', null, '', null],
                'it_owner' => ['IT owner', 'text', null, '', null],
                'start_date' => ['Programme start', 'date', null, null, 'Due dates in the plan are calculated from this.'],
                'seats_planned' => ['Copilot seats to buy in total', 'number', null, 25, 'Seats already in the tenant are detected automatically.'],
            ]],
            'goals' => ['Business goals', 'Why the organisation wants Copilot. Drives training scenarios and success measures.', [
                'goals' => ['Priority scenarios', 'multi', array_map(fn ($g) => $g[0], self::GOALS), ['meetings', 'documents', 'knowledge'], null],
                'goal_text' => ['Expected outcome in their words', 'text', null, '', 'For example: halve the time spent writing client reports.'],
                'target_active' => ['Target: % of licensed users active weekly', 'number', null, 70, null],
                'target_hours' => ['Target: hours saved per user per week', 'number', null, 2, null],
                'target_satisfaction' => ['Target: % of pilot users who want to keep Copilot', 'number', null, 80, null],
            ]],
            'data' => ['Data protection approach', 'Copilot only shows what a user can already access, so oversharing becomes visible.', [
                'data_approach' => ['Approach', 'select', array_map(fn ($d) => $d[0], self::DATA_APPROACHES), 'balanced', null],
                'sensitive_sites' => ['Sensitive SharePoint sites to hide from Copilot (one URL per line)', 'textarea', null, '', 'HR, finance, legal, board. Restricted Content Discovery is applied to these.'],
                'allowed_sites' => ['Strict approach: sites Copilot may search during the pilot (one URL per line, max 100)', 'textarea', null, '', 'Intranet, policies, templates and the pilot teams\' own sites.'],
                'fix_links' => ['Make "people in your organisation" the default sharing link (not anyone links)', 'bool', null, true, null],
            ]],
            'features' => ['Features and policies', 'What users can do with Copilot.', [
                'web_search' => ['Allow web search for grounding answers', 'bool', null, true, 'Off keeps answers to work data only.'],
                'agents' => ['Agents and plugins', 'select', ['none' => 'None during the pilot', 'approved' => 'Only agents approved by IT', 'all' => 'All Microsoft and approved third-party agents'], 'approved', null],
                'meetings' => ['Copilot in Teams meetings', 'select', ['with_transcript' => 'With transcription (recall after the meeting)', 'without_transcript' => 'During the meeting only, no transcript kept', 'off' => 'Off'], 'with_transcript', null],
                'copilot_chat' => ['Offer free Copilot Chat to unlicensed users', 'bool', null, true, 'Web-grounded chat with enterprise data protection.'],
            ]],
            'people' => ['People and change', 'Adoption is a people project.', [
                'works_council' => ['Works council, union or HR consultation is required', 'bool', null, false, null],
                'consulted' => ['Consultation completed', 'bool', null, false, null],
                'champions' => ['Number of champions', 'number', null, 3, 'About one per 20 users.'],
                'training' => ['Training approach', 'select', ['live' => 'Live sessions', 'self' => 'Self-paced (Microsoft adoption content)', 'both' => 'Both'], 'both', null],
            ]],
            'pilot' => ['Pilot and rollout', 'Who goes first and how fast.', [
                'pilot_users' => ['Pilot members', 'users', null, [], 'Pick 10 to 50 people across roles, including champions and sceptics.'],
                'pilot_weeks' => ['Pilot length (weeks)', 'number', null, 6, 'Habits form in 4 to 8 weeks.'],
                'wave_size' => ['Users per rollout wave', 'number', null, 25, null],
                'wave_weeks' => ['Weeks between waves', 'number', null, 2, null],
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

    public static function lines(?string $text): array
    {
        return array_values(array_filter(array_map('trim', preg_split('/\r\n|\r|\n/', (string) $text)), fn ($l) => preg_match('#^https://[^\s]+$#i', $l)));
    }
}
