<?php

namespace App\Services\Lifecycle;

use App\Models\ActivityLog;
use App\Models\LifecycleRun;
use App\Models\LifecycleTask;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Graph\GraphException;
use App\Support\TenantLock;
use Carbon\Carbon;
use Throwable;

/**
 * Joiner process. Creates the account, sets the manager, assigns licences and groups, and issues
 * the first credential. Future start dates create the account disabled and schedule enablement.
 * Credentials are returned once and never stored.
 */
class Onboarding
{
    /**
     * @param  array  $in  first_name, last_name, display_name, username, domain, job_title, department, office, mobile,
     *                     usage_location, manager_id, password_mode (password|tap), tap_hours, licences[], groups[],
     *                     copy_from (user id), start_date (Y-m-d), ticket, notes, checklist[]
     * @return array{run: LifecycleRun, credentials: ?array}
     */
    public function run(Tenant $tenant, array $in, User $by): array
    {
        return TenantLock::run($tenant, fn () => $this->execute($tenant, $in, $by));
    }

    private function execute(Tenant $tenant, array $in, User $by): array
    {
        $dir = new Directory($tenant);
        $g = $dir->graph;
        $upn = strtolower($in['username'].'@'.$in['domain']);
        $start = ! empty($in['start_date']) ? Carbon::parse($in['start_date'])->startOfDay()->setTime(7, 0) : null;
        $future = $start && $start->isFuture();
        $steps = [];
        $step = function (string $key, string $label, string $status, string $message, string $kind = 'auto') use (&$steps) {
            $steps[] = compact('key', 'label', 'kind', 'status', 'message') + ['at' => now()->toIso8601String()];
        };

        $run = LifecycleRun::create([
            'tenant_id' => $tenant->id, 'type' => 'onboard', 'target_upn' => $upn, 'target_name' => $in['display_name'],
            'ticket' => $in['ticket'] ?? null, 'status' => 'in_progress', 'steps' => [], 'notes' => $in['notes'] ?? null, 'user_id' => $by->id,
        ]);

        // Copy access from a similar colleague ("model user"), merged with explicit choices.
        $licences = array_values(array_unique($in['licences'] ?? []));
        $groups = array_values(array_unique($in['groups'] ?? []));
        if (! empty($in['copy_from'])) {
            try {
                $model = $dir->snapshot($in['copy_from']);
                $licences = array_values(array_unique(array_merge($licences, array_column($model['licences'], 'skuId'))));
                $groups = array_values(array_unique(array_merge($groups, array_column(array_filter($model['groups'], fn ($x) => ! $x['dynamic']), 'id'))));
                $step('copy', 'Copy access from '.$model['user']['displayName'], 'done', count($model['licences']).' licence(s) and '.count($model['groups']).' group(s) added to the plan.');
            } catch (Throwable $e) {
                $step('copy', 'Copy access from colleague', 'failed', $e->getMessage());
            }
        }

        // 1. Account
        $password = Directory::password();
        try {
            $user = $g->post('/users', array_filter([
                'accountEnabled' => ! $future,
                'displayName' => $in['display_name'],
                'givenName' => $in['first_name'],
                'surname' => $in['last_name'],
                'mailNickname' => preg_replace('/[^a-z0-9._-]/', '', strtolower($in['username'])),
                'userPrincipalName' => $upn,
                'jobTitle' => $in['job_title'] ?? null,
                'department' => $in['department'] ?? null,
                'officeLocation' => $in['office'] ?? null,
                'mobilePhone' => $in['mobile'] ?? null,
                'businessPhones' => ! empty($in['business_phone']) ? [$in['business_phone']] : null,
                'companyName' => $in['company'] ?? null,
                'streetAddress' => $in['street'] ?? null,
                'city' => $in['city'] ?? null,
                'state' => $in['state'] ?? null,
                'postalCode' => $in['postal_code'] ?? null,
                'country' => $in['country_name'] ?? null,
                'usageLocation' => strtoupper($in['usage_location'] ?? 'US'),
                'passwordProfile' => ['password' => $password, 'forceChangePasswordNextSignIn' => true],
            ], fn ($v) => $v !== null && $v !== ''));
            $step('account', 'Create account '.$upn, 'done', $future ? 'Created disabled until the start date.' : 'Created and enabled.');
        } catch (GraphException $e) {
            $step('account', 'Create account '.$upn, 'failed', $e->friendly());
            $run->update(['steps' => $steps, 'status' => 'failed']);
            ActivityLog::record('staff.onboard', $tenant, 'failure', "Onboarding of {$upn} failed: ".$e->friendly());

            return ['run' => $run, 'credentials' => null];
        }
        $run->target_id = $user['id'];

        // 2. Manager
        if (! empty($in['manager_id'])) {
            try {
                $g->put('/users/'.$user['id'].'/manager/$ref', ['@odata.id' => 'https://graph.microsoft.com/v1.0/users/'.$in['manager_id']]);
                $step('manager', 'Set manager', 'done', 'Manager recorded for approvals, org chart and leaver hand-over.');
            } catch (GraphException $e) {
                $step('manager', 'Set manager', 'failed', $e->friendly());
            }
        }

        // 3. Licences
        $skuNames = collect($dir->licences())->pluck('name', 'skuId');
        foreach ($licences as $sku) {
            try {
                $g->post('/users/'.$user['id'].'/assignLicense', ['addLicenses' => [['skuId' => $sku, 'disabledPlans' => []]], 'removeLicenses' => []]);
                $step('lic-'.$sku, 'Licence: '.($skuNames[$sku] ?? $sku), 'done', 'Assigned.');
            } catch (GraphException $e) {
                $step('lic-'.$sku, 'Licence: '.($skuNames[$sku] ?? $sku), 'failed', $e->friendly());
            }
        }

        // 4. Groups and Teams
        $groupNames = collect($dir->groups())->pluck('name', 'id');
        foreach ($groups as $gid) {
            try {
                $g->post('/groups/'.$gid.'/members/$ref', ['@odata.id' => 'https://graph.microsoft.com/v1.0/directoryObjects/'.$user['id']]);
                $step('grp-'.$gid, 'Group: '.($groupNames[$gid] ?? $gid), 'done', 'Added.');
            } catch (GraphException $e) {
                $step('grp-'.$gid, 'Group: '.($groupNames[$gid] ?? $gid), 'failed', $e->friendly());
            }
        }

        // 5. First credential
        $credentials = ['upn' => $upn, 'mode' => $in['password_mode'] ?? 'password', 'password' => null, 'tap' => null, 'tap_expires' => null];
        if (($in['password_mode'] ?? 'password') === 'tap') {
            try {
                $minutes = max(60, min(480, (int) ($in['tap_hours'] ?? 8) * 60));
                $tap = $g->post('/users/'.$user['id'].'/authentication/temporaryAccessPassMethods', array_filter([
                    'lifetimeInMinutes' => $minutes,
                    'isUsableOnce' => false,
                    'startDateTime' => $future ? $start->copy()->utc()->toIso8601ZuluString() : null,
                ], fn ($v) => $v !== null));
                $credentials['tap'] = $tap['temporaryAccessPass'] ?? null;
                $credentials['tap_expires'] = ($future ? $start->copy() : now())->addMinutes($minutes)->toDayDateTimeString();
                $step('credential', 'Temporary Access Pass', 'done', "Valid for {$minutes} minutes".($future ? ' from the start date' : '').'. The user sets up passwordless sign-in and MFA with it.');
            } catch (GraphException $e) {
                $credentials['password'] = $password;
                $credentials['mode'] = 'password';
                $step('credential', 'Temporary Access Pass', 'failed', $e->friendly().' Falling back to the temporary password.');
            }
        } else {
            $credentials['password'] = $password;
            $step('credential', 'Temporary password', 'done', 'Must be changed at first sign-in. Share it through a secure channel, never with the username in the same message.');
        }

        // 6. Scheduled enablement
        if ($future) {
            LifecycleTask::create(['run_id' => $run->id, 'tenant_id' => $tenant->id, 'action' => 'enable_account', 'execute_at' => $start]);
            $step('enable', 'Enable account on '.$start->toFormattedDateString(), 'scheduled', 'TenantsGuard enables the account automatically at '.$start->format('H:i').'.', 'scheduled');
        }

        // 7. Hand-over checklist (selectable)
        foreach (self::CHECKLIST as $key => [$label, $hint]) {
            if (in_array($key, $in['checklist'] ?? [], true)) {
                $step($key, $label, 'pending', $hint, 'manual');
            }
        }

        $run->steps = $steps;
        $run->refreshStatus();
        $run->save();
        ActivityLog::record('staff.onboard', $tenant, $run->status === 'failed' ? 'failure' : 'success',
            "Onboarded {$in['display_name']} ({$upn}): ".count($licences).' licence(s), '.count($groups).' group(s)'.($future ? ', starts '.$start->toFormattedDateString() : ''), null, ['run' => $run->id]);

        return ['run' => $run, 'credentials' => $credentials];
    }

    /** Hand-over items that happen outside Microsoft 365 or need a person. */
    public const CHECKLIST = [
        'device' => ['Provision a device (Windows Autopilot / enrolled phone)', 'Order or assign hardware. With Autopilot the device enrolls itself at first sign-in.'],
        'mfa' => ['User registers MFA / passkey on day one', 'With a Temporary Access Pass the user goes to aka.ms/mysecurityinfo and registers Microsoft Authenticator or a passkey.'],
        'credentials' => ['Deliver credentials securely to the manager', 'Use a one-time secret link or phone. Never email the username and password together.'],
        'training' => ['Security awareness training and policies signed', 'Acceptable-use policy, phishing training, data handling (HIPAA/ISO evidence).'],
        'apps' => ['Line-of-business app access', 'Request accounts in third-party systems (practice software, CRM, payroll).'],
        'phone' => ['Phone number / Teams Phone', 'Assign a number if Teams Phone is licensed.'],
    ];
}
