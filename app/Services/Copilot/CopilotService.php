<?php

namespace App\Services\Copilot;

use App\Models\ActivityLog;
use App\Models\CopilotProject;
use App\Models\CopilotTask;
use App\Models\User;
use App\Services\Graph\GraphException;
use App\Services\Scanner;
use App\Support\TenantLock;
use Carbon\Carbon;
use RuntimeException;

class CopilotService
{
    public function assess(CopilotProject $project, ?User $by = null): array
    {
        $a = Assessor::run($project);
        $project->update(['assessment' => $a, 'assessed_at' => now(), 'stage' => in_array($project->stage, ['requirements', 'assess'], true) ? 'prepare' : $project->stage]);
        $this->syncTasks($project->fresh());
        $this->verify($project->fresh());
        ActivityLog::record('copilot.assess', $project->tenant, 'info', 'Copilot readiness assessed'.($by ? " by {$by->email}" : '').': '.($a['score'] ?? '?').'%');

        return $a;
    }

    public function syncTasks(CopilotProject $project): void
    {
        $start = Carbon::parse($project->requirements['start_date'] ?? now()->toDateString());
        $keys = [];
        foreach (Planner::tasks($project->requirements, $project->tenant->name) as $i => $t) {
            $keys[] = $t['key'];
            CopilotTask::updateOrCreate(['copilot_project_id' => $project->id, 'key' => $t['key']], [
                'phase' => $t['phase'], 'position' => $i, 'title' => $t['title'], 'method' => $t['method'], 'why' => $t['why'], 'steps' => $t['steps'],
                'link' => $t['link'], 'verify' => $t['verify'], 'who' => $t['who'], 'required' => $t['required'],
                'due_date' => $start->copy()->addDays($t['due'])->toDateString(), 'stale' => false,
            ]);
        }
        CopilotTask::where('copilot_project_id', $project->id)->whereNotIn('key', $keys)->update(['stale' => true]);
        $this->refreshGates($project->fresh());
    }

    /** Map assessment checks and usage onto tasks. Passing checks complete open tasks automatically. */
    public function verify(CopilotProject $project): void
    {
        $checks = $project->assessment['checks'] ?? [];
        $map = ['pass' => 'ok', 'warn' => 'partial', 'fail' => 'missing'];
        foreach ($project->tasks()->where('stale', false)->whereNotNull('verify')->where('method', '!=', 'gate')->get() as $task) {
            [$status, $detail] = match ($task->verify) {
                'pilot_licences' => $this->pilotLicenceStatus($project),
                'pilot_usage' => $this->usageStatus($project),
                default => isset($checks[$task->verify]) && isset($map[$checks[$task->verify]['status']])
                    ? [$map[$checks[$task->verify]['status']], $checks[$task->verify]['detail']] : [null, null],
            };
            if (! $status) {
                continue;
            }
            $task->update(['verify_status' => $status, 'verify_detail' => mb_substr((string) $detail, 0, 1000)]);
            if ($status === 'ok' && in_array($task->status, ['todo', 'in_progress'], true) && $project->unlocked($task->phase)) {
                $task->update(['status' => 'done', 'completed_at' => now(), 'note' => trim(($task->note ? $task->note."\n" : '').'Verified automatically.')]);
            }
        }
    }

    private function pilotLicenceStatus(CopilotProject $project): array
    {
        $pilot = collect($project->assessment['users'] ?? [])->where('pilot', true);
        if ($pilot->isEmpty()) {
            return [null, null];
        }
        $done = $pilot->where('copilot', true)->count();

        return [$done === $pilot->count() ? 'ok' : ($done ? 'partial' : 'missing'), "{$done} of {$pilot->count()} pilot users have Copilot."];
    }

    private function usageStatus(CopilotProject $project): array
    {
        $u = $project->usage;
        if (! $u || ! $u['licensed']) {
            return [null, null];
        }
        $target = (int) ($project->requirements['target_active'] ?? 70);

        return [$u['active7_pct'] >= $target ? 'ok' : ($u['active7_pct'] >= $target / 2 ? 'partial' : 'missing'),
            "{$u['active7']} of {$u['licensed']} licensed users active in the last 7 days ({$u['active7_pct']}%, target {$target}%)."];
    }

    /** Pull the Copilot usage report (Reports.Read.All). */
    public function usage(CopilotProject $project): array
    {
        $g = Scanner::context($project->tenant)->graph;
        $rows = array_map(fn ($r) => [
            'upn' => $r['userPrincipalName'] ?? '', 'name' => $r['displayName'] ?? '', 'last' => $r['lastActivityDate'] ?? null,
            'apps' => array_values(array_filter([
                ! empty($r['microsoftTeamsCopilotLastActivityDate']) ? 'Teams' : null, ! empty($r['outlookCopilotLastActivityDate']) ? 'Outlook' : null,
                ! empty($r['wordCopilotLastActivityDate']) ? 'Word' : null, ! empty($r['excelCopilotLastActivityDate']) ? 'Excel' : null,
                ! empty($r['powerPointCopilotLastActivityDate']) ? 'PowerPoint' : null, ! empty($r['copilotChatLastActivityDate']) ? 'Chat' : null,
            ])),
        ], $g->getAll("/beta/reports/getMicrosoft365CopilotUsageUserDetail(period='D30')"));
        $since = fn (int $d) => count(array_filter($rows, fn ($r) => $r['last'] && Carbon::parse($r['last'])->gte(now()->subDays($d)->startOfDay())));
        $licensed = count($rows);
        $u = ['rows' => $rows, 'licensed' => $licensed, 'active7' => $since(7), 'active30' => $since(30),
            'active7_pct' => $licensed ? (int) round($since(7) / $licensed * 100) : 0,
            'inactive' => array_values(array_map(fn ($r) => $r['upn'], array_filter($rows, fn ($r) => ! $r['last'] || Carbon::parse($r['last'])->lt(now()->subDays(30)))))];
        $project->update(['usage' => $u, 'usage_at' => now()]);
        $this->verify($project->fresh());

        return $u;
    }

    public function signoff(CopilotProject $project, string $gate, User $by, ?string $note): void
    {
        $phase = ['readiness' => 'prepare', 'pilot' => 'pilot', 'rollout' => 'rollout'][$gate] ?? abort(404);
        if (! $project->unlocked($phase)) {
            throw new RuntimeException('Earlier gates must be signed first.');
        }
        $open = $project->tasks()->where('phase', $phase)->where('stale', false)->where('required', true)->where('method', '!=', 'gate')
            ->whereNotIn('status', ['done', 'skipped'])->pluck('title');
        if ($open->isNotEmpty()) {
            throw new RuntimeException('Finish or mark as not needed: '.$open->implode('; ').'.');
        }
        if ($gate === 'readiness' && ($project->requirements['works_council'] ?? false) && ! ($project->requirements['consulted'] ?? false)) {
            throw new RuntimeException('The required works council / HR consultation is not recorded as completed.');
        }
        $blockers = collect($project->assessment['checks'] ?? [])->where('status', 'fail')->pluck('title');
        if ($gate === 'readiness' && $blockers->isNotEmpty() && mb_strlen(trim((string) $note)) < 15) {
            throw new RuntimeException('Blockers remain: '.$blockers->implode('; ').'. Fix them and re-assess, or record why the risk is accepted in the sign-off note.');
        }
        $project->update([
            'signoffs' => array_values(array_merge(array_filter($project->signoffs ?? [], fn ($s) => $s['gate'] !== $gate),
                [['gate' => $gate, 'by' => $by->id, 'name' => $by->name, 'at' => now()->toIso8601String(), 'note' => $note, 'score' => $project->assessment['score'] ?? null]])),
            'stage' => CopilotProject::GATES[$gate][1],
        ]);
        $this->refreshGates($project->fresh());
        ActivityLog::record('copilot.signoff', $project->tenant, 'success', 'Copilot gate "'.CopilotProject::GATES[$gate][0]."\" signed off by {$by->email}".($note ? ": {$note}" : ''));
    }

    private function refreshGates(CopilotProject $project): void
    {
        foreach (['gate_readiness' => 'readiness', 'gate_pilot' => 'pilot', 'gate_rollout' => 'rollout'] as $key => $gate) {
            $s = $project->signoff($gate);
            CopilotTask::where('copilot_project_id', $project->id)->where('key', $key)->update($s
                ? ['status' => 'done', 'completed_by' => $s['by'], 'completed_at' => Carbon::parse($s['at']), 'note' => $s['note'] ?? null, 'verify_status' => 'ok', 'verify_detail' => 'Signed by '.$s['name']]
                : ['status' => 'todo', 'completed_by' => null, 'completed_at' => null, 'verify_status' => null, 'verify_detail' => null]);
        }
    }

    // ------------------------------------------------------------------ automation

    public function runAutomation(CopilotTask $task, User $by): string
    {
        $project = $task->project;
        $msg = match (true) {
            in_array($task->key, ['licences', 'user_services', 'usage_location', 'mfa', 'public_groups'], true) => (function () use ($project, $by) {
                $a = $this->assess($project, $by);

                return 'Assessment refreshed: readiness '.($a['score'] ?? '?').'%.';
            })(),
            $task->key === 'pilot_group' => $this->pilotGroup($project, $by),
            $task->key === 'pilot_licences' => (function () use ($project, $by) {
                $r = $this->assign($project, $project->pilotUsers(), $by);

                return $r['message'];
            })(),
            in_array($task->key, ['pilot_usage', 'usage_monthly'], true) => (function () use ($project) {
                $u = $this->usage($project);

                return "{$u['active7']} of {$u['licensed']} licensed users active in 7 days; ".count($u['inactive']).' inactive for 30 days.';
            })(),
            str_starts_with($task->key, 'wave_') => throw new RuntimeException('Use the Licence assignment tool on the Implement tab to pick this wave\'s users.'),
            default => throw new RuntimeException('This task is not automated.'),
        };
        $task = $task->fresh();
        if (! in_array($task->key, ['licences', 'user_services', 'usage_location', 'mfa', 'public_groups', 'pilot_usage', 'pilot_licences'], true)) {
            $task->update(['status' => 'done', 'completed_by' => $by->id, 'completed_at' => now(), 'note' => $msg, 'verify_status' => 'ok', 'verify_detail' => $msg]);
        }
        ActivityLog::record('copilot.automation', $project->tenant, 'success', "Copilot: {$task->title}. {$msg}");

        return $msg;
    }

    private function pilotGroup(CopilotProject $project, User $by): string
    {
        return TenantLock::run($project->tenant, function () use ($project) {
            $g = Scanner::context($project->tenant)->graph;
            $group = collect($g->getAll('/groups'))->firstWhere('mailNickname', 'copilotpilot')
                ?? $g->post('/groups', ['displayName' => 'Copilot Pilot', 'mailNickname' => 'copilotpilot', 'mailEnabled' => false, 'securityEnabled' => true,
                    'description' => 'Microsoft 365 Copilot pilot members. Managed by TenantsGuard.']);
            $added = 0;
            foreach ($project->pilotUsers() as $uid) {
                try {
                    $g->post('/groups/'.$group['id'].'/members/$ref', ['@odata.id' => 'https://graph.microsoft.com/v1.0/directoryObjects/'.$uid]);
                    $added++;
                } catch (GraphException $e) {
                    if (! str_contains(strtolower($e->getMessage()), 'already exist')) {
                        throw $e;
                    }
                }
            }

            return "Group \"Copilot Pilot\" ready, {$added} member(s) added.";
        });
    }

    /**
     * Assign Copilot licences. Guard rails: nothing before the readiness gate; only pilot members
     * until the pilot gate; eligible base licence and usage location required; never more than free seats.
     */
    public function assign(CopilotProject $project, array $userIds, User $by): array
    {
        if (! $project->signoff('readiness')) {
            throw new RuntimeException('Sign off "Ready for pilot" before assigning Copilot licences.');
        }
        $a = $this->assess($project);
        $users = collect($a['users'])->keyBy('id');
        $pilot = array_flip($project->pilotUsers());
        if (! $project->signoff('pilot') && array_diff($userIds, array_keys($pilot))) {
            throw new RuntimeException('Until the pilot exit is signed off, Copilot can only be assigned to pilot members.');
        }
        $skuId = $a['copilot']['skuIds'][0] ?? throw new RuntimeException('There are no Copilot licences in the tenant.');
        $todo = array_values(array_filter($userIds, fn ($id) => isset($users[$id]) && ! $users[$id]['copilot']));
        if (count($todo) > $a['copilot']['free']) {
            throw new RuntimeException('Only '.$a['copilot']['free'].' Copilot seat(s) are free for '.count($todo).' user(s). Buy more seats or select fewer users.');
        }

        return TenantLock::run($project->tenant, function () use ($project, $todo, $users, $skuId, $by) {
            $g = Scanner::context($project->tenant)->graph;
            $ok = [];
            $skipped = [];
            foreach ($todo as $id) {
                $u = $users[$id];
                if (! $u['eligible'] || ! $u['usage_location']) {
                    $skipped[] = $u['name'].' ('.(! $u['eligible'] ? 'no eligible base licence' : 'no usage location').')';

                    continue;
                }
                try {
                    $g->post('/users/'.$id.'/assignLicense', ['addLicenses' => [['skuId' => $skuId, 'disabledPlans' => []]], 'removeLicenses' => []]);
                    $ok[] = $u['name'];
                } catch (GraphException $e) {
                    $skipped[] = $u['name'].' ('.$e->friendly().')';
                }
            }
            ActivityLog::record('copilot.licence', $project->tenant, $skipped ? 'warning' : 'success', "{$by->email} assigned Copilot to ".count($ok).' user(s)'.($ok ? ': '.implode(', ', $ok) : '').($skipped ? '. Skipped: '.implode('; ', $skipped) : ''));
            $this->assess($project->fresh());

            return ['assigned' => $ok, 'skipped' => $skipped, 'message' => count($ok).' user(s) got Copilot.'.($skipped ? ' Skipped: '.implode('; ', $skipped).'.' : '')];
        });
    }

    public function remove(CopilotProject $project, array $userIds, User $by): string
    {
        $a = $project->assessment ?? $this->assess($project);
        $users = collect($a['users'])->keyBy('id');
        $skuIds = $a['copilot']['skuIds'];

        return TenantLock::run($project->tenant, function () use ($project, $userIds, $users, $skuIds, $by) {
            $g = Scanner::context($project->tenant)->graph;
            $done = [];
            foreach ($userIds as $id) {
                if (! ($users[$id]['copilot'] ?? false)) {
                    continue;
                }
                $g->post('/users/'.$id.'/assignLicense', ['addLicenses' => [], 'removeLicenses' => $skuIds]);
                $done[] = $users[$id]['name'];
            }
            ActivityLog::record('copilot.licence', $project->tenant, 'info', "{$by->email} removed Copilot from ".count($done).' user(s): '.implode(', ', $done));
            $this->assess($project->fresh());

            return count($done).' Copilot licence(s) reclaimed.';
        });
    }

    /** Make selected public Microsoft 365 groups private. Only groups the assessment flagged as public. */
    public function privatise(CopilotProject $project, array $groupIds, User $by): string
    {
        $public = collect($project->assessment['public_groups'] ?? [])->keyBy('id');
        $ids = array_values(array_filter($groupIds, fn ($id) => $public->has($id)));

        return TenantLock::run($project->tenant, function () use ($project, $ids, $public, $by) {
            $g = Scanner::context($project->tenant)->graph;
            foreach ($ids as $id) {
                $g->patch('/groups/'.$id, ['visibility' => 'Private']);
            }
            ActivityLog::record('copilot.groups', $project->tenant, 'success', "{$by->email} made ".count($ids).' group(s) private: '.collect($ids)->map(fn ($i) => $public[$i]['name'])->implode(', '));
            $this->assess($project->fresh());

            return count($ids).' group(s) are now private.';
        });
    }
}
