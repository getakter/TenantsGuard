<?php

namespace App\Services\Copilot;

use App\Models\CopilotProject;
use App\Models\PurviewProject;
use App\Services\Scanner;
use Throwable;

/**
 * Live Microsoft 365 Copilot readiness assessment through Microsoft Graph.
 * Each check: area, title, status (pass | warn | fail | info | unknown), detail, fix, weight.
 */
final class Assessor
{
    public const AREAS = ['Licensing', 'Users', 'Identity and security', 'Data and oversharing', 'People'];

    public static function run(CopilotProject $project): array
    {
        $tenant = $project->tenant;
        $req = $project->requirements;
        $g = Scanner::context($tenant)->graph;
        $try = function (callable $fn) {
            try {
                return $fn();
            } catch (Throwable) {
                return null;
            }
        };

        $skus = $try(fn () => $g->getAll('/subscribedSkus'));
        $users = $try(fn () => $g->getAll('/users?$select=id,displayName,userPrincipalName,accountEnabled,userType,assignedLicenses,usageLocation,mail,jobTitle,department&$top=999'));
        $groups = $try(fn () => $g->getAll('/groups?$select=id,displayName,groupTypes,visibility,resourceProvisioningOptions&$top=999'));
        $spo = $try(fn () => $g->get('/admin/sharepoint/settings'));
        $labels = $try(fn () => $g->getAll('/beta/security/informationProtection/sensitivityLabels'));
        $registration = $try(fn () => Scanner::context($tenant)->registration());

        // ---- licences
        $skuInfo = collect($skus ?? [])->filter(fn ($s) => ($s['capabilityStatus'] ?? 'Enabled') === 'Enabled')->keyBy('skuId')->map(fn ($s) => [
            'part' => $s['skuPartNumber'], 'total' => (int) ($s['prepaidUnits']['enabled'] ?? 0), 'used' => (int) ($s['consumedUnits'] ?? 0),
            'plans' => array_column($s['servicePlans'] ?? [], 'servicePlanName'),
        ]);
        $copilotSkus = $skuInfo->filter(fn ($s) => Catalog::isCopilotSku($s['part']) || array_intersect($s['plans'], Catalog::PLANS['copilot']));
        $copilot = ['total' => $copilotSkus->sum('total'), 'used' => $copilotSkus->sum('used'), 'skuIds' => $copilotSkus->keys()->values()->all()];
        $copilot['free'] = max(0, $copilot['total'] - $copilot['used']);

        // ---- per-user readiness
        $pilot = array_flip($project->pilotUsers());
        $rows = [];
        foreach (collect($users ?? [])->filter(fn ($u) => strtolower($u['userType'] ?? 'Member') === 'member' && ($u['accountEnabled'] ?? true)) as $u) {
            $ids = array_column($u['assignedLicenses'] ?? [], 'skuId');
            $parts = array_map(fn ($id) => $skuInfo[$id]['part'] ?? '', $ids);
            $plans = collect($ids)->flatMap(fn ($id) => $skuInfo[$id]['plans'] ?? [])->unique()->all();
            $svc = [];
            foreach (['mailbox', 'onedrive', 'teams', 'apps'] as $need) {
                $svc[$need] = (bool) array_intersect(Catalog::PLANS[$need], $plans);
            }
            $rows[$u['id']] = [
                'id' => $u['id'], 'name' => $u['displayName'], 'upn' => $u['userPrincipalName'], 'department' => $u['department'] ?? null,
                'eligible' => (bool) array_intersect($parts, Catalog::ELIGIBLE_BASE), 'services' => $svc, 'usage_location' => $u['usageLocation'] ?? null,
                'copilot' => (bool) array_intersect($ids, $copilot['skuIds']), 'pilot' => isset($pilot[$u['id']]),
            ];
        }
        $members = count($rows);
        $eligible = count(array_filter($rows, fn ($r) => $r['eligible']));
        $pilotRows = array_filter($rows, fn ($r) => $r['pilot']);
        $pilotGaps = [];
        foreach ($pilotRows as $r) {
            $missing = array_keys(array_filter($r['services'], fn ($v) => ! $v));
            if (! $r['eligible']) {
                array_unshift($missing, 'eligible base licence');
            }
            if ($missing) {
                $pilotGaps[] = $r['name'].': '.implode(', ', $missing);
            }
        }
        $noLocation = array_filter($rows, fn ($r) => ! $r['usage_location']);

        // ---- security posture
        $scan = $tenant->scans()->first();
        $results = $scan ? $scan->results()->whereIn('check_id', ['ID-01', 'ID-02', 'ID-08'])->pluck('status', 'check_id') : collect();
        $mfaEnforced = ($results['ID-02'] ?? null) === 'pass' || ($results['ID-01'] ?? null) === 'pass';
        $regMembers = collect($registration ?? [])->filter(fn ($r) => strtolower($r['userType'] ?? 'member') === 'member');
        $mfaPct = $regMembers->count() ? (int) round($regMembers->where('isMfaRegistered', true)->count() / $regMembers->count() * 100) : null;
        $critical = $scan ? ($scan->counts['fail_critical'] ?? null) : null;

        // ---- data exposure
        $public = collect($groups ?? [])->filter(fn ($x) => in_array('Unified', $x['groupTypes'] ?? [], true) && strcasecmp($x['visibility'] ?? '', 'Public') === 0)
            ->map(fn ($x) => ['id' => $x['id'], 'name' => $x['displayName'], 'team' => in_array('Team', $x['resourceProvisioningOptions'] ?? [], true)])->values()->all();
        $guests = collect($users ?? [])->filter(fn ($u) => strtolower($u['userType'] ?? '') === 'guest')->count();
        $sharing = $spo['sharingCapability'] ?? null;
        $purview = PurviewProject::where('tenant_id', $tenant->id)->first();
        $usage = $project->usage;
        $concealed = $usage ? collect($usage['rows'] ?? [])->contains(fn ($r) => ! str_contains($r['upn'] ?? '', '@')) : null;

        $c = [];
        $add = function (string $key, string $area, string $title, string $status, string $detail, string $fix = '', int $weight = 2) use (&$c) {
            $c[$key] = compact('area', 'title', 'status', 'detail', 'fix', 'weight');
        };

        $add('licences_copilot', 'Licensing', 'Microsoft 365 Copilot licences', $skus === null ? 'unknown' : ($copilot['total'] ? 'pass' : 'fail'),
            $skus === null ? 'Licences could not be read.' : ($copilot['total'] ? "{$copilot['total']} seats, {$copilot['used']} assigned, {$copilot['free']} free." : 'No Copilot licences in the tenant.'),
            'Buy Microsoft 365 Copilot seats (planned: '.($req['seats_planned'] ?? '?').') through your licensing partner.', 3);
        $add('licences_base', 'Licensing', 'Eligible base licences', $users === null ? 'unknown' : ($members && $eligible === $members ? 'pass' : ($eligible ? 'warn' : 'fail')),
            $users === null ? 'Users could not be read.' : "{$eligible} of {$members} enabled members have a base licence that qualifies for Copilot (Business Basic/Standard/Premium, E1/E3/E5, F1/F3).",
            'Copilot is an add-on: users without an eligible base licence cannot be given Copilot.', 3);
        $add('pilot_services', 'Users', 'Pilot users have mailbox, OneDrive, Teams and Office apps', ! $pilotRows ? 'warn' : ($pilotGaps ? 'fail' : 'pass'),
            ! $pilotRows ? 'No pilot users chosen yet.' : ($pilotGaps ? 'Gaps: '.implode('; ', array_slice($pilotGaps, 0, 8)).'.' : count($pilotRows).' pilot users are fully provisioned.'),
            'Copilot grounds answers in Exchange Online mail, OneDrive files and Teams chats. Users without them get poor results. Desktop Office apps are needed for Copilot in Word, Excel and PowerPoint desktop.', 3);
        $add('usage_location', 'Users', 'Usage location set', $users === null ? 'unknown' : ($noLocation ? 'warn' : 'pass'),
            $noLocation ? count($noLocation).' member(s) have no usage location; licences cannot be assigned to them.' : 'All members have a usage location.', 'Set the usage location on each user (Entra ID or the onboarding form).', 1);
        $add('mfa', 'Identity and security', 'MFA enforced and registered', ! $scan ? 'unknown' : ($mfaEnforced && ($mfaPct ?? 0) >= 95 ? 'pass' : ($mfaEnforced ? 'warn' : 'fail')),
            ($scan ? ($mfaEnforced ? 'MFA is enforced by policy.' : 'MFA is NOT enforced for all users.') : 'No posture scan yet.').($mfaPct !== null ? " {$mfaPct}% of members registered MFA." : ''),
            'A compromised account with Copilot can summarise everything that user can reach. Enforce MFA (Conditional Access or security defaults) first.', 3);
        $add('posture', 'Identity and security', 'TenantsGuard security posture', ! $scan ? 'unknown' : ($scan->score >= 70 ? 'pass' : ($scan->score >= 40 ? 'warn' : 'fail')),
            $scan ? "Posture score {$scan->score}%".($critical !== null ? ", {$critical} critical finding(s)." : '.').($tenant->latestSecureScore?->ok() ? ' Secure Score '.round($tenant->latestSecureScore->percent).'%.' : '') : 'Run a scan.',
            'Fix critical identity findings in Security posture before the pilot.', 2);
        $add('sharing', 'Data and oversharing', 'SharePoint and OneDrive sharing level', $spo === null ? 'unknown' : ($sharing === 'externalUserAndGuestSharing' ? 'warn' : 'pass'),
            $spo === null ? 'Sharing settings could not be read.' : 'Organisation sharing level: '.match ($sharing) {
                'externalUserAndGuestSharing' => 'anyone links allowed', 'externalUserSharingOnly' => 'new and existing guests', 'existingExternalUserSharingOnly' => 'existing guests only', 'disabled' => 'internal only', default => (string) $sharing
            }.'.',
            'Anyone links spread files beyond their intended audience; Copilot then surfaces them to everyone who can open them. Prefer "new and existing guests" and internal default links.', 2);
        $add('public_groups', 'Data and oversharing', 'Public Teams and Microsoft 365 groups', $groups === null ? 'unknown' : (! $public ? 'pass' : (count($public) > 5 ? 'fail' : 'warn')),
            $groups === null ? 'Groups could not be read.' : (count($public) ? count($public).' public: '.implode(', ', array_slice(array_column($public, 'name'), 0, 8)).'. Everyone in the organisation can read their files, so Copilot can surface them to anyone.' : 'No public groups.'),
            'Make groups private unless they really are for everyone. Use the public groups tool on the Implement tab.', 3);
        $add('guests', 'Data and oversharing', 'Guest accounts', $users === null ? 'unknown' : 'info', "{$guests} guest account(s). Guests without Copilot cannot use it, but content they shared into is part of what members' Copilot sees.", '', 0);
        $add('labels', 'Data and oversharing', 'Sensitivity labels', $labels === null ? 'unknown' : (count($labels) ? 'pass' : 'warn'),
            $labels === null ? 'Labels could not be read (InformationProtectionPolicy.Read.All).' : (count($labels) ? count($labels).' sensitivity labels published.' : 'No sensitivity labels. Copilot respects labels and encryption; without them, sensitive files are protected only by permissions.'),
            'Run the Purview programme: labels, DLP for Microsoft 365 Copilot and retention of Copilot interactions.', 2);
        $add('purview', 'Data and oversharing', 'Purview data security programme', $purview ? ($purview->isApproved() ? 'pass' : 'warn') : 'info',
            $purview ? 'Purview programme at stage "'.($purview->stage).'"'.($purview->isApproved() ? ' with an approved design.' : ', design not approved yet.') : 'No Purview programme for this client yet.',
            'A Purview programme gives Copilot labels, DLP and retention to respect.', 1);
        if (in_array($req['data_approach'] ?? 'balanced', ['strict', 'balanced'], true)) {
            $sites = Catalog::lines($req['sensitive_sites'] ?? '');
            $add('sensitive_sites', 'Data and oversharing', 'Sensitive sites identified', $sites ? 'pass' : 'warn',
                $sites ? count($sites).' sensitive site(s) will be hidden with Restricted Content Discovery.' : 'No sensitive sites listed in the requirements.', 'List HR, finance, legal and board sites so they can be hidden from Copilot discovery.', 1);
        }
        if ($req['works_council'] ?? false) {
            $add('consultation', 'People', 'Works council / union / HR consultation', ($req['consulted'] ?? false) ? 'pass' : 'fail',
                ($req['consulted'] ?? false) ? 'Consultation recorded as completed.' : 'Consultation is required and not yet completed. The pilot cannot start.', 'Complete the consultation, then update the requirements.', 2);
        }
        $add('reports_privacy', 'People', 'Usage reports show user names', $concealed === null ? 'unknown' : ($concealed ? 'warn' : 'pass'),
            $concealed === null ? 'Pull usage once Copilot is assigned to check this.' : ($concealed ? 'User names are concealed in reports, so per-user adoption and licence reclaim are not possible.' : 'User-level usage is visible.'),
            'Microsoft 365 admin center > Settings > Org settings > Reports: turn off "Display concealed user, group and site names" (agree with HR/privacy first).', 1);

        $scored = array_filter($c, fn ($x) => $x['weight'] > 0 && in_array($x['status'], ['pass', 'warn', 'fail'], true));
        $total = array_sum(array_column($scored, 'weight'));
        $score = $total ? (int) round(array_sum(array_map(fn ($x) => $x['weight'] * ['pass' => 1, 'warn' => 0.5, 'fail' => 0][$x['status']], $scored)) / $total * 100) : null;

        return [
            'score' => $score, 'checks' => $c, 'copilot' => $copilot, 'members' => $members, 'eligible' => $eligible,
            'users' => array_values($rows), 'public_groups' => $public, 'guests' => $guests, 'read_at' => now()->toIso8601String(),
        ];
    }
}
