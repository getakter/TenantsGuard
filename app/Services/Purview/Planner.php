<?php

namespace App\Services\Purview;

/**
 * The implementation plan: tasks per phase generated from the design.
 *
 * method: auto (TenantsGuard does it through Graph), script (generated PowerShell),
 *         portal (guided steps in the Purview portal), manual (people/process), gate (sign-off)
 * verify: key the Verifier understands, or null
 */
final class Planner
{
    public const METHODS = [
        'auto' => ['TenantsGuard', 's-pass'],
        'script' => ['PowerShell', 's-info'],
        'portal' => ['Purview portal', 's-manual'],
        'manual' => ['People / process', 's-na'],
        'gate' => ['Sign-off gate', 's-fail'],
    ];

    /** @return array<int, array> */
    public static function tasks(array $design, array $req): array
    {
        $p = Catalog::PORTAL;
        $pilotEnd = 21 + 7 * ($design['pilot']['weeks'] ?? 4);
        $dlp = array_values(array_filter($design['dlp'], fn ($x) => $x['include']));
        $auto = array_values(array_filter($design['autolabel'], fn ($x) => $x['include']));
        $retLabels = array_values(array_filter($design['retention']['labels'], fn ($x) => $x['include']));
        $irm = array_values(array_filter($design['irm']['policies'], fn ($x) => $x['include']));
        $endpoint = collect($dlp)->contains(fn ($x) => in_array('Devices', $x['locations'], true));
        $encryption = collect($design['labels'])->contains(fn ($l) => $l['include'] && $l['encryption'] !== 'none');
        $containers = collect($design['labels'])->contains(fn ($l) => $l['include'] && $l['container']);
        $owner = $req['owner_name'] ?: 'Compliance owner';

        $t = [];
        $add = function (string $phase, string $key, string $title, string $method, string $why, array $steps = [], ?string $link = null, ?string $verify = null, int $due = 0, string $who = 'Purview administrator', bool $required = true) use (&$t) {
            $t[] = compact('phase', 'key', 'title', 'method', 'why', 'steps', 'link', 'verify', 'due', 'who', 'required');
        };

        // ------------------------------------------------------------------ Foundations
        $add('foundations', 'licences', 'Confirm licensing for every planned capability', 'auto',
            'Some Purview features need E5-level licences. Gaps change the design, so confirm them before anything is built.',
            ['TenantsGuard reads the subscribed licences and lists missing capabilities on the overview.', 'Buy the add-on, or accept the reduced design and record the decision.'], null, 'licences', 2);
        $add('foundations', 'roles', 'Assign Purview admin roles (least privilege)', 'portal',
            'Separate who configures policies from who investigates content. Global Administrator is not needed for day-to-day Purview work.',
            ['Purview portal > Settings > Roles and scopes > Role groups.', 'Information Protection Admins: the people who build labels and DLP.', 'Compliance Administrator: the Purview administrator.',
                'Insider Risk Management Admins / Analysts / Investigators: separate people, approved by HR and legal.', 'Record who has which role in the evidence note.'], $p['roles'], null, 3);
        $add('foundations', 'audit', 'Turn on unified audit logging', 'script',
            'DLP, labelling and insider risk all depend on audit events. Without audit, Activity explorer and alerts stay empty.',
            ['Run the Foundations script (Exchange Online section).', 'Verify: the verification export shows audit ingestion enabled.'], $p['audit'], 'audit', 3);
        $add('foundations', 'pilot_group', 'Create the "'.$design['pilot']['group_name'].'" group with pilot members', 'auto',
            'Label, retention and DLP policies are scoped to this mail-enabled group during the pilot.',
            ['TenantsGuard creates a Microsoft 365 group (mail-enabled, private) and adds the pilot members chosen in the requirements.'], null, 'pilot_group', 4);
        $add('foundations', 'spo_labels', 'Enable sensitivity labels for Office files in SharePoint and OneDrive', 'script',
            'Lets SharePoint and OneDrive read labelled and encrypted files for search, eDiscovery, co-authoring and DLP.',
            ['Run the Foundations script (SharePoint section, needs the SharePoint Online Management Shell).', 'Or Purview portal > Information protection > Sensitivity labels > "Turn on now".'], $p['labels'], null, 5);
        if ($encryption) {
            $add('foundations', 'coauth', 'Enable co-authoring for encrypted files', 'script',
                'Without it, encrypted Office files cannot be edited by several people at once and AutoSave is off.',
                ['Run the Foundations script (Security & Compliance section).'], $p['labels'], 'coauth', 5);
        }
        if ($containers) {
            $add('foundations', 'container_labels', 'Enable sensitivity labels for Teams, groups and sites', 'script',
                'Entra ID must allow labels on Microsoft 365 groups before container settings (privacy, guests, sharing) work.',
                ['Run the Foundations script (Microsoft Graph section) to set EnableMIPLabels.', 'Then synchronise labels to Entra (Execute-AzureADLabelSync in the same script).'], null, null, 5);
        }
        if ($endpoint) {
            $add('foundations', 'devices', 'Onboard Windows and macOS devices to Purview', 'portal',
                'Endpoint DLP and device insider risk signals only work on onboarded devices.',
                ['Purview portal > Settings > Device onboarding > turn on.', 'Devices already onboarded to Defender for Endpoint appear automatically.', 'Otherwise deploy the onboarding package with Intune.'], $p['devices'], null, 7);
        }
        $add('foundations', 'comms_plan', 'Agree the communication and support plan', 'manual',
            'Users accept labels and DLP when they know why. The helpdesk needs to know how to handle overrides and false positives.',
            ['Use the generated announcement email (Documents).', 'Brief the helpdesk: how to change a label, how to report a false positive, who triages DLP alerts.', 'Decide the help link shown in Office (label policy setting).'], null, null, 7, $owner);
        $add('foundations', 'incident_process', 'Define DLP and insider risk alert triage', 'manual',
            'Alerts without an owner are ignored. Decide who triages, how fast, and when HR or legal is involved.',
            ['Name the DLP alert triage owner and backup.', 'Agree response times (for example high severity within 1 business day).', 'Insider risk: investigators separate from IT administrators.'], $p['dlp_alerts'], null, 7, $owner);
        $add('foundations', 'gate_design', 'Design approved by the compliance owner', 'gate',
            'Nothing is deployed to users until the owner accepts the label taxonomy, DLP rules, retention periods and insider risk scope.',
            ['Review the design document.', 'Approve the design in TenantsGuard (Design tab).'], null, 'design_approved', 7, $owner);

        // ------------------------------------------------------------------ Discover
        $add('discover', 'labels_create', 'Create the sensitivity labels (not yet published)', 'script',
            'Labels can be created without anyone seeing them. Publishing is a separate step for the pilot.',
            ['Run the Discover script: creates '.collect($design['labels'])->where('include', true)->count().' labels with markings'.($encryption ? ', encryption' : '').($containers ? ' and container settings' : '').'.'], $p['labels'], 'labels', 9);
        if ($dlp) {
            $add('discover', 'dlp_simulation', 'Deploy DLP policies in simulation for everyone', 'script',
                'Simulation shows what would have matched without affecting users. It is the evidence for tuning thresholds.',
                ['Run the Discover script: creates '.count($dlp).' DLP policies in "test without notifications" mode.', 'Leave them running for at least 14 days.'], $p['dlp'], 'dlp_simulation', 10);
            $add('discover', 'dlp_review', 'Review simulation results and tune', 'portal',
                'False positives in production destroy trust. Tune instance counts, confidence levels and exclusions now.',
                ['Purview portal > Data loss prevention > Policies > each TG policy > View simulation.', 'Check top matched SITs and locations. Raise the high-volume threshold or confidence if noisy.', 'Record the findings in the evidence note.'], $p['dlp'], null, 24);
        }
        if ($auto) {
            $add('discover', 'autolabel_simulation', 'Run service-side auto-labelling in simulation', 'script',
                'Shows which files and emails would be labelled automatically before anything changes.',
                ['Run the Discover script: creates '.count($auto).' auto-labelling policies in simulation.', 'Review the matched items after the simulation completes.'], $p['autolabel'], 'autolabel_simulation', 10);
        }
        $add('discover', 'explorers', 'Review Content explorer and Activity explorer', 'portal',
            'Find where sensitive data actually lives (sites, mailboxes) and how it moves. Use this to prioritise sites for container labels.',
            ['Content explorer: list top 10 locations per sensitive information type.', 'Activity explorer: check labelling and DLP activity.', 'Note high-risk sites (for example HR, finance) for Highly Confidential container labels.'], $p['content_explorer'], null, 20);
        if ($irm && $design['irm']['licensed']) {
            $add('discover', 'irm_analytics', 'Turn on insider risk analytics and privacy settings', 'portal',
                'Analytics estimates risk from existing signals without creating alerts. Pseudonymisation protects user privacy.',
                ['Insider risk management > Settings > Privacy: show anonymised versions of usernames.', 'Settings > Analytics: turn on.', 'Settings > Policy indicators: Office indicators'.($endpoint ? ' and device indicators' : '').'.'], $p['irm_settings'], null, 14);
        }
        $add('discover', 'gate_discover', 'Discovery findings reviewed', 'gate',
            'The pilot starts only when simulation results are understood and the design has been tuned if needed.',
            ['Confirm the DLP and auto-labelling simulation was reviewed and tuned.', 'Sign off the discover gate.'], null, 'gate_discover', 24, $owner);

        // ------------------------------------------------------------------ Pilot
        $add('pilot', 'labels_publish_pilot', 'Publish labels to the pilot group', 'script',
            'The pilot group sees labels in Office, Outlook and Teams, with the default label and justification settings.',
            ['Run the Pilot script: label policy "'.Designer::PREFIX.'Sensitivity labels (pilot)".', 'Labels appear in apps within 24 hours. Ask pilot users to restart Office.'], $p['label_policies'], 'label_policy_pilot', 25);
        if ($dlp) {
            $add('pilot', 'dlp_pilot', 'Show DLP policy tips to the pilot group', 'script',
                'Pilot users see tips and overrides while everyone else stays in simulation.',
                ['Run the Pilot script: pilot DLP policies in "test with notifications" mode for pilot members.'], $p['dlp'], 'dlp_pilot', 26);
        }
        if ($retLabels) {
            $add('pilot', 'retention_labels', 'Create retention labels from the retention schedule', 'auto',
                'Retention labels let users and rules apply the right record period to specific content.',
                ['TenantsGuard creates '.count($retLabels).' retention labels through Microsoft Graph.'], $p['retention_labels'], 'retention_labels', 26);
            $add('pilot', 'retention_publish_pilot', 'Publish retention labels to the pilot group', 'script',
                'Pilot users can apply retention labels in Outlook, SharePoint and OneDrive.',
                ['Run the Pilot script: retention label policy for pilot members.'], $p['retention'], 'retention_publish_pilot', 27);
        }
        if ($design['autolabel'] ?? []) {
            $add('pilot', 'client_autolabel', 'Configure label recommendations in Office apps', 'portal',
                'Users are prompted to apply the right label when sensitive data is detected while they type.',
                collect($design['labels'])->filter(fn ($l) => $l['include'] && $l['recommend'])->map(fn ($l) => 'Edit label "'.$l['display'].'" > Auto-labeling for files and emails > '.($l['auto_mode'] === 'automatic' ? 'Automatically apply' : 'Recommend').' when content contains: '.implode(', ', array_slice($l['recommend'], 0, 6)).'.')->values()->all()
                ?: ['No label recommendations in the design.'], $p['labels'], null, 27, 'Purview administrator', false);
        }
        foreach ($irm as $pol) {
            $add('pilot', $pol['key'], 'Insider risk policy: '.$pol['template'], 'portal',
                $pol['purpose'],
                array_values(array_filter(['Requires: privacy approval recorded in the requirements'.($design['irm']['privacy_approved'] ? ' (done)' : ' (NOT yet approved)').'.',
                    $pol['key'] === 'irm-departing' ? ($design['irm']['hr_connector'] ? 'Set up the HR data connector first (Settings > Data connectors).' : 'Without an HR connector, the policy uses account deletion in Entra ID as the trigger.') : null,
                    $pol['key'] === 'irm-leaks' ? 'Choose the high-severity rules of the TG DLP policies as the triggering event.' : null,
                    'Insider risk management > Policies > Create policy > template "'.$pol['template'].'". Name: '.$pol['name'].'.',
                    'Scope: start with the pilot group or a priority user group.'])), $p['irm'], null, 30, 'Insider risk administrator');
        }
        $add('pilot', 'pilot_feedback', 'Collect pilot feedback and tune', 'manual',
            'Real users find the friction: unclear label names, noisy DLP tips, blocked business processes.',
            ['Short survey after 2 weeks: which label was confusing, which tip was wrong.', 'Review DLP overrides and false-positive reports.', 'Adjust label tooltips and DLP thresholds; record changes.'], $p['activity_explorer'], null, $pilotEnd - 3, $owner);
        $add('pilot', 'gate_pilot', 'Pilot exit approved', 'gate',
            'Production only starts with evidence: acceptable false positives, no blocked critical process, helpdesk ready.',
            ['Confirm false positives are acceptable and tuning is done.', 'Confirm the helpdesk and alert triage are ready.', 'Sign off the pilot gate.'], null, 'gate_pilot', $pilotEnd, $owner);

        // ------------------------------------------------------------------ Production
        $add('production', 'labels_publish_all', 'Publish labels to all users'.($design['label_policy']['mandatory'] ? ' (mandatory labelling)' : ''), 'script',
            'Everyone gets the labels with the default label'.($design['label_policy']['mandatory'] ? ', mandatory labelling' : '').' and downgrade justification.',
            ['Send the announcement email to all staff first.', 'Run the Production script: label policy for all users, then remove the pilot policy.'], $p['label_policies'], 'label_policy_all', $pilotEnd + 3);
        if ($dlp) {
            $add('production', 'dlp_enforce', 'Enforce DLP for everyone', 'script',
                'Switches the tuned policies from simulation to enforcement and removes the pilot copies.',
                ['Run the Production script: DLP policies set to "Enable".', 'Watch the alerts dashboard closely for the first week.'], $p['dlp'], 'dlp_enforce', $pilotEnd + 7);
        }
        if ($auto) {
            $add('production', 'autolabel_enable', 'Turn on service-side auto-labelling', 'script',
                'Labels existing and new content at rest in SharePoint, OneDrive and Exchange.',
                ['Only after the simulation was reviewed.', 'Run the Production script: auto-labelling policies set to "Enable".'], $p['autolabel'], 'autolabel_enable', $pilotEnd + 10);
        }
        if ($design['retention']['baseline']['include'] || $design['retention']['teams']['include']) {
            $add('production', 'retention_baseline', 'Apply the baseline retention policies', 'script',
                'Protects against accidental or malicious deletion across email, files'.($design['retention']['teams']['include'] ? ' and Teams' : '').'.',
                ['Run the Production script: baseline retention policies for all locations.', 'Retention policies take up to 7 days to apply everywhere.'], $p['retention'], 'retention_baseline', $pilotEnd + 7);
        }
        if ($retLabels) {
            $add('production', 'retention_publish_all', 'Publish retention labels to all users', 'script',
                'Everyone can apply record labels to the content they own.',
                ['Run the Production script: retention label policy for all users.'], $p['retention'], 'retention_publish_all', $pilotEnd + 10);
        }
        if ($containers) {
            $add('production', 'container_apply', 'Apply labels to high-risk Teams and sites', 'manual',
                'Container labels enforce privacy, guest access and external sharing on the sites found in Content explorer.',
                ['Label HR, finance, legal and board sites/Teams as Highly Confidential.', 'Tell site owners before changing guest access.'], null, null, $pilotEnd + 14, 'Purview administrator');
        }
        $add('production', 'training', 'User training and quick reference', 'manual',
            'A 15-minute session and a one-page guide cut wrong labels and helpdesk calls dramatically.',
            ['Use the generated quick-reference guide (Documents).', 'Record attendance for compliance evidence.'], null, null, $pilotEnd + 5, $owner);
        $add('production', 'gate_production', 'Production handover approved', 'gate',
            'Formal handover to operations with the documented design, roles and review cadence.',
            ['Confirm all production tasks are done or accepted.', 'Sign off the production gate.'], null, 'gate_production', $pilotEnd + 21, $owner);

        // ------------------------------------------------------------------ Operate (recurring)
        $add('operate', 'op_alerts', 'Weekly: triage DLP'.($irm ? ' and insider risk' : '').' alerts', 'manual',
            'Untriaged alerts are a compliance finding in themselves.', ['Data loss prevention > Alerts: resolve or escalate each high-severity alert.', 'Record false positives for tuning.'], $p['dlp_alerts'], null, $pilotEnd + 28, 'DLP triage owner', false);
        $add('operate', 'op_adoption', 'Monthly: label adoption and override review', 'portal',
            'Shows whether people label correctly and whether DLP overrides hide real risk.', ['Activity explorer: labels applied, changed, removed.', 'DLP: top overrides and justifications.'], $p['activity_explorer'], null, $pilotEnd + 45, 'Purview administrator', false);
        $add('operate', 'op_access', 'Quarterly: review Purview role assignments', 'portal',
            'Investigation roles give access to content; keep them minimal.', ['Role groups: remove people who changed jobs.'], $p['roles'], null, $pilotEnd + 100, $owner, false);
        $add('operate', 'op_policy_review', 'Annually: review the design against requirements and law', 'manual',
            'Regulations, business and data change. The design must follow.', ['Re-run the requirements in TenantsGuard and compare.', 'Confirm retention periods with legal counsel.'], null, null, $pilotEnd + 365, $owner, false);

        return $t;
    }
}
