<?php

namespace App\Services\Scripts;

use App\Exceptions\TenantBusyException;
use App\Models\ActivityLog;
use App\Models\Script;
use App\Models\ScriptRun;
use App\Models\ScriptRunTarget;
use App\Models\ScriptVersion;
use App\Models\Setting;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Notifier;
use App\Support\TenantLock;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/** Library workflow (draft, submit, approve) and run lifecycle (queue, process, cancel). */
class ScriptService
{
    public const PARAM_TYPES = ['string' => 'Text', 'int' => 'Number', 'bool' => 'Yes / no', 'choice' => 'Choice', 'email' => 'Email / UPN', 'guid' => 'GUID'];

    public static function enabled(): bool
    {
        return Setting::find('scripts_enabled')?->value === '1';
    }

    public static function selfApproval(): bool
    {
        return Setting::find('scripts_self_approval')?->value === '1';
    }

    // ------------------------------------------------------------------ library

    /** Save a new draft version (versions are immutable; editing always creates a new one). */
    public function saveVersion(Script $script, array $in, User $by): ScriptVersion
    {
        $params = array_values(array_map(fn ($p) => [
            'name' => $p['name'], 'label' => $p['label'] ?: $p['name'], 'type' => $p['type'], 'required' => (bool) ($p['required'] ?? false),
            'default' => ($p['default'] ?? '') === '' ? null : $p['default'],
            'options' => $p['type'] === 'choice' ? array_values(array_filter(array_map('trim', explode(',', (string) ($p['options'] ?? ''))))) : [],
            'help' => $p['help'] ?? null,
        ], $in['parameters'] ?? []));
        $body = str_replace("\r\n", "\n", $in['body']);

        return DB::transaction(function () use ($script, $in, $by, $params, $body) {
            // Older drafts and pending versions are replaced by the new one; approved versions stay runnable until a new approval.
            $script->versions()->whereIn('status', ['draft', 'pending'])->update(['status' => 'superseded']);
            $version = $script->versions()->create([
                'version' => (int) $script->versions()->max('version') + 1,
                'body' => $body, 'sha256' => hash('sha256', $body), 'risk' => $in['risk'],
                'use_graph_module' => ! empty($in['use_graph_module']), 'use_exchange' => ! empty($in['use_exchange']),
                'timeout' => (int) $in['timeout'], 'parameters' => $params, 'lint' => ScriptLinter::lint($body, $params, $in['risk']),
                'change_note' => $in['change_note'] ?? null, 'status' => 'draft', 'created_by' => $by->id,
            ]);
            ActivityLog::record('script.edit', null, 'info', "Script \"{$script->name}\" v{$version->version} saved by {$by->email}", null, ['script' => $script->id, 'sha256' => $version->sha256]);

            return $version;
        });
    }

    public function submit(ScriptVersion $v, User $by): void
    {
        if ($v->status !== 'draft') {
            throw new RuntimeException('Only drafts can be submitted.');
        }
        if ($v->blocked()) {
            throw new RuntimeException('Fix the blocked items before submitting: '.collect($v->blocked())->pluck('text')->unique()->implode(', ').'.');
        }
        $v->update(['status' => 'pending', 'submitted_by' => $by->id, 'submitted_at' => now()]);
        ActivityLog::record('script.submit', null, 'info', "Script \"{$v->script->name}\" v{$v->version} submitted for approval by {$by->email}");
        Notifier::send('script', 'Script waiting for approval', "\"{$v->script->name}\" v{$v->version} ({$v->risk}) was submitted by {$by->name}.");
    }

    /** Four-eyes: the approver must be someone else unless self-approval is switched on. */
    public function review(ScriptVersion $v, User $by, bool $approve, ?string $note): void
    {
        if ($v->status !== 'pending') {
            throw new RuntimeException('This version is not waiting for approval.');
        }
        $authors = array_filter([$v->created_by, $v->submitted_by]);
        if ($approve && in_array($by->id, $authors, true) && ! self::selfApproval()) {
            throw new RuntimeException('You wrote or submitted this version, so someone else must approve it. A Global Administrator can allow self-approval for single-person teams.');
        }
        if ($approve && $v->blocked()) {
            throw new RuntimeException('This version has blocked items and cannot be approved.');
        }
        if ($approve && hash('sha256', $v->body) !== $v->sha256) {
            throw new RuntimeException('The script body does not match its recorded hash.');
        }

        DB::transaction(function () use ($v, $by, $approve, $note) {
            if ($approve) {
                $v->script->versions()->where('status', 'approved')->update(['status' => 'superseded']);
            }
            $v->forceFill(['status' => $approve ? 'approved' : 'rejected', 'reviewed_by' => $by->id, 'reviewed_at' => now(), 'review_note' => $note]);
            $v->signature = $approve ? $v->sign() : null;
            $v->save();
        });
        $self = in_array($by->id, array_filter([$v->created_by, $v->submitted_by]), true);
        ActivityLog::record($approve ? 'script.approve' : 'script.reject', null, $approve ? 'success' : 'info',
            "Script \"{$v->script->name}\" v{$v->version} ".($approve ? 'approved' : 'rejected')." by {$by->email}".($self ? ' (self-approved)' : '').($note ? ": {$note}" : ''),
            null, ['sha256' => $v->sha256]);
    }

    // ------------------------------------------------------------------ runs

    /** Validate parameter values against the approved definitions. Values are data, never code. */
    public static function validateParameters(ScriptVersion $v, array $values): array
    {
        $out = [];
        foreach ($v->parameters as $p) {
            $raw = $values[$p['name']] ?? null;
            $raw = is_string($raw) ? trim($raw) : $raw;
            if ($raw === null || $raw === '') {
                if ($p['type'] === 'bool') {
                    $out[$p['name']] = false;

                    continue;
                }
                if ($p['required']) {
                    throw new RuntimeException("{$p['label']} is required.");
                }

                continue;
            }
            $out[$p['name']] = match ($p['type']) {
                'int' => filter_var($raw, FILTER_VALIDATE_INT) !== false ? (int) $raw : throw new RuntimeException("{$p['label']} must be a whole number."),
                'bool' => in_array($raw, ['1', 'true', 'on', 1, true], true),
                'choice' => in_array($raw, $p['options'], true) ? $raw : throw new RuntimeException("{$p['label']} must be one of: ".implode(', ', $p['options']).'.'),
                'email' => filter_var($raw, FILTER_VALIDATE_EMAIL) ? $raw : throw new RuntimeException("{$p['label']} must be an email address or UPN."),
                'guid' => preg_match('/^[0-9a-f]{8}-([0-9a-f]{4}-){3}[0-9a-f]{12}$/i', $raw) ? strtolower($raw) : throw new RuntimeException("{$p['label']} must be a GUID."),
                default => mb_strlen($raw) <= 500 && ! preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', $raw) ? $raw : throw new RuntimeException("{$p['label']} is too long or contains control characters."),
            };
        }

        return $out;
    }

    /** @param  Tenant[]  $tenants  already authorised by the caller */
    public function queue(ScriptVersion $v, array $tenants, array $params, array $meta, User $by): ScriptRun
    {
        if (! self::enabled()) {
            throw new RuntimeException('PowerShell execution is switched off.');
        }
        if (! $v->intact()) {
            ActivityLog::record('script.integrity', null, 'failure', "Integrity check failed for \"{$v->script->name}\" v{$v->version}. Run refused.");
            Notifier::send('script', 'Script integrity check failed', "\"{$v->script->name}\" v{$v->version} no longer matches its approval. Review the database for tampering.");
            throw new RuntimeException('This script version failed its integrity check and cannot run. The event was logged.');
        }
        $run = DB::transaction(function () use ($v, $tenants, $params, $meta, $by) {
            $run = ScriptRun::create(['script_version_id' => $v->id, 'user_id' => $by->id, 'parameters' => $params] + $meta);
            foreach ($tenants as $t) {
                $run->targets()->create(['tenant_id' => $t->id]);
            }

            return $run;
        });
        ActivityLog::record('script.run', null, 'info', "{$by->email} queued \"{$v->script->name}\" v{$v->version} ({$v->risk}) for ".count($tenants).' client(s): '
            .collect($tenants)->pluck('name')->implode(', ').". Reason: {$meta['reason']}", null, ['run' => $run->id, 'sha256' => $v->sha256, 'parameters' => $params]);

        return $run;
    }

    public function cancel(ScriptRun $run, User $by): void
    {
        if (! $run->isActive()) {
            return;
        }
        $run->targets()->where('status', 'queued')->update(['status' => 'cancelled', 'error' => 'Cancelled by '.$by->name, 'finished_at' => now()]);
        $run->update(['status' => 'cancelling']);
        $run->refreshStatus();
        ActivityLog::record('script.cancel', null, 'info', "Script run #{$run->id} cancelled by {$by->email}");
    }

    /** Work through queued targets. One processor at a time; safe to call from the scheduler and from a spawned worker. */
    public function process(int $maxSeconds = 3300): int
    {
        $lock = Cache::lock('tg:scripts:processor', $maxSeconds + 120);
        if (! $lock->get()) {
            return 0;
        }
        $done = 0;
        $until = time() + $maxSeconds;
        $skip = [];
        try {
            // Only one processor holds the lock, so anything still "running" belongs to a worker that died.
            foreach (ScriptRunTarget::with('run')->where('status', 'running')->get() as $orphan) {
                $orphan->update(['status' => 'failed', 'error' => 'The worker stopped unexpectedly while this was running. Check the server and run it again.', 'finished_at' => now()]);
                $orphan->run->refreshStatus();
            }
            while (time() < $until) {
                $target = ScriptRunTarget::with('run.version.script', 'tenant')->where('status', 'queued')->whereNotIn('id', $skip ?: [0])->orderBy('id')->first();
                if (! $target) {
                    break;
                }
                $run = $target->run;
                if (! self::enabled()) {
                    $target->update(['status' => 'failed', 'error' => 'PowerShell execution was switched off before this ran.', 'finished_at' => now()]);
                    $run->refreshStatus();

                    continue;
                }
                if ($run->status === 'queued') {
                    $run->update(['status' => 'running', 'started_at' => now()]);
                }
                try {
                    TenantLock::run($target->tenant, fn () => app(ScriptExecutor::class)->execute($target, $run->version, $run->parameters ?? [],
                        fn () => $run->fresh()->status === 'cancelling'));
                } catch (TenantBusyException) {
                    $skip[] = $target->id; // another job holds the tenant; try again on the next pass

                    continue;
                }
                $done++;
                $run->refreshStatus();
                if (! $run->isActive()) {
                    $this->finished($run->fresh('version.script', 'user', 'targets.tenant'));
                }
            }
        } finally {
            $lock->release();
        }

        return $done;
    }

    private function finished(ScriptRun $run): void
    {
        $failed = $run->targets->whereIn('status', ['failed', 'timeout']);
        $name = $run->version->script->name;
        ActivityLog::record('script.finished', null, $failed->isEmpty() ? 'success' : 'failure',
            "Script \"{$name}\" run #{$run->id} finished: ".$run->targets->where('status', 'succeeded')->count().' of '.$run->targets->count().' client(s) succeeded.', null, ['run' => $run->id]);
        if ($failed->isNotEmpty()) {
            Notifier::send('script', "Script \"{$name}\" failed for ".$failed->count().' client(s)', $failed->map(fn ($t) => $t->tenant->name.': '.$t->error)->implode("\n"));
        }
    }

    /** Start a detached worker so the run begins immediately, without waiting for the scheduler. */
    public static function dispatch(): void
    {
        if (config('tenantsguard.scripts.dispatch') !== 'spawn') {
            return;
        }
        $php = PHP_BINARY;
        $artisan = base_path('artisan');
        if (DIRECTORY_SEPARATOR === '\\') {
            pclose(popen('start "" /B "'.$php.'" "'.$artisan.'" scripts:process > NUL 2>&1', 'r'));
        } else {
            exec(escapeshellarg($php).' '.escapeshellarg($artisan).' scripts:process > /dev/null 2>&1 &');
        }
    }
}
