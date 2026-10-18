<?php

namespace App\Console\Commands;

use App\Exceptions\TenantBusyException;
use App\Models\ActivityLog;
use App\Models\LifecycleRun;
use App\Models\LifecycleTask;
use App\Models\User;
use App\Services\Graph\GraphException;
use App\Services\Lifecycle\Directory;
use App\Services\Lifecycle\Offboarding;
use App\Services\Notifier;
use App\Support\TenantLock;
use Illuminate\Console\Command;
use Throwable;

/** Runs scheduled staff-lifecycle actions: enable on start date, remove licences, delete leavers. */
class ProcessLifecycleTasks extends Command
{
    protected $signature = 'lifecycle:process';

    protected $description = 'Execute due onboarding/offboarding tasks (start-date enablement, delayed licence removal, account deletion).';

    public function handle(): int
    {
        $due = LifecycleTask::with('run', 'tenant')->where('status', 'pending')->where('execute_at', '<=', now())->orderBy('execute_at')->get();

        foreach ($due as $task) {
            $run = $task->run;
            $tenant = $task->tenant;
            if ($task->action === 'offboard') {
                $this->offboard($task);

                continue;
            }
            try {
                $message = TenantLock::run($tenant, function () use ($task, $run, $tenant) {
                    $g = (new Directory($tenant))->graph;
                    $uid = $run->target_id;

                    return match ($task->action) {
                        'enable_account' => (function () use ($g, $uid) {
                            $g->patch('/users/'.$uid, ['accountEnabled' => true]);

                            return 'Account enabled on the start date.';
                        })(),
                        'remove_licenses' => (function () use ($g, $uid) {
                            $user = $g->get('/users/'.$uid);
                            $skus = array_column($user['assignedLicenses'] ?? [], 'skuId');
                            if ($skus) {
                                $g->post('/users/'.$uid.'/assignLicense', ['addLicenses' => [], 'removeLicenses' => $skus]);
                            }

                            return $skus ? count($skus).' licence(s) removed.' : 'No licences left to remove.';
                        })(),
                        'delete_user' => (function () use ($g, $uid) {
                            $g->delete('/users/'.$uid);

                            return 'Account deleted (restorable from Deleted users for 30 days).';
                        })(),
                    };
                });
                $task->update(['status' => 'done', 'message' => $message]);
                $run->updateStep(match ($task->action) {
                    'enable_account' => 'enable', 'remove_licenses' => 'licences', default => 'delete_account',
                }, 'done', $message, 'Scheduler');
                ActivityLog::record('staff.task', $tenant, 'success', LifecycleTask::ACTIONS[$task->action].": {$run->target_upn}. {$message}");
                $this->info("{$tenant->name}: {$task->action} {$run->target_upn}");
            } catch (TenantBusyException) {
                $this->warn("{$tenant->name} busy, retrying next run.");
            } catch (Throwable $e) {
                $msg = $e instanceof GraphException ? $e->friendly() : $e->getMessage();
                $task->update(['status' => 'failed', 'message' => $msg]);
                $run->updateStep(match ($task->action) {
                    'enable_account' => 'enable', 'remove_licenses' => 'licences', default => 'delete_account',
                }, 'failed', $msg, 'Scheduler');
                ActivityLog::record('staff.task', $tenant, 'failure', LifecycleTask::ACTIONS[$task->action]." failed for {$run->target_upn}: {$msg}");
                Notifier::send('change_failed', 'Scheduled staff task failed', LifecycleTask::ACTIONS[$task->action]." for {$run->target_upn}: {$msg}", $tenant);
            }
        }

        return self::SUCCESS;
    }

    /** A whole offboarding that was scheduled for a later date and time. */
    private function offboard(LifecycleTask $task): void
    {
        $run = $task->run;
        $tenant = $task->tenant;
        $payload = $task->payload ?? [];
        $by = User::find($payload['by'] ?? null) ?? User::orderBy('id')->get()->first(fn (User $u) => $u->isGlobalAdmin());
        try {
            $run = (new Offboarding)->run($tenant, $payload, $by, $run);
            $task->update(['status' => 'done', 'message' => 'Offboarding ran: '.(LifecycleRun::STATUS[$run->status][0] ?? $run->status).'.']);
            $this->info("{$tenant->name}: offboarded {$run->target_upn}");
            if (in_array($run->status, ['failed', 'partial'], true)) {
                Notifier::send('change_failed', 'Scheduled offboarding had failures', "Offboarding of {$run->target_upn} finished with failed steps. Open the run in TenantsGuard.", $tenant);
            }
        } catch (TenantBusyException) {
            $this->warn("{$tenant->name} busy, retrying next run.");
        } catch (Throwable $e) {
            $msg = $e instanceof GraphException ? $e->friendly() : $e->getMessage();
            $task->update(['status' => 'failed', 'message' => $msg]);
            $run->updateStep('scheduled', 'failed', 'Scheduled offboarding could not start: '.$msg, 'Scheduler');
            ActivityLog::record('staff.task', $tenant, 'failure', "Scheduled offboarding failed for {$run->target_upn}: {$msg}");
            Notifier::send('change_failed', 'Scheduled offboarding failed', "{$run->target_upn}: {$msg}", $tenant);
        }
    }
}
