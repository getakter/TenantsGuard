<?php

namespace App\Services\Purview;

use App\Models\ActivityLog;
use App\Models\PurviewProject;
use App\Models\PurviewTask;
use App\Models\User;
use App\Services\Graph\GraphException;
use App\Services\Scanner;
use App\Support\TenantLock;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

class PurviewService
{
    /** Licence capabilities from the tenant's service plans: true / false, or null when unreadable. */
    public function readiness(PurviewProject $project): array
    {
        $g = Scanner::context($project->tenant)->graph;
        try {
            $plans = collect($g->getAll('/subscribedSkus'))
                ->filter(fn ($s) => ($s['capabilityStatus'] ?? '') === 'Enabled')
                ->flatMap(fn ($s) => collect($s['servicePlans'] ?? [])->filter(fn ($p) => in_array($p['provisioningStatus'] ?? 'Success', ['Success', 'PendingActivation', 'PendingProvisioning'], true))->pluck('servicePlanName'))
                ->unique()->values()->all();
            $caps = [];
            foreach (Catalog::CAPABILITIES as $cap => [, $any]) {
                $caps[$cap] = (bool) array_intersect($any, $plans);
            }
        } catch (GraphException) {
            $caps = array_fill_keys(array_keys(Catalog::CAPABILITIES), null);
        }
        $project->update(['capabilities' => $caps]);

        return $caps;
    }

    /** Existing labels read through Graph (for the overview and verification). */
    public function existing(PurviewProject $project): array
    {
        $g = Scanner::context($project->tenant)->graph;
        $out = ['sensitivity' => null, 'retention' => null];
        try {
            $out['sensitivity'] = array_column($g->getAll('/beta/security/informationProtection/sensitivityLabels'), 'name');
        } catch (Throwable) {
        }
        try {
            $out['retention'] = array_column($g->getAll('/security/labels/retentionLabels'), 'displayName');
        } catch (Throwable) {
        }

        return $out;
    }

    /** (Re)generate the design from requirements. Clears approval: a changed design needs a new approval. */
    public function generate(PurviewProject $project, User $by): void
    {
        $caps = $project->capabilities ?? $this->readiness($project);
        $design = Designer::design($project->requirements, $caps, $project->tenant->name);
        $this->saveDesign($project, $design, $by, 'Design generated from requirements');
    }

    public function saveDesign(PurviewProject $project, array $design, User $by, string $why): void
    {
        DB::transaction(function () use ($project, $design) {
            $wasApproved = $project->isApproved();
            $project->design = $design;
            if ($wasApproved && PurviewProject::hash($design) !== $project->design_hash) {
                $project->design_approved_at = null;
                $project->design_approved_by = null;
                $project->signoffs = array_values(array_filter($project->signoffs ?? [], fn ($s) => $s['gate'] !== 'design'));
            }
            if ($project->stage === 'requirements') {
                $project->stage = 'design';
            }
            $project->save();
            $this->syncTasks($project);
        });
        ActivityLog::record('purview.design', $project->tenant, 'info', "{$why} for {$project->name} by {$by->email}");
    }

    /** Create or update tasks from the plan, keeping status, owner and evidence of existing tasks. */
    public function syncTasks(PurviewProject $project): void
    {
        $start = Carbon::parse($project->requirements['start_date'] ?? now()->toDateString());
        $plan = Planner::tasks($project->design, $project->requirements);
        $keys = [];
        foreach ($plan as $i => $t) {
            $keys[] = $t['key'];
            PurviewTask::updateOrCreate(['purview_project_id' => $project->id, 'key' => $t['key']], [
                'phase' => $t['phase'], 'position' => $i, 'title' => $t['title'], 'method' => $t['method'], 'why' => $t['why'], 'steps' => $t['steps'],
                'link' => $t['link'], 'verify' => $t['verify'], 'who' => $t['who'], 'required' => $t['required'],
                'due_date' => $start->copy()->addDays($t['due'])->toDateString(), 'stale' => false,
            ]);
        }
        PurviewTask::where('purview_project_id', $project->id)->whereNotIn('key', $keys)->update(['stale' => true]);
        $this->refreshGateTasks($project->fresh());
    }

    public function approveDesign(PurviewProject $project, User $by, ?string $note): void
    {
        if (! $project->design) {
            throw new RuntimeException('Generate the design first.');
        }
        if (blank($project->requirements['owner_name'] ?? null)) {
            throw new RuntimeException('Name the compliance owner in the requirements before approving.');
        }
        $hash = PurviewProject::hash($project->design);
        $project->update([
            'design_hash' => $hash, 'design_approved_at' => now(), 'design_approved_by' => $by->id,
            'signoffs' => array_values(array_merge(array_filter($project->signoffs ?? [], fn ($s) => $s['gate'] !== 'design'),
                [['gate' => 'design', 'by' => $by->id, 'name' => $by->name, 'at' => now()->toIso8601String(), 'note' => $note, 'hash' => $hash]])),
            'stage' => $project->stageIndex() < 2 ? 'foundations' : $project->stage,
        ]);
        $this->refreshGateTasks($project->fresh());
        ActivityLog::record('purview.approve', $project->tenant, 'success', "Purview design approved by {$by->email} (hash ".substr($hash, 0, 12).')'.($note ? ": {$note}" : ''));
    }

    /** Sign off a gate. Every required task of the phase must be done or marked not needed. */
    public function signoff(PurviewProject $project, string $gate, User $by, ?string $note): void
    {
        abort_unless(isset(PurviewProject::GATES[$gate]) && $gate !== 'design', 404);
        $phase = ['discover' => 'discover', 'pilot' => 'pilot', 'production' => 'production'][$gate];
        if (! $project->unlocked($phase)) {
            throw new RuntimeException('Earlier gates must be signed first.');
        }
        $open = $project->tasks()->where('phase', $phase)->where('stale', false)->where('required', true)->where('method', '!=', 'gate')
            ->whereNotIn('status', ['done', 'skipped'])->pluck('title');
        if ($open->isNotEmpty()) {
            throw new RuntimeException('Finish or mark as not needed: '.$open->implode('; ').'.');
        }
        $project->update([
            'signoffs' => array_values(array_merge(array_filter($project->signoffs ?? [], fn ($s) => $s['gate'] !== $gate),
                [['gate' => $gate, 'by' => $by->id, 'name' => $by->name, 'at' => now()->toIso8601String(), 'note' => $note]])),
            'stage' => PurviewProject::GATES[$gate][1],
        ]);
        $this->refreshGateTasks($project->fresh());
        ActivityLog::record('purview.signoff', $project->tenant, 'success', 'Purview gate "'.PurviewProject::GATES[$gate][0]."\" signed off by {$by->email}".($note ? ": {$note}" : ''));
    }

    private function refreshGateTasks(PurviewProject $project): void
    {
        $map = ['gate_design' => 'design', 'gate_discover' => 'discover', 'gate_pilot' => 'pilot', 'gate_production' => 'production'];
        foreach ($map as $key => $gate) {
            $s = $gate === 'design' ? ($project->isApproved() ? $project->signoff('design') : null) : $project->signoff($gate);
            PurviewTask::where('purview_project_id', $project->id)->where('key', $key)->update($s
                ? ['status' => 'done', 'completed_by' => $s['by'], 'completed_at' => Carbon::parse($s['at']), 'note' => $s['note'] ?? null, 'verify_status' => 'ok', 'verify_detail' => 'Signed by '.$s['name']]
                : ['status' => 'todo', 'completed_by' => null, 'completed_at' => null, 'verify_status' => null, 'verify_detail' => null]);
        }
    }

    // ------------------------------------------------------------------ automation through Microsoft Graph

    public function runAutomation(PurviewTask $task, User $by): string
    {
        $project = $task->project;

        return TenantLock::run($project->tenant, function () use ($task, $project, $by) {
            $message = match ($task->key) {
                'licences' => $this->automateLicences($project),
                'pilot_group' => $this->automatePilotGroup($project),
                'retention_labels' => $this->automateRetentionLabels($project),
                default => throw new RuntimeException('This task is not automated.'),
            };
            $task->update(['status' => 'done', 'completed_by' => $by->id, 'completed_at' => now(), 'note' => $message, 'verify_status' => 'ok', 'verify_detail' => $message]);
            ActivityLog::record('purview.automation', $project->tenant, 'success', "Purview: {$task->title}. {$message}");

            return $message;
        });
    }

    private function automateLicences(PurviewProject $project): string
    {
        $caps = $this->readiness($project);
        $gaps = Designer::gaps($project->requirements, $caps);

        return in_array(null, $caps, true) ? 'Licences could not be read (Organization.Read.All); confirm manually.'
            : ($gaps ? 'Gaps: '.implode('; ', array_map(fn ($g) => $g['label'].' needs '.$g['needs'], $gaps)).'. The design already accounts for them.' : 'All planned capabilities are licensed.');
    }

    private function automatePilotGroup(PurviewProject $project): string
    {
        $g = Scanner::context($project->tenant)->graph;
        $p = $project->design['pilot'];
        $existing = collect($g->getAll("/groups?\$filter=mailNickname eq '{$p['mail_nickname']}'"))->firstWhere('mailNickname', $p['mail_nickname'])
            ?? collect($g->getAll('/groups'))->firstWhere('displayName', $p['group_name']);
        $group = $existing ?? $g->post('/groups', [
            'displayName' => $p['group_name'], 'mailNickname' => $p['mail_nickname'], 'description' => 'Microsoft Purview pilot members. Managed by TenantsGuard.',
            'groupTypes' => ['Unified'], 'mailEnabled' => true, 'securityEnabled' => true, 'visibility' => 'Private',
        ]);
        $added = 0;
        foreach ($p['members'] as $uid) {
            try {
                $g->post('/groups/'.$group['id'].'/members/$ref', ['@odata.id' => 'https://graph.microsoft.com/v1.0/directoryObjects/'.$uid]);
                $added++;
            } catch (GraphException $e) {
                if (! str_contains(strtolower($e->getMessage()), 'already exist')) {
                    throw $e;
                }
            }
        }

        return ($existing ? 'Group already existed' : 'Group created').", {$added} member(s) added. Mail: {$p['mail_nickname']}@{$project->tenant->domain}.";
    }

    private function automateRetentionLabels(PurviewProject $project): string
    {
        $g = Scanner::context($project->tenant)->graph;
        $have = array_map('strtolower', array_column($g->getAll('/security/labels/retentionLabels'), 'displayName'));
        $created = [];
        foreach (collect($project->design['retention']['labels'])->where('include', true) as $l) {
            if (in_array(strtolower($l['name']), $have, true)) {
                continue;
            }
            $g->post('/security/labels/retentionLabels', [
                'displayName' => $l['name'],
                'behaviorDuringRetentionPeriod' => $l['record'] ? 'retainAsRecord' : 'retain',
                'actionAfterRetentionPeriod' => $l['action'],
                'retentionTrigger' => $l['trigger'],
                'retentionDuration' => ['@odata.type' => '#microsoft.graph.security.retentionDurationInDays', 'days' => $l['years'] * 365],
                'defaultRecordBehavior' => $l['record'] ? 'startLocked' : 'startUnlocked',
                'descriptionForUsers' => $l['description'],
                'descriptionForAdmins' => 'Created by TenantsGuard from the approved retention schedule.',
            ]);
            $created[] = $l['name'];
        }

        return $created ? 'Created: '.implode(', ', $created).'.' : 'All retention labels already existed.';
    }

    // ------------------------------------------------------------------ verification

    /** Validate and store the export produced by the verification script, then verify tasks. */
    public function importVerification(PurviewProject $project, string $json, User $by): array
    {
        $data = json_decode(preg_replace('/^\xEF\xBB\xBF/', '', $json), true);
        if (! is_array($data) || ($data['kind'] ?? null) !== 'tenantsguard-purview-verification') {
            throw new RuntimeException('This is not a TenantsGuard verification export. Run the verification script and upload tenantsguard-purview-verification.json.');
        }
        if (! empty($data['tenantId']) && $project->tenant->ms_tenant_id && strcasecmp($data['tenantId'], $project->tenant->ms_tenant_id) !== 0) {
            ActivityLog::record('purview.verify', $project->tenant, 'failure', "Verification export from another tenant ({$data['tenantId']}) was rejected for {$project->name}");
            throw new RuntimeException('The export is from a different tenant ('.$data['tenantId'].'). Nothing was imported.');
        }
        $names = fn ($k) => array_values(array_filter(array_map(fn ($x) => is_array($x) ? (string) ($x['Name'] ?? '') : '', (array) ($data[$k] ?? []))));
        $clean = [
            'generatedAt' => $data['generatedAt'] ?? null,
            'labels' => $names('labels'),
            'labelPolicies' => array_map(fn ($x) => ['name' => (string) ($x['Name'] ?? ''), 'scope' => array_map('strval', (array) ($x['Scope'] ?? []))], (array) ($data['labelPolicies'] ?? [])),
            'dlpPolicies' => array_map(fn ($x) => ['name' => (string) ($x['Name'] ?? ''), 'mode' => (string) ($x['Mode'] ?? '')], (array) ($data['dlpPolicies'] ?? [])),
            'autoLabelPolicies' => array_map(fn ($x) => ['name' => (string) ($x['Name'] ?? ''), 'mode' => (string) ($x['Mode'] ?? '')], (array) ($data['autoLabelPolicies'] ?? [])),
            'retentionPolicies' => $names('retentionPolicies'),
            'retentionLabels' => $names('retentionLabels'),
            'labelCoauth' => (bool) ($data['labelCoauth'] ?? false),
            'auditEnabled' => (bool) ($data['auditEnabled'] ?? false),
        ];
        $project->update(['verification' => $clean, 'verified_at' => now()]);
        $results = $this->verify($project->fresh());
        ActivityLog::record('purview.verify', $project->tenant, 'info', "Purview verification imported by {$by->email}: ".collect($results)->where('status', 'ok')->count().' of '.count($results).' checks in place.');

        return $results;
    }

    /** Compare the tenant with the design. Uses the uploaded export plus Graph where possible. */
    public function verify(PurviewProject $project): array
    {
        $d = $project->design;
        $v = $project->verification;
        $graph = $this->existing($project);
        $lower = fn (array $a) => array_map('strtolower', $a);
        $check = function (array $expected, array $have) use ($lower): array {
            $missing = array_values(array_diff($lower($expected), $lower($have)));

            return [$missing ? (count($missing) === count($expected) ? 'missing' : 'partial') : 'ok', $missing];
        };
        $dlpNames = collect($d['dlp'])->where('include', true)->pluck('name')->all();
        $modes = collect($v['dlpPolicies'] ?? [])->pluck('mode', 'name')->mapWithKeys(fn ($m, $n) => [strtolower($n) => $m]);
        $autoModes = collect($v['autoLabelPolicies'] ?? [])->pluck('mode', 'name')->mapWithKeys(fn ($m, $n) => [strtolower($n) => $m]);
        $labelKeys = collect($d['labels'])->where('include', true)->pluck('key')->all();
        $retLabels = collect($d['retention']['labels'])->where('include', true)->pluck('name')->all();
        $policyNames = collect($v['labelPolicies'] ?? [])->pluck('name')->all();

        $modeCheck = function (array $names, $modes, array $accepted) {
            $bad = array_values(array_filter($names, fn ($n) => ! in_array($modes[strtolower($n)] ?? null, $accepted, true)));

            return [$bad ? (count($bad) === count($names) ? 'missing' : 'partial') : 'ok', $bad];
        };

        $rules = [
            'audit' => fn () => $v ? [$v['auditEnabled'] ? 'ok' : 'missing', []] : null,
            'coauth' => fn () => $v ? [$v['labelCoauth'] ? 'ok' : 'missing', []] : null,
            'labels' => fn () => $v ? $check($labelKeys, $v['labels'])
                : ($graph['sensitivity'] !== null ? $check(collect($d['labels'])->where('include', true)->where('parent', null)->pluck('display')->all(), $graph['sensitivity']) : null),
            'label_policy_pilot' => fn () => $v ? $check([Designer::PREFIX.'Sensitivity labels (pilot)'], $policyNames) : null,
            'label_policy_all' => fn () => $v ? $check([Designer::PREFIX.'Sensitivity labels (all users)'], $policyNames) : null,
            'dlp_simulation' => fn () => $v ? $modeCheck($dlpNames, $modes, ['TestWithoutNotifications', 'TestWithNotifications', 'Enable']) : null,
            'dlp_pilot' => fn () => $v ? $modeCheck(array_map(fn ($n) => $n.' (pilot)', $dlpNames), $modes, ['TestWithNotifications', 'Enable']) : null,
            'dlp_enforce' => fn () => $v ? $modeCheck($dlpNames, $modes, ['Enable']) : null,
            'autolabel_simulation' => fn () => $v ? $modeCheck(collect($d['autolabel'])->where('include', true)->pluck('name')->all(), $autoModes, ['TestWithoutNotifications', 'TestWithNotifications', 'Enable']) : null,
            'autolabel_enable' => fn () => $v ? $modeCheck(collect($d['autolabel'])->where('include', true)->pluck('name')->all(), $autoModes, ['Enable']) : null,
            'retention_labels' => fn () => $graph['retention'] !== null ? $check($retLabels, $graph['retention']) : ($v ? $check($retLabels, $v['retentionLabels']) : null),
            'retention_publish_pilot' => fn () => $v ? $check([Designer::PREFIX.'Retention labels (pilot)'], $v['retentionPolicies']) : null,
            'retention_publish_all' => fn () => $v ? $check([Designer::PREFIX.'Retention labels (all users)'], $v['retentionPolicies']) : null,
            'retention_baseline' => fn () => $v ? $check(array_values(array_filter([$d['retention']['baseline']['include'] ? Designer::PREFIX.'Baseline retention' : null, $d['retention']['teams']['include'] ? Designer::PREFIX.'Teams retention' : null])), $v['retentionPolicies']) : null,
            'pilot_group' => function () use ($project) {
                try {
                    $nick = $project->design['pilot']['mail_nickname'];
                    $found = collect(Scanner::context($project->tenant)->graph->getAll("/groups?\$filter=mailNickname eq '{$nick}'"))->firstWhere('mailNickname', $nick);

                    return [$found ? 'ok' : 'missing', []];
                } catch (Throwable) {
                    return null;
                }
            },
        ];

        $results = [];
        foreach ($project->tasks()->where('stale', false)->whereNotNull('verify')->get() as $task) {
            if (! isset($rules[$task->verify])) {
                continue;
            }
            $r = $rules[$task->verify]();
            if ($r === null) {
                continue;
            }
            [$status, $missing] = $r;
            $detail = $status === 'ok' ? 'In place in the tenant.' : 'Missing or not in the expected mode: '.($missing ? implode(', ', array_slice($missing, 0, 8)) : 'setting is off').'.';
            $task->update(['verify_status' => $status, 'verify_detail' => mb_substr($detail, 0, 1000)]);
            if ($status === 'ok' && in_array($task->status, ['todo', 'in_progress'], true)) {
                $task->update(['status' => 'done', 'completed_at' => now(), 'note' => trim(($task->note ? $task->note."\n" : '').'Verified automatically.')]);
            }
            $results[] = ['task' => $task->title, 'status' => $status, 'detail' => $detail];
        }

        return $results;
    }
}
