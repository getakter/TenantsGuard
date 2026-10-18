<?php

namespace App\Services\Lifecycle;

use App\Models\ActivityLog;
use App\Models\LifecycleRun;
use App\Models\LifecycleTask;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Checks\Roles;
use App\Services\Graph\GraphException;
use App\Services\Notifier;
use App\Support\TenantLock;
use Carbon\Carbon;
use RuntimeException;
use Throwable;

/**
 * Leaver process in the order security teams expect: lock out first, then preserve data, then
 * remove access and licences, then clean up. Memberships are captured before removal.
 */
class Offboarding
{
    /** key => [label, description, kind, default on] */
    public const STEPS = [
        // Immediate lock-out
        'block_signin' => ['Block sign-in', 'Disable the account immediately.', 'auto', true],
        'revoke_sessions' => ['Sign out of all sessions', 'Revoke refresh tokens so Outlook, Teams and mobile apps stop working within minutes.', 'auto', true],
        'reset_password' => ['Reset password', 'Set a random password nobody knows.', 'auto', true],
        'remove_mfa' => ['Remove MFA methods', 'Delete Authenticator, phone, FIDO2 and TAP methods so a lost or kept phone cannot be used.', 'auto', true],
        // Mailbox and data
        'auto_reply' => ['Set out-of-office reply', 'Tell senders who to contact instead.', 'auto', true],
        'remove_rules' => ['Remove inbox rules', 'Deletes inbox rules, including forwarding rules, which are a common data-exfiltration path.', 'auto', true],
        'convert_mailbox' => ['Convert mailbox to shared mailbox', 'Keeps email accessible without a licence. Do this BEFORE licences are removed.', 'manual', true],
        'forward_mail' => ['Forward or delegate mail to the manager', 'Grant the manager Full Access to the shared mailbox or set forwarding.', 'manual', true],
        'hide_gal' => ['Hide from the global address list', 'Stops colleagues emailing the leaver by mistake.', 'manual', true],
        'onedrive' => ['Hand over OneDrive files', "Give the manager access to the leaver's OneDrive before it is deleted (default retention is 30 days).", 'manual', true],
        // Access
        'remove_roles' => ['Remove admin roles', 'Remove every Entra directory role.', 'auto', true],
        'remove_groups' => ['Remove from groups and Teams', 'Remove from all groups, Teams and distribution lists that can be managed through Graph. Dynamic groups update automatically.', 'auto', true],
        'ownership' => ['Transfer group and Teams ownership', 'Adds the new owner first, then removes the leaver as owner. Groups they solely own are reported if no new owner is chosen.', 'auto', true],
        'third_party' => ['Revoke access to third-party apps', 'Line-of-business, SaaS and shared accounts the leaver knew passwords for.', 'manual', true],
        // Devices
        'devices' => ['Retire or wipe Intune devices', 'Retire removes company data. Wipe factory-resets corporate devices.', 'auto', true],
        'collect_hardware' => ['Collect hardware', 'Laptop, phone, security keys, badges.', 'manual', true],
        // Licences and account
        'licences' => ['Remove licences', 'Frees the seats. Scheduled after a delay so the mailbox can be converted first.', 'auto', true],
        'delete_account' => ['Delete the account', 'Scheduled. Deleted users can be restored for 30 days.', 'auto', false],
    ];

    public const SNIPPETS = [
        'convert_mailbox' => 'Set-Mailbox -Identity {upn} -Type Shared',
        'forward_mail' => 'Add-MailboxPermission -Identity {upn} -User {manager} -AccessRights FullAccess -InheritanceType All',
        'hide_gal' => 'Set-Mailbox -Identity {upn} -HiddenFromAddressListsEnabled $true',
        'onedrive' => 'Set-SPOUser -Site https://{tenant}-my.sharepoint.com/personal/{od} -LoginName {manager} -IsSiteCollectionAdmin $true',
    ];

    /**
     * @param  array  $in  user_id, steps[], auto_reply_message, devices: [deviceId => none|retire|wipe], licence_delay_days,
     *                     delete_after_days, manager_upn, ticket, reason
     */
    public function run(Tenant $tenant, array $in, User $by, ?LifecycleRun $existing = null): LifecycleRun
    {
        return TenantLock::run($tenant, fn () => $this->execute($tenant, $in, $by, $existing));
    }

    /** Schedule the whole offboarding for a later date and time ("Schedule this offboarding"). */
    public function schedule(Tenant $tenant, array $in, User $by, Carbon $at): LifecycleRun
    {
        $dir = new Directory($tenant);
        self::guard($dir, $in['user_id']);
        $u = $dir->user($in['user_id']);
        $run = LifecycleRun::create([
            'tenant_id' => $tenant->id, 'type' => 'offboard', 'target_upn' => $u['userPrincipalName'], 'target_name' => $u['displayName'], 'target_id' => $u['id'],
            'ticket' => $in['ticket'] ?? null, 'status' => 'scheduled', 'notes' => $in['reason'] ?? null, 'user_id' => $by->id,
            'steps' => [['key' => 'scheduled', 'label' => 'Offboarding scheduled for '.$at->format('D j M Y, H:i'), 'kind' => 'scheduled', 'status' => 'scheduled',
                'message' => count($in['steps'] ?? []).' step(s) will run automatically. Access stays unchanged until then.', 'at' => now()->toIso8601String()]],
        ]);
        LifecycleTask::create(['run_id' => $run->id, 'tenant_id' => $tenant->id, 'action' => 'offboard', 'payload' => $in + ['by' => $by->id], 'execute_at' => $at]);
        ActivityLog::record('staff.offboard', $tenant, 'info', "Scheduled offboarding of {$u['displayName']} ({$u['userPrincipalName']}) for ".$at->toDayDateTimeString(), null, ['run' => $run->id]);

        return $run;
    }

    /** Refuse to remove the last Global Administrator of the client tenant. */
    public static function guard(Directory $dir, string $userId): void
    {
        $roles = $dir->graph->getAll('/directoryRoles?$expand=members');
        $ga = collect($roles)->firstWhere('roleTemplateId', Roles::GLOBAL_ADMIN);
        $members = collect($ga['members'] ?? []);
        if ($members->contains('id', $userId) && $members->count() <= 1) {
            throw new RuntimeException('This user is the only Global Administrator of the tenant. Assign another Global Administrator before offboarding.');
        }
    }

    private function execute(Tenant $tenant, array $in, User $by, ?LifecycleRun $existing = null): LifecycleRun
    {
        $dir = new Directory($tenant);
        $g = $dir->graph;
        $selected = $in['steps'] ?? [];
        self::guard($dir, $in['user_id']);

        $snapshot = $dir->snapshot($in['user_id']);
        $u = $snapshot['user'];
        $uid = $u['id'];
        $upn = $u['userPrincipalName'];

        $attrs = [
            'tenant_id' => $tenant->id, 'type' => 'offboard', 'target_upn' => $upn, 'target_name' => $u['displayName'], 'target_id' => $uid,
            'ticket' => $in['ticket'] ?? null, 'status' => 'in_progress', 'snapshot' => $snapshot, 'notes' => $in['reason'] ?? null, 'user_id' => $by->id,
        ];
        $steps = [];
        if ($existing) {
            $existing->update($attrs);
            $run = $existing;
            $steps[] = ['key' => 'scheduled', 'label' => 'Scheduled offboarding started', 'kind' => 'scheduled', 'status' => 'done', 'message' => 'Started automatically at the scheduled time.', 'at' => now()->toIso8601String()];
        } else {
            $run = LifecycleRun::create($attrs + ['steps' => []]);
        }

        $record = function (string $key, string $status, string $message, ?string $label = null) use (&$steps) {
            [$l, , $kind] = self::STEPS[$key] ?? [$label ?? $key, '', 'auto'];
            $steps[] = ['key' => $key, 'label' => $label ?? $l, 'kind' => $kind, 'status' => $status, 'message' => $message, 'at' => now()->toIso8601String()];
        };
        $do = function (string $key, callable $fn) use ($selected, $record) {
            if (! in_array($key, $selected, true)) {
                return;
            }
            try {
                $record($key, 'done', $fn());
            } catch (GraphException $e) {
                $record($key, 'failed', $e->friendly());
            } catch (Throwable $e) {
                $record($key, 'failed', $e->getMessage());
            }
        };

        // ---- 1. Lock out ----
        $do('block_signin', function () use ($g, $uid) {
            $g->patch('/users/'.$uid, ['accountEnabled' => false]);

            return 'Sign-in blocked.';
        });
        $do('revoke_sessions', function () use ($g, $uid) {
            $g->post('/users/'.$uid.'/revokeSignInSessions', []);

            return 'All refresh tokens revoked. Apps sign out within minutes.';
        });
        $do('reset_password', function () use ($g, $uid) {
            $g->patch('/users/'.$uid, ['passwordProfile' => ['password' => Directory::password(24), 'forceChangePasswordNextSignIn' => false]]);

            return 'Password set to a random value that was not stored.';
        });
        $do('remove_mfa', function () use ($g, $uid, $snapshot) {
            $segments = [
                '#microsoft.graph.microsoftAuthenticatorAuthenticationMethod' => 'microsoftAuthenticatorMethods',
                '#microsoft.graph.phoneAuthenticationMethod' => 'phoneMethods',
                '#microsoft.graph.fido2AuthenticationMethod' => 'fido2Methods',
                '#microsoft.graph.softwareOathAuthenticationMethod' => 'softwareOathMethods',
                '#microsoft.graph.temporaryAccessPassAuthenticationMethod' => 'temporaryAccessPassMethods',
                '#microsoft.graph.emailAuthenticationMethod' => 'emailMethods',
                '#microsoft.graph.windowsHelloForBusinessAuthenticationMethod' => 'windowsHelloForBusinessMethods',
            ];
            $removed = 0;
            foreach ($snapshot['methods'] as $m) {
                if (isset($segments[$m['type']])) {
                    $g->delete('/users/'.$uid.'/authentication/'.$segments[$m['type']].'/'.$m['id']);
                    $removed++;
                }
            }

            return $removed ? "{$removed} MFA method(s) removed." : 'No MFA methods registered.';
        });

        // ---- 2. Mailbox ----
        $do('auto_reply', function () use ($g, $uid, $in) {
            $msg = trim((string) ($in['auto_reply_message'] ?? '')) ?: 'Thank you for your email. I am no longer with the organisation.';
            $html = nl2br(e($msg));
            $g->patch('/users/'.$uid.'/mailboxSettings', ['automaticRepliesSetting' => ['status' => 'alwaysEnabled', 'externalAudience' => 'all', 'internalReplyMessage' => $html, 'externalReplyMessage' => $html]]);

            return 'Out-of-office reply enabled for internal and external senders.';
        });

        $do('remove_rules', function () use ($g, $uid, $snapshot) {
            foreach ($snapshot['rules'] as $r) {
                $g->delete('/users/'.$uid.'/mailFolders/inbox/messageRules/'.$r['id']);
            }

            return $snapshot['rules'] ? 'Removed '.count($snapshot['rules']).' rule(s): '.implode(', ', array_column($snapshot['rules'], 'name')).'.' : 'No inbox rules.';
        });

        // ---- 3. Access ----
        $do('remove_roles', function () use ($g, $uid, $snapshot) {
            foreach ($snapshot['roles'] as $r) {
                $g->delete('/directoryRoles/'.$r['id'].'/members/'.$uid.'/$ref');
            }

            return $snapshot['roles'] ? 'Removed: '.implode(', ', array_column($snapshot['roles'], 'name')).'.' : 'No admin roles assigned.';
        });
        $do('ownership', function () use ($g, $uid, $snapshot, $in) {
            $new = $in['new_owner_id'] ?? null;
            $moved = [];
            $failed = [];
            foreach ($snapshot['owned'] as $grp) {
                try {
                    if ($new && $new !== $uid) {
                        try {
                            $g->post('/groups/'.$grp['id'].'/owners/$ref', ['@odata.id' => 'https://graph.microsoft.com/v1.0/users/'.$new]);
                        } catch (GraphException $e) {
                            if (! str_contains(strtolower($e->getMessage()), 'already exist')) {
                                throw $e;
                            }
                        }
                    }
                    $g->delete('/groups/'.$grp['id'].'/owners/'.$uid.'/$ref');
                    $moved[] = $grp['name'];
                } catch (GraphException $e) {
                    $failed[] = $grp['name'].' ('.$e->friendly().')';
                }
            }
            if ($failed && ! $moved) {
                throw new RuntimeException('Could not change ownership of: '.implode('; ', $failed).'. Choose a new owner and retry, or fix it in the admin center.');
            }

            return ($snapshot['owned'] ? 'Ownership updated for '.count($moved).' group(s)'.($moved ? ': '.implode(', ', $moved) : '').'.' : 'The leaver owns no groups or Teams.')
                .($failed ? ' Failed: '.implode('; ', $failed) : '');
        });
        $do('remove_groups', function () use ($g, $uid, $snapshot) {
            $removed = [];
            $failed = [];
            foreach ($snapshot['groups'] as $grp) {
                if ($grp['dynamic']) {
                    continue;
                }
                try {
                    $g->delete('/groups/'.$grp['id'].'/members/'.$uid.'/$ref');
                    $removed[] = $grp['name'];
                } catch (GraphException $e) {
                    $failed[] = $grp['name'];
                }
            }
            if ($failed && ! $removed) {
                throw new RuntimeException('Could not remove from: '.implode(', ', $failed).' (manage in Exchange admin center).');
            }

            return ($removed ? 'Removed from '.count($removed).': '.implode(', ', $removed).'.' : 'No removable group memberships.')
                .($failed ? ' Not removable through Graph: '.implode(', ', $failed).'.' : '');
        });

        // ---- 4. Devices ----
        if (in_array('devices', $selected, true)) {
            $actions = $in['devices'] ?? [];
            $done = [];
            $errors = [];
            foreach ($snapshot['devices'] as $d) {
                $action = $actions[$d['id']] ?? ($in['device_action'] ?? 'retire');
                if (! in_array($action, ['retire', 'wipe'], true)) {
                    continue;
                }
                try {
                    $g->post('/deviceManagement/managedDevices/'.$d['id'].'/'.$action, $action === 'wipe' ? ['keepEnrollmentData' => false, 'keepUserData' => false] : []);
                    $done[] = "{$d['name']} ({$action})";
                } catch (GraphException $e) {
                    $errors[] = "{$d['name']}: ".$e->friendly();
                }
            }
            $record('devices', $errors && ! $done ? 'failed' : 'done',
                ($done ? 'Requested: '.implode(', ', $done).'.' : ($snapshot['devices'] ? 'No device actions selected.' : 'No Intune devices for this user.')).($errors ? ' Errors: '.implode('; ', $errors) : ''));
        }

        // ---- 5. Licences ----
        if (in_array('licences', $selected, true)) {
            $delay = max(0, (int) ($in['licence_delay_days'] ?? 0));
            if (! $snapshot['licences']) {
                $record('licences', 'skipped', 'No licences assigned.');
            } elseif ($delay === 0) {
                try {
                    $g->post('/users/'.$uid.'/assignLicense', ['addLicenses' => [], 'removeLicenses' => array_column($snapshot['licences'], 'skuId')]);
                    $record('licences', 'done', 'Removed: '.implode(', ', array_column($snapshot['licences'], 'name')).'.');
                } catch (GraphException $e) {
                    $record('licences', 'failed', $e->friendly());
                }
            } else {
                $at = now()->addDays($delay)->setTime(7, 0);
                LifecycleTask::create(['run_id' => $run->id, 'tenant_id' => $tenant->id, 'action' => 'remove_licenses', 'execute_at' => $at]);
                $record('licences', 'scheduled', 'Licences ('.implode(', ', array_column($snapshot['licences'], 'name')).') will be removed on '.$at->toFormattedDateString().'. Convert the mailbox first.');
            }
        }

        // ---- 6. Delete account ----
        if (in_array('delete_account', $selected, true)) {
            $at = now()->addDays(max(1, (int) ($in['delete_after_days'] ?? 30)))->setTime(7, 0);
            LifecycleTask::create(['run_id' => $run->id, 'tenant_id' => $tenant->id, 'action' => 'delete_user', 'execute_at' => $at]);
            $record('delete_account', 'scheduled', 'Account deletion scheduled for '.$at->toFormattedDateString().' (restorable for 30 days after that).');
        }

        // ---- 7. Manual checklist ----
        $manager = $in['manager_upn'] ?? '<manager@domain>';
        foreach (self::STEPS as $key => [$label, $desc, $kind]) {
            if ($kind === 'manual' && in_array($key, $selected, true)) {
                $snippet = isset(self::SNIPPETS[$key]) ? ' PowerShell: '.strtr(self::SNIPPETS[$key], [
                    '{upn}' => $upn, '{manager}' => $manager, '{tenant}' => explode('.', (string) $tenant->domain)[0],
                    '{od}' => str_replace(['@', '.'], '_', $upn),
                ]) : '';
                $record($key, 'pending', $desc.$snippet);
            }
        }

        $run->steps = $steps;
        $run->refreshStatus();
        $run->save();
        $failed = count(array_filter($steps, fn ($s) => $s['status'] === 'failed'));
        ActivityLog::record('staff.offboard', $tenant, $failed ? 'failure' : 'success',
            "Offboarded {$u['displayName']} ({$upn})".($failed ? ": {$failed} step(s) failed" : '').'. '.($in['reason'] ?? ''), null, ['run' => $run->id]);
        if ($failed) {
            Notifier::send('change_failed', "Offboarding of {$upn}: {$failed} step(s) failed", 'Review the offboarding run and complete the failed steps manually.', $tenant);
        }

        return $run;
    }
}
