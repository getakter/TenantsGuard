<?php

namespace App\Services\Intune;

use App\Exceptions\TenantBusyException;
use App\Models\ActivityLog;
use App\Models\IntuneBundle;
use App\Models\IntuneDeployment;
use App\Models\Tenant;
use App\Services\Graph\GraphException;
use App\Services\Notifier;
use App\Services\Scanner;
use App\Support\TenantLock;
use Illuminate\Support\Str;
use Throwable;

/**
 * Deploys Intune templates to a tenant through Microsoft Graph.
 *
 *  - Idempotent: objects are named "TG - <template>" and matched by display name. Existing objects are
 *    left alone, or updated when "update existing" is chosen.
 *  - Safe by default: pilot-group assignment, Conditional Access in report-only mode, dry-run preview.
 *  - Licence-aware: templates whose licence is missing are skipped, not failed.
 */
class IntuneDeployer
{
    public const COLLECTIONS = [
        'compliance' => '/deviceManagement/deviceCompliancePolicies',
        'configuration' => '/deviceManagement/deviceConfigurations',
        'mam_ios' => '/deviceAppManagement/iosManagedAppProtections',
        'mam_android' => '/deviceAppManagement/androidManagedAppProtections',
        'feature_update' => '/deviceManagement/windowsFeatureUpdateProfiles',
        'autopilot' => '/beta/deviceManagement/windowsAutopilotDeploymentProfiles',
        'ca' => '/identity/conditionalAccess/policies',
    ];

    public const PERMISSIONS = [
        'compliance' => 'DeviceManagementConfiguration.ReadWrite.All',
        'configuration' => 'DeviceManagementConfiguration.ReadWrite.All',
        'feature_update' => 'DeviceManagementConfiguration.ReadWrite.All',
        'mam_ios' => 'DeviceManagementApps.ReadWrite.All',
        'mam_android' => 'DeviceManagementApps.ReadWrite.All',
        'autopilot' => 'DeviceManagementServiceConfig.ReadWrite.All',
        'ca' => 'Policy.ReadWrite.ConditionalAccess',
    ];

    public const PILOT_GROUP = 'TenantsGuard Pilot';

    private IntuneContext $ctx;

    private array $existing = [];

    private array $groups = [];

    /** Deployments stuck in "running" longer than this are marked failed (worker crashed or was killed). */
    public const STALE_MINUTES = 30;

    /**
     * Queue a deployment for the background worker. Web requests must not run it: a real tenant easily
     * needs more than PHP's request time limit.
     *
     * @param  array<int, array{template: string, params?: array, assignment?: string, group?: ?string}>  $items
     */
    public function queue(Tenant $tenant, array $items, bool $dryRun, bool $updateExisting, ?IntuneBundle $bundle, string $bundleName, ?string $batch = null): IntuneDeployment
    {
        return IntuneDeployment::create([
            'batch' => $batch ?? (string) Str::uuid(),
            'tenant_id' => $tenant->id,
            'bundle_id' => $bundle?->id,
            'bundle_name' => $bundleName,
            'user_id' => auth()->id(),
            'dry_run' => $dryRun,
            'update_existing' => $updateExisting,
            'status' => 'queued',
            'request' => ['items' => array_values($items)],
        ]);
    }

    /** Queue and run immediately in this process (control plans, tests). */
    public function run(Tenant $tenant, array $items, bool $dryRun, bool $updateExisting, ?IntuneBundle $bundle, string $bundleName, ?string $batch = null): IntuneDeployment
    {
        return $this->process($this->queue($tenant, $items, $dryRun, $updateExisting, $bundle, $bundleName, $batch));
    }

    /** Execute one queued deployment. Safe with several workers: the claim is atomic. */
    public function process(IntuneDeployment $deployment): IntuneDeployment
    {
        $claimed = IntuneDeployment::whereKey($deployment->id)->where('status', 'queued')
            ->update(['status' => 'running', 'started_at' => now(), 'updated_at' => now()]);
        if (! $claimed) {
            return $deployment->fresh('items');
        }
        $deployment->refresh();
        $tenant = $deployment->tenant;
        @set_time_limit(0);

        try {
            return TenantLock::run($tenant, fn () => $this->execute($deployment, $tenant, $deployment->request['items'] ?? []));
        } catch (TenantBusyException) {
            // Another change is running in this client: try again on the next pass.
            $deployment->update(['status' => 'queued', 'started_at' => null]);

            return $deployment;
        } catch (Throwable $e) {
            $this->record($deployment, ['template' => '-', 'display_name' => 'Deployment', 'status' => 'failed',
                'message' => 'The deployment stopped: '.($e instanceof GraphException ? $e->friendly() : $e->getMessage())]);

            return $this->finish($deployment, $tenant);
        }
    }

    /** Worker loop: close stale deployments, then run queued ones. Returns the number processed. */
    public function processQueued(int $maxSeconds = 3300): int
    {
        IntuneDeployment::where('status', 'running')
            ->where(fn ($q) => $q->where('started_at', '<', now()->subMinutes(self::STALE_MINUTES))
                ->orWhere(fn ($q) => $q->whereNull('started_at')->where('updated_at', '<', now()->subMinutes(self::STALE_MINUTES))))
            ->get()->each(fn (IntuneDeployment $d) => $this->abandon($d));

        $until = time() + $maxSeconds;
        $n = 0;
        $skipped = [];
        while (time() < $until) {
            $next = IntuneDeployment::where('status', 'queued')->whereNotIn('id', $skipped)->orderBy('id')->first();
            if (! $next) {
                break;
            }
            $done = $this->process($next);
            if ($done->status === 'queued') {
                $skipped[] = $next->id; // client busy; leave it for the next pass
            } else {
                $n++;
            }
        }

        return $n;
    }

    /** Start a detached worker so the deployment begins at once (the scheduler is the safety net). */
    public static function dispatch(): void
    {
        $mode = config('tenantsguard.intune.dispatch', 'spawn');
        if ($mode === 'sync') {
            app(self::class)->processQueued(600);

            return;
        }
        if ($mode !== 'spawn') {
            return;
        }
        $php = PHP_BINARY;
        $artisan = base_path('artisan');
        if (DIRECTORY_SEPARATOR === '\\') {
            pclose(popen('start "" /B "'.$php.'" "'.$artisan.'" intune:process > NUL 2>&1', 'r'));
        } else {
            exec(escapeshellarg($php).' '.escapeshellarg($artisan).' intune:process > /dev/null 2>&1 &');
        }
    }

    private function abandon(IntuneDeployment $d): void
    {
        $this->record($d, ['template' => '-', 'display_name' => 'Deployment', 'status' => 'failed',
            'message' => 'The deployment stopped before it finished (time limit or worker stopped). Items listed here were applied. Run the bundle again to complete it: existing policies are detected and left unchanged.']);
        $this->finish($d, $d->tenant, false);
    }

    private function execute(IntuneDeployment $deployment, Tenant $tenant, array $items): IntuneDeployment
    {
        if (! $tenant->isReady()) {
            $this->record($deployment, ['template' => '-', 'display_name' => 'Tenant', 'status' => 'failed', 'message' => 'Tenant is not connected (admin consent pending).']);

            return $this->finish($deployment, $tenant);
        }

        $this->ctx = new IntuneContext($tenant);
        $this->existing = [];
        $this->groups = [];
        $licenses = $this->ctx->licenses();

        foreach ($items as $item) {
            $template = IntuneCatalog::find($item['template'] ?? '');
            if (! $template) {
                continue;
            }
            $this->record($deployment, $this->deployOne($tenant, $template, $item, $licenses, $deployment->dry_run, $deployment->update_existing, $deployment->bundle_name));
            $deployment->touch(); // heartbeat for the progress page and stale detection
        }

        return $this->finish($deployment, $tenant);
    }

    private function deployOne(Tenant $tenant, array $t, array $item, array $licenses, bool $dryRun, bool $update, string $bundleName): array
    {
        $name = IntuneCatalog::PREFIX.$t['name'];
        $row = ['template' => $t['key'], 'display_name' => $name];

        // ---- manual prerequisites: verify, never change ----
        if ($t['kind'] === 'manual') {
            $check = $this->ctx->verify($t);

            return $row + [
                'status' => $check['ok'] === true ? 'ready' : 'manual',
                'message' => $check['detail'].($check['ok'] === true ? '' : ' Steps: '.implode(' → ', $t['steps'] ?? [])),
            ];
        }

        $params = IntuneCatalog::params($t, $item['params'] ?? []);
        $missing = IntuneCatalog::missingLicenses($t, $licenses);
        if (($params['mde_level'] ?? 'none') !== 'none' && ! ($licenses['mde'] ?? true)) {
            $missing[] = 'mde';
        }
        if ($missing) {
            return $row + ['status' => 'skipped', 'message' => 'Requires '.implode(', ', array_map(fn ($l) => IntuneCatalog::LICENSES[$l], array_unique($missing))).'.'];
        }

        $target = in_array($item['assignment'] ?? '', $t['targets'], true) ? $item['assignment'] : $t['default_target'];
        $groupName = $target === 'pilot' ? self::PILOT_GROUP : ($target === 'group' ? trim((string) ($item['group'] ?? '')) : null);
        if ($target === 'group' && $groupName === '') {
            return $row + ['status' => 'failed', 'message' => 'Assignment "Named group" needs a group name.'];
        }
        $row['assignment'] = $target === 'group' || $target === 'pilot' ? 'Group: '.$groupName : (IntuneCatalog::TARGETS[$target] ?? $target);

        try {
            $collection = self::COLLECTIONS[$t['kind']];
            $existing = $this->findExisting($collection, $name);

            if ($dryRun) {
                $groupNote = $groupName && ! $this->findGroup($groupName) ? ' Group "'.$groupName.'" will be created.' : '';
                if ($existing) {
                    return $row + ['status' => $update ? 'would_update' : 'exists', 'object_id' => $existing['id'] ?? null,
                        'message' => ($update ? 'Will update the existing policy.' : 'Already deployed. Left unchanged.').$groupNote];
                }

                return $row + ['status' => 'would_create', 'message' => 'Will be created'.($t['kind'] === 'ca' ? ' in report-only mode' : '').'.'.$groupNote];
            }

            $groupId = $groupName ? $this->ensureGroup($groupName) : null;
            $payload = ($t['build'])($params, $tenant, $groupId ? ['group_id' => $groupId] : []);
            $payload['displayName'] = $name;
            if ($t['kind'] !== 'ca') {
                $payload['description'] = Str::limit($t['summary'], 900).' Managed by TenantsGuard'.($bundleName ? " (bundle: {$bundleName})" : '').'.';
            }

            if ($existing) {
                if (! $update) {
                    return $row + ['status' => 'exists', 'object_id' => $existing['id'], 'message' => 'Already deployed. Left unchanged (enable "update existing" to apply new settings).'];
                }
                unset($payload['scheduledActionsForRule']); // not patchable on compliance policies
                $this->ctx->graph->patch($collection.'/'.$existing['id'], $payload);
                $id = $existing['id'];
                $status = 'updated';
            } else {
                $created = $this->ctx->graph->post($collection, $payload);
                $id = $created['id'] ?? null;
                $status = 'created';
            }

            if (in_array($t['kind'], ['mam_ios', 'mam_android'], true) && $id) {
                $this->ctx->graph->post($collection.'/'.$id.'/targetApps', ['apps' => [], 'appGroupType' => $params['apps'] ?? 'allCoreMicrosoftApps']);
            }
            if ($id && $t['kind'] !== 'ca' && $target !== 'none') {
                $this->assign($t['kind'], $collection, $id, $target, $groupId);
            }

            return $row + ['status' => $status, 'object_id' => $id, 'message' => match (true) {
                $t['kind'] === 'ca' => 'Created in report-only mode. Review sign-in logs, add break-glass exclusions, then enable.',
                $target === 'none' => 'Created without assignment.',
                default => ucfirst($status).' and assigned.',
            }];
        } catch (GraphException $e) {
            $msg = $e->friendly();
            // Intune sometimes returns its internal error envelope as the message: keep the useful part.
            $inner = json_decode($e->getMessage(), true);
            if (is_array($inner) && isset($inner['Message'])) {
                $msg = 'Intune returned an error: '.trim(preg_replace('/ - Operation ID.*$/s', '', $inner['Message'])).'.'
                    .($t['kind'] === 'autopilot' ? ' This usually means Windows Autopilot is not ready in this tenant yet (automatic MDM enrollment and the Intune licence must be in place), or a temporary service issue. Try again later or create the profile in the Intune portal.' : ' Try again later.');
            }
            if ($e->isPermissionOrLicense() && $e->graphCode !== 'AccessPolicy') {
                $msg .= ' Needed: '.(self::PERMISSIONS[$t['kind']] ?? 'Intune permissions').($groupName ? ' and Group.ReadWrite.All' : '').'. Update the app permissions in Settings and re-grant consent.';
            }

            return $row + ['status' => 'failed', 'message' => $msg];
        } catch (Throwable $e) {
            report($e);

            return $row + ['status' => 'failed', 'message' => $e->getMessage()];
        }
    }

    private function findExisting(string $collection, string $name): ?array
    {
        $this->existing[$collection] ??= collect($this->ctx->graph->getAll($collection))->keyBy('displayName')->all();

        return $this->existing[$collection][$name] ?? null;
    }

    private function findGroup(string $name): ?string
    {
        if (! array_key_exists($name, $this->groups)) {
            $filter = rawurlencode("displayName eq '".str_replace("'", "''", $name)."'");
            $found = $this->ctx->graph->getAll('/groups?$filter='.$filter.'&$select=id,displayName');
            $this->groups[$name] = $found[0]['id'] ?? null;
        }

        return $this->groups[$name];
    }

    private function ensureGroup(string $name): string
    {
        if ($id = $this->findGroup($name)) {
            return $id;
        }
        $created = $this->ctx->graph->post('/groups', [
            'displayName' => $name,
            'description' => 'Created by TenantsGuard for Intune policy assignment. Add pilot users and devices here.',
            'mailEnabled' => false,
            'mailNickname' => Str::slug($name).'-'.Str::lower(Str::random(4)),
            'securityEnabled' => true,
        ]);

        return $this->groups[$name] = $created['id'];
    }

    private function assign(string $kind, string $collection, string $id, string $target, ?string $groupId): void
    {
        $assignmentTarget = match ($target) {
            'all_users' => ['@odata.type' => '#microsoft.graph.allLicensedUsersAssignmentTarget'],
            'all_devices' => ['@odata.type' => '#microsoft.graph.allDevicesAssignmentTarget'],
            default => ['@odata.type' => '#microsoft.graph.groupAssignmentTarget', 'groupId' => $groupId],
        };

        if ($kind === 'autopilot') {
            $this->ctx->graph->post($collection.'/'.$id.'/assignments', ['target' => $assignmentTarget]);

            return;
        }
        $this->ctx->graph->post($collection.'/'.$id.'/assign', ['assignments' => [['target' => $assignmentTarget]]]);
    }

    private function record(IntuneDeployment $deployment, array $row): void
    {
        $deployment->items()->create([
            'tenant_id' => $deployment->tenant_id,
            'template' => $row['template'],
            'display_name' => $row['display_name'],
            'status' => $row['status'],
            'object_id' => $row['object_id'] ?? null,
            'assignment' => $row['assignment'] ?? null,
            'message' => $row['message'] ?? null,
        ]);
    }

    private function finish(IntuneDeployment $deployment, Tenant $tenant, bool $rescan = true): IntuneDeployment
    {
        IntuneInventory::forget($tenant);
        $counts = $deployment->items()->get()->countBy('status')->all();
        $failed = $counts['failed'] ?? 0;
        $changed = ($counts['created'] ?? 0) + ($counts['updated'] ?? 0);
        $deployment->update([
            'finished_at' => now(),
            'summary' => $counts,
            'status' => match (true) {
                $deployment->dry_run => 'preview',
                $failed && ! $changed && ! ($counts['exists'] ?? 0) => 'failed',
                $failed > 0 => 'partial',
                default => 'success',
            },
        ]);

        $text = collect($counts)->map(fn ($n, $s) => "{$n} {$s}")->implode(', ');
        ActivityLog::record($deployment->dry_run ? 'intune.preview' : 'intune.deploy', $tenant, $failed ? 'failure' : ($deployment->dry_run ? 'info' : 'success'),
            ($deployment->dry_run ? 'Previewed' : 'Deployed')." Intune bundle \"{$deployment->bundle_name}\": {$text}", null, ['deployment' => $deployment->id]);
        if (! $deployment->dry_run && $failed) {
            Notifier::send('change_failed', "Intune deployment \"{$deployment->bundle_name}\": {$failed} item(s) failed", $text, $tenant);
        }

        // Refresh the security posture so device-related controls reflect the new policies.
        if ($rescan && ! $deployment->dry_run && $changed) {
            try {
                app(Scanner::class)->scan($tenant, 'intune');
            } catch (Throwable) {
            }
        }

        return $deployment->fresh('items');
    }
}
