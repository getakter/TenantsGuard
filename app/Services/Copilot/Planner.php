<?php

namespace App\Services\Copilot;

/** The Copilot implementation plan, generated from the requirements. Same task shape as the Purview planner. */
final class Planner
{
    public const METHODS = \App\Services\Purview\Planner::METHODS;

    public static function tasks(array $req, string $tenantName): array
    {
        $p = Catalog::PORTAL;
        $approach = $req['data_approach'] ?? 'balanced';
        $sites = Catalog::lines($req['sensitive_sites'] ?? '');
        $pilotEnd = 21 + 7 * max(2, (int) ($req['pilot_weeks'] ?? 6));
        $sponsor = $req['sponsor_name'] ?: 'Business sponsor';
        $pilotCount = count($req['pilot_users'] ?? []);
        $remaining = max(0, (int) ($req['seats_planned'] ?? 0) - $pilotCount);
        $waveSize = max(1, (int) ($req['wave_size'] ?? 25));
        $waves = min(8, max(1, (int) ceil($remaining / $waveSize)));
        $gap = max(1, (int) ($req['wave_weeks'] ?? 2)) * 7;

        $t = [];
        $add = function (string $phase, string $key, string $title, string $method, string $why, array $steps = [], ?string $link = null, ?string $verify = null, int $due = 0, string $who = 'IT administrator', bool $required = true) use (&$t) {
            $t[] = compact('phase', 'key', 'title', 'method', 'why', 'steps', 'link', 'verify', 'due', 'who', 'required');
        };

        // ------------------------------------------------------------------ Prepare
        $add('prepare', 'licences', 'Confirm Copilot seats and eligible base licences', 'auto',
            'Copilot is an add-on to an eligible Microsoft 365 or Office 365 base licence. Seat shortfalls change the pilot and wave sizes.',
            ['TenantsGuard re-runs the readiness assessment and checks seats and base licences.', 'Order missing seats through your licensing partner.'], $p['m365_licenses'], 'licences_copilot', 3);
        $add('prepare', 'user_services', 'Pilot users have mailbox, OneDrive, Teams and Office apps', 'auto',
            'Copilot grounds answers in the user\'s mail, files, chats and meetings. Missing services mean weak answers and a poor pilot.',
            ['TenantsGuard checks every pilot user\'s service plans.', 'Fix gaps by assigning an eligible base licence (People > user) and letting OneDrive provision (the user opens OneDrive once).'], null, 'pilot_services', 5);
        $add('prepare', 'usage_location', 'Usage location set for every Copilot user', 'auto',
            'Licences cannot be assigned without a usage location.', ['TenantsGuard checks all members.', 'Set the missing usage locations in Entra ID.'], null, 'usage_location', 5, 'IT administrator', false);
        $add('prepare', 'apps_channel', 'Microsoft 365 Apps on a supported update channel', 'portal',
            'Copilot in Word, Excel, PowerPoint and Outlook desktop needs current Microsoft 365 Apps builds (Current Channel or Monthly Enterprise Channel recommended).',
            ['Microsoft 365 Apps admin center or Intune: set the update channel for Copilot users.', 'Check that pilot devices run a supported build (Help > About).', 'New Outlook or classic Outlook on a supported build.'], $p['apps_admin'], null, 7);
        $add('prepare', 'network', 'Allow Copilot network endpoints and WebSocket connections', 'manual',
            'Copilot uses WebSocket connections to *.cloud.microsoft and *.office.com. Proxies that break WebSockets or TLS inspection cause Copilot to fail silently.',
            ['Confirm the Microsoft 365 endpoint categories "Optimize" and "Allow" bypass TLS inspection.', 'Allow WebSocket (wss://) to *.cloud.microsoft, *.office.com and *.office.net.', 'Test Copilot in a browser from the office network before the pilot.'], $p['network'], null, 7);
        $add('prepare', 'mfa', 'Enforce MFA and fix critical identity findings', 'auto',
            'Copilot amplifies a compromised account: an attacker can ask it for everything the user can reach.',
            ['TenantsGuard checks the latest posture scan: MFA enforcement, registration and critical findings.', 'Fix them in Security posture (MFA for all users, block legacy authentication).'], null, 'mfa', 7);
        $add('prepare', 'oversharing_reports', 'Run SharePoint oversharing reports (Data access governance)', 'script',
            'Shows sites shared with "Everyone except external users", anyone-links and widely shared files. This is the main Copilot data risk.',
            ['Run the Prepare script (SharePoint section). SharePoint Advanced Management is included with Copilot licences.', 'Open the reports in SharePoint admin center > Reports > Data access governance.', 'Agree a remediation owner for each top site.'], $p['sp_admin'], null, 10);
        $add('prepare', 'public_groups', 'Make public Teams and groups private where content is not for everyone', 'auto',
            'Files in public Teams and groups are readable by the whole organisation, so Copilot surfaces them to anyone who asks.',
            ['Implement tab > Public Teams and groups: review the list with the owners and make the right ones private.'], null, 'public_groups', 12);
        if ($req['fix_links'] ?? true) {
            $add('prepare', 'sharing_links', 'Default sharing link: people in the organisation', 'script',
                'Stops new "anyone" links being created by default.',
                ['Run the Prepare script: sets the default link to "people in your organisation" with view permission.'], $p['sp_admin'], 'sharing', 10);
        }
        if ($approach !== 'standard') {
            $add('prepare', 'rcd', 'Hide sensitive sites from Copilot (Restricted Content Discovery)', 'script',
                'Sensitive sites stay accessible to their members but are not surfaced by Copilot or organisation-wide search to others.',
                [$sites ? 'Run the Prepare script: Restricted Content Discovery for '.count($sites).' site(s).' : 'List sensitive sites in the requirements first, then download the Prepare script.'], $p['sp_admin'], 'sensitive_sites', 12);
        }
        if ($approach === 'strict') {
            $add('prepare', 'rss', 'Turn on Restricted SharePoint Search for the pilot', 'script',
                'Limits Copilot and organisation-wide search to an allow-list of curated sites while oversharing is remediated. Users still see their own files and sites they visit.',
                ['Run the Prepare script: Restricted SharePoint Search with the allowed sites from the requirements (max 100).', 'It is switched off again in the Rollout phase.'], $p['sp_admin'], null, 14);
        }
        $add('prepare', 'labels_dlp', 'Sensitivity labels and DLP for Microsoft 365 Copilot', 'portal',
            'Copilot respects labels and encryption. A DLP policy for the "Microsoft 365 Copilot" location can stop it from processing Highly Confidential content.',
            ['Use the Purview programme for this client for labels, DLP and retention.', 'Purview > DLP > create policy > location "Microsoft 365 Copilot": exclude content labelled Highly Confidential from Copilot processing.',
                'Purview > DSPM for AI: turn on the recommended policies to see AI interactions with sensitive data.'], $p['purview_dlp'], 'labels', 14, 'Compliance owner', $approach !== 'standard');
        $add('prepare', 'audit_retention', 'Audit and retention of Copilot interactions', 'portal',
            'Prompts and responses are audited and can be retained or deleted like Teams chats. Decide the period before the pilot.',
            ['Confirm unified audit logging is on (Purview programme Foundations).', 'Purview > Data lifecycle management > retention policy for "Microsoft Copilot experiences" with the agreed period.'], $p['purview_retention'], null, 14, 'Compliance owner');
        $add('prepare', 'reports_privacy', 'Decide on user names in usage reports', 'portal',
            'Concealed names hide who uses Copilot, which blocks per-user adoption tracking and licence reclaim.',
            ['Agree with HR/privacy.', 'Microsoft 365 admin center > Settings > Org settings > Reports: untick "Display concealed user, group and site names".'], $p['reports_privacy'], 'reports_privacy', 10, 'IT administrator', false);
        if (($req['meetings'] ?? 'with_transcript') !== 'off') {
            $add('prepare', 'teams_meetings', 'Teams meeting policy for Copilot', 'script',
                ($req['meetings'] ?? '') === 'with_transcript' ? 'Copilot in meetings with transcription lets people ask about the meeting afterwards.' : 'Copilot during meetings only; no transcript is kept.',
                ['Run the Prepare script (Microsoft Teams section).', 'Tell meeting organisers how Copilot and transcription notifications look to attendees.'], null, null, 12);
        }
        $add('prepare', 'copilot_settings', 'Copilot settings: web search, agents, pinning, Copilot Chat', 'portal',
            'Sets what licensed and unlicensed users can do, according to the requirements.',
            ['Microsoft 365 admin center > Copilot > Settings.', 'Web search: '.(($req['web_search'] ?? true) ? 'allowed' : 'turned off (policy "Allow web search in Copilot")').'.',
                'Agents: '.['none' => 'block all agents during the pilot', 'approved' => 'allow only agents approved in Integrated apps', 'all' => 'allow Microsoft and approved third-party agents'][$req['agents'] ?? 'approved'].'.',
                'Copilot Chat for unlicensed users: '.(($req['copilot_chat'] ?? true) ? 'pin it in Teams, Outlook and the Microsoft 365 app' : 'do not pin').'.'], $p['copilot_settings'], null, 14);
        if ($req['works_council'] ?? false) {
            $add('prepare', 'consultation', 'Works council / union / HR consultation', 'manual',
                'Copilot processes employee communications; consultation is required before the pilot.',
                ['Share the readiness report and pilot charter.', 'Record the outcome and update the requirements ("Consultation completed").'], null, 'consultation', 14, $sponsor);
        }
        $add('prepare', 'change_plan', 'Champions, training and communication plan', 'manual',
            'Copilot value comes from habits, not licences. Champions and scenario-based training double active usage.',
            ['Nominate '.($req['champions'] ?? 3).' champions.', 'Training: '.['live' => 'live sessions', 'self' => 'self-paced adoption content', 'both' => 'live kick-off plus self-paced content'][$req['training'] ?? 'both'].'.',
                'Use the pilot charter (prompt library, survey) and the announcement email from Documents.'], $p['adoption'], null, 16, $sponsor);
        $add('prepare', 'gate_readiness', 'Ready for pilot', 'gate',
            'The pilot starts only when users, security and data exposure are under control.',
            ['Readiness score reviewed; failed checks fixed or accepted.', 'Sponsor signs off.'], null, 'gate_readiness', 20, $sponsor);

        // ------------------------------------------------------------------ Pilot
        $add('pilot', 'pilot_group', 'Create the "Copilot Pilot" group with pilot members', 'auto',
            'One group for pilot communication, policies and reporting.', ['TenantsGuard creates a security group and adds the '.$pilotCount.' pilot member(s).'], null, null, 21);
        $add('pilot', 'pilot_licences', 'Assign Copilot licences to the pilot', 'auto',
            'Licences go to pilot members only; seats are checked first.', ['TenantsGuard assigns Copilot to every pilot member with an eligible base licence.', 'Copilot appears in apps within 24 hours.'], $p['m365_licenses'], 'pilot_licences', 22);
        $add('pilot', 'pilot_kickoff', 'Kick-off, training and prompt library', 'manual',
            'Show people what good looks like in their own work on day one.', ['Run the kick-off with the pilot charter scenarios.', 'Share the prompt library and how to give feedback.'], $p['adoption'], null, 22, $sponsor);
        $add('pilot', 'pilot_baseline', 'Baseline survey', 'manual',
            'Without a baseline, value cannot be shown later.', ['Send the baseline questions from the pilot charter before people start using Copilot.'], null, null, 22, $sponsor);
        $add('pilot', 'pilot_usage', 'Weekly usage review', 'auto',
            'Low usage in week 2 predicts failure. Nudge, coach or swap inactive pilot users.', ['TenantsGuard pulls the Copilot usage report (Reports.Read.All) and compares weekly active users with the target ('.($req['target_active'] ?? 70).'%).'], $p['usage_reports'], 'pilot_usage', 35);
        $add('pilot', 'pilot_issues', 'Track oversharing findings and issues', 'manual',
            'Pilot users will find content they should not see. Each finding is a permissions fix, not a Copilot problem.', ['Log every "Copilot showed me X" report.', 'Fix the permissions at the source and record it in the evidence note.'], null, null, $pilotEnd - 7);
        $add('pilot', 'pilot_eval', 'Evaluate the pilot against the success criteria', 'manual',
            'Go or no-go needs evidence: usage, time saved, satisfaction and issues.',
            ['Send the end-of-pilot survey (pilot charter).', 'Compare with targets: '.($req['target_active'] ?? 70).'% weekly active, '.($req['target_hours'] ?? 2).' h/week saved, '.($req['target_satisfaction'] ?? 80).'% want to keep it.'], $p['viva_dashboard'], null, $pilotEnd - 2, $sponsor);
        $add('pilot', 'gate_pilot', 'Pilot exit: go for rollout', 'gate',
            'Rollout only with evidence of value and no open data exposure issues.', ['Success criteria met or accepted.', 'Oversharing findings fixed.', 'Sponsor signs off.'], null, 'gate_pilot', $pilotEnd, $sponsor);

        // ------------------------------------------------------------------ Rollout
        for ($w = 1; $w <= $waves; $w++) {
            $add('rollout', 'wave_'.$w, "Wave {$w}: assign Copilot to the next ".min($waveSize, max(1, $remaining - ($w - 1) * $waveSize)).' users', 'auto',
                'Waves keep training and support manageable and let you watch for issues.',
                ['Implement tab > Licence assignment: pick the wave (by department or role) and assign.', 'Send the announcement and training invitation to the wave the same day.'], $p['m365_licenses'], null, $pilotEnd + 3 + ($w - 1) * $gap);
        }
        $lastWave = $pilotEnd + 3 + ($waves - 1) * $gap;
        if ($approach === 'strict') {
            $add('rollout', 'rss_off', 'Turn off Restricted SharePoint Search', 'script',
                'Restricted SharePoint Search is a temporary measure. Turn it off once oversharing is remediated, otherwise Copilot stays limited to the allow-list.',
                ['Confirm the oversharing reports are clean for the top sites.', 'Run the Rollout script.'], $p['sp_admin'], null, $pilotEnd + 7);
        }
        $add('rollout', 'training_all', 'Training for every wave', 'manual', 'Each wave gets the same scenario-based start as the pilot.', ['Live or recorded session per wave, plus the prompt library.'], $p['adoption'], null, $lastWave, $sponsor);
        $add('rollout', 'champions', 'Champion community', 'manual', 'Peers drive adoption more than IT.', ['Monthly champion call: share prompts that worked, collect issues.'], null, null, $lastWave, $sponsor, false);
        $add('rollout', 'gate_rollout', 'Rollout complete', 'gate', 'Formal handover to business-as-usual.', ['All waves licensed and trained.', 'Sponsor signs off.'], null, 'gate_rollout', $lastWave + 7, $sponsor);

        // ------------------------------------------------------------------ Adopt
        $add('adopt', 'usage_monthly', 'Monthly: usage review and licence reclaim', 'auto',
            'Copilot seats are expensive. Reassign seats from people who do not use Copilot after coaching.', ['TenantsGuard pulls usage and lists licensed users inactive for 30 days.', 'Coach them first; reclaim in the Licence assignment tool if still inactive.'], $p['usage_reports'], null, $lastWave + 37, 'IT administrator', false);
        $add('adopt', 'value_report', 'Quarterly: value report to the sponsor', 'manual', 'Keeps the investment justified.', ['Copilot Dashboard (Viva Insights) or the survey: active users, time saved, top scenarios.'], $p['viva_dashboard'], null, $lastWave + 90, $sponsor, false);
        $add('adopt', 'oversharing_quarterly', 'Quarterly: oversharing review', 'script', 'New sites and sharing appear every week.', ['Run the Adopt script: refreshes the data access governance reports.'], $p['sp_admin'], null, $lastWave + 90, 'IT administrator', false);
        $add('adopt', 'agents_review', 'Quarterly: review agents and plugins', 'portal', 'New agents can reach new data sources.', ['Integrated apps: review requested and deployed agents.'], $p['integrated_apps'], null, $lastWave + 90, 'IT administrator', false);

        return $t;
    }
}
