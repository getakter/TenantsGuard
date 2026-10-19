<?php

namespace App\Services\Monitoring;

use App\Models\ActivityLog;
use App\Models\SecurityAlert;
use App\Models\Setting;
use App\Models\Tenant;
use App\Services\Checks\Roles;
use App\Services\Graph\GraphClientInterface;
use App\Services\Graph\GraphException;
use App\Services\Notifier;
use App\Services\Scanner;
use App\Support\MicrosoftApp;
use Carbon\Carbon;
use Illuminate\Support\Str;
use Throwable;

/**
 * Near-real-time security monitoring of a client tenant (read-only).
 *
 * Event rules read the Entra audit and sign-in logs since the last pass and raise one alert per event.
 * State rules (risky users, Defender alerts, service incidents) mirror the current state: their alerts
 * clear themselves when the condition clears in Microsoft 365. Changes made by TenantsGuard itself are
 * ignored. A rule that the tenant is not licensed or permitted for is reported as unavailable; the
 * other rules still run.
 */
class SecurityMonitor
{
    /** key => [name, description, default severity, source / requirement] */
    public const RULES = [
        'role_assignment' => ['Privileged role assigned', 'Someone was added to an Entra admin role, permanently or as eligible.', 'high', 'Entra audit log'],
        'ca_change' => ['Conditional Access changed', 'A Conditional Access policy was created, changed or deleted.', 'medium', 'Entra audit log'],
        'app_consent' => ['Application consent', 'A user or admin granted an application access to company data.', 'medium', 'Entra audit log'],
        'app_credential' => ['App credential added', 'A secret or certificate was added to an application or service principal (a common persistence technique).', 'high', 'Entra audit log'],
        'federation_change' => ['Domain federation changed', 'Domain authentication or federation settings changed. Attackers use this to forge sign-ins.', 'critical', 'Entra audit log'],
        'mfa_removed' => ['MFA method removed by an admin', 'An administrator deleted a user\'s authentication methods.', 'medium', 'Entra audit log'],
        'break_glass_signin' => ['Break-glass account used', 'An emergency access account signed in or tried to.', 'critical', 'Entra sign-in log (Entra ID P1)'],
        'risky_user' => ['High-risk user', 'Entra ID Protection rates a user as high risk.', 'high', 'Entra ID Protection (Entra ID P2)'],
        'defender_alert' => ['Microsoft Defender alert', 'An active alert from Microsoft Defender XDR or Entra ID Protection.', 'medium', 'Defender (SecurityAlert.Read.All)'],
        'service_incident' => ['Microsoft 365 service incident', 'Microsoft reports an active service incident that can affect this client.', 'medium', 'Service health (ServiceHealth.Read.All)'],
    ];

    /** Rules whose alerts clear automatically when the source clears. */
    private const STATE_RULES = ['risky_user', 'defender_alert', 'service_incident'];

    private GraphClientInterface $graph;

    private array $status = [];

    private int $new = 0;

    private int $cleared = 0;

    public function __construct(private readonly Tenant $tenant) {}

    /** Rules switched on for this tenant. */
    public static function enabledRules(Tenant $tenant): array
    {
        $off = $tenant->monitoring['disabled_rules'] ?? [];

        return array_values(array_diff(array_keys(self::RULES), $off));
    }

    public static function breakGlass(Tenant $tenant): array
    {
        return array_values(array_filter(array_map('strtolower', $tenant->monitoring['break_glass'] ?? [])));
    }

    /** @return array{new: int, cleared: int, status: array} */
    public function run(): array
    {
        if (! $this->tenant->isReady()) {
            return ['new' => 0, 'cleared' => 0, 'status' => []];
        }
        $this->graph = Scanner::context($this->tenant)->graph;
        $since = $this->tenant->monitored_at
            ? Carbon::parse($this->tenant->monitored_at)->subMinutes(10)->max(now()->subDays(7))
            : now()->subDay();
        $rules = self::enabledRules($this->tenant);

        if (array_intersect($rules, ['role_assignment', 'ca_change', 'app_consent', 'app_credential', 'federation_change', 'mfa_removed'])) {
            $this->guard(['role_assignment', 'ca_change', 'app_consent', 'app_credential', 'federation_change', 'mfa_removed'], fn () => $this->audits($since, $rules));
        }
        if (in_array('break_glass_signin', $rules, true)) {
            $this->guard(['break_glass_signin'], fn () => $this->breakGlassSignIns($since));
        }
        if (in_array('risky_user', $rules, true)) {
            $this->guard(['risky_user'], fn () => $this->riskyUsers());
        }
        if (in_array('defender_alert', $rules, true)) {
            $this->guard(['defender_alert'], fn () => $this->defenderAlerts());
        }
        if (in_array('service_incident', $rules, true)) {
            $this->guard(['service_incident'], fn () => $this->serviceIncidents());
        }

        $this->tenant->forceFill(['monitored_at' => now(), 'monitor_status' => $this->status])->saveQuietly();

        return ['new' => $this->new, 'cleared' => $this->cleared, 'status' => $this->status];
    }

    /** Run a rule group; a permission or licence gap marks it unavailable instead of failing the pass. */
    private function guard(array $rules, callable $fn): void
    {
        try {
            $fn();
            foreach ($rules as $r) {
                $this->status[$r] = 'ok';
            }
        } catch (GraphException $e) {
            $why = $e->isPermissionOrLicense()
                ? 'Not available: '.self::RULES[$rules[0]][3].' is not licensed or the permission is not granted.'
                : 'Could not read: '.Str::limit($e->getMessage(), 160);
            foreach ($rules as $r) {
                $this->status[$r] = $why;
            }
        } catch (Throwable $e) {
            report($e);
            foreach ($rules as $r) {
                $this->status[$r] = 'Could not read: '.Str::limit($e->getMessage(), 160);
            }
        }
    }

    // ------------------------------------------------------------------ event rules

    private function audits(Carbon $since, array $rules): void
    {
        $ourApp = (string) MicrosoftApp::clientId();
        $events = $this->graph->getAll('/auditLogs/directoryAudits?$filter='.rawurlencode('activityDateTime ge '.$since->copy()->utc()->format('Y-m-d\TH:i:s\Z')).'&$top=999');

        // TenantsGuard's own app in this tenant: app ID, service principal and (home tenant) application object.
        $ourIds = array_values(array_filter([$ourApp, Setting::find('ms_app_object_id')?->value, $ourApp !== '' ? $this->ourServicePrincipal($ourApp) : null]));
        $grants = [];
        foreach ($events as $e) {
            $activity = (string) ($e['activityDisplayName'] ?? '');
            $app = $e['initiatedBy']['app'] ?? null;
            if ($ourApp !== '' && ($app['appId'] ?? null) === $ourApp) {
                continue; // TenantsGuard's own, audited change
            }
            $by = $e['initiatedBy']['user']['userPrincipalName'] ?? ($app['displayName'] ?? 'Unknown');
            $target = $e['targetResources'][0] ?? [];
            $targetName = $target['userPrincipalName'] ?? $target['displayName'] ?? 'unknown';
            $props = collect($e['targetResources'] ?? [])->flatMap(fn ($t) => $t['modifiedProperties'] ?? [])
                ->mapWithKeys(fn ($p) => [$p['displayName'] ?? '' => trim((string) ($p['newValue'] ?? ''), '"[] ')]);
            $targetsJson = json_encode($e['targetResources'] ?? []);
            $aboutUs = (bool) array_filter($ourIds, fn ($id) => str_contains($targetsJson, $id));
            // Role events list the role and the member in either order (PIM vs. directory).
            $member = collect($e['targetResources'] ?? [])->first(fn ($t) => ! empty($t['userPrincipalName']) || in_array($t['type'] ?? '', ['User', 'ServicePrincipal', 'Group'], true));
            $roleTarget = collect($e['targetResources'] ?? [])->first(fn ($t) => ($t['type'] ?? '') === 'Role');
            $result = ($e['result'] ?? 'success') === 'success' ? '' : ' (attempt failed)';

            // Application permissions: one audit event per role, grouped per consent below.
            if ($activity === 'Add app role assignment to service principal') {
                $spApp = $props['ServicePrincipal.AppId'] ?? '';
                if ($spApp !== '' && $spApp !== $ourApp && ! empty($props['AppRole.Value'])) {
                    $k = ($e['correlationId'] ?? $e['id']).'|'.$spApp;
                    $grants[$k] ??= ['app' => $props['ServicePrincipal.DisplayName'] ?? $spApp, 'app_id' => $spApp, 'by' => $by, 'at' => $e['activityDateTime'] ?? null, 'roles' => []];
                    $grants[$k]['roles'][] = $props['AppRole.Value'];
                }

                continue;
            }

            [$rule, $severity, $title] = match (true) {
                str_starts_with($activity, 'Add member to role') || str_starts_with($activity, 'Add eligible member to role') => (function () use ($props, $member, $roleTarget, $activity) {
                    $tpl = $props['Role.TemplateId'] ?? $props['RoleDefinitionOriginId'] ?? '';
                    $role = $props['Role.DisplayName'] ?? Roles::PRIVILEGED[$tpl] ?? $roleTarget['displayName'] ?? ($tpl ?: 'an admin role');
                    $targetName = $member['userPrincipalName'] ?? $member['displayName'] ?? 'Someone';
                    $tier0 = (bool) preg_match('/Global Administrator|Privileged Role Administrator|Privileged Authentication Administrator|Security Administrator|Conditional Access Administrator|Application Administrator|Hybrid Identity/i', $role);

                    return ['role_assignment', $tier0 ? 'critical' : 'high', "{$targetName} was ".(str_contains($activity, 'eligible') ? 'made eligible for' : 'added to')." {$role}"];
                })(),
                in_array($activity, ['Add conditional access policy', 'Update conditional access policy', 'Delete conditional access policy'], true) => [
                    'ca_change', str_starts_with($activity, 'Delete') ? 'high' : 'medium',
                    'Conditional Access policy "'.($target['displayName'] ?? 'unknown').'" '.strtolower(str_replace(' conditional access policy', '', $activity)).'d',
                ],
                $activity === 'Consent to application' && $aboutUs => [null, null, null], // consent to TenantsGuard itself: tracked under Permission scope
                $activity === 'Consent to application' => [
                    'app_consent', strcasecmp($props['ConsentContext.IsAdminConsent'] ?? '', 'True') === 0 ? 'high' : 'medium',
                    (strcasecmp($props['ConsentContext.IsAdminConsent'] ?? '', 'True') === 0 ? 'Admin consent' : 'User consent').' granted to "'.($target['displayName'] ?? 'an application').'"',
                ],
                (str_contains($activity, 'Certificates and secrets management') || $activity === 'Add service principal credentials') && $aboutUs => [
                    'app_credential', 'critical', 'Credential added to the TenantsGuard app itself',
                ],
                str_contains($activity, 'Certificates and secrets management') || $activity === 'Add service principal credentials' => [
                    'app_credential', 'high', 'Credential added to "'.($target['displayName'] ?? 'an application').'"',
                ],
                in_array($activity, ['Set domain authentication', 'Set federation settings on domain', 'Update domain'], true) && ($activity !== 'Update domain' || isset($props['AuthenticationType']) || isset($props['LiveType'])) => [
                    'federation_change', 'critical', 'Authentication settings of domain '.($target['displayName'] ?? '').' changed',
                ],
                in_array($activity, ['Admin deleted security info', 'Admin deleted all security info'], true) => [
                    'mfa_removed', 'medium', "MFA methods of {$targetName} were removed by an admin",
                ],
                default => [null, null, null],
            };
            if (! $rule || ! in_array($rule, $rules, true)) {
                continue;
            }
            // One action can be logged twice (directory and PIM): one alert per member, role and day.
            $key = $rule === 'role_assignment'
                ? 'role:'.strtolower($title).'|'.Carbon::parse($e['activityDateTime'] ?? now())->format('Y-m-d')
                : (string) ($e['id'] ?? md5(json_encode($e)));
            $why = $rule === 'app_credential' && $aboutUs
                ? ' A new secret or certificate on the TenantsGuard app gives access to every client. If you did not just renew it in Settings, revoke it now and investigate.'
                : '';
            $this->raise($rule, $key, $severity, $title.$result,
                "By {$by} at ".Carbon::parse($e['activityDateTime'] ?? now())->toDayDateTimeString().' UTC. Activity: '.trim($activity).'.'.$why,
                ['activity' => $activity, 'initiated_by' => $by, 'target' => $targetName, 'properties' => $props->filter()->take(8)->all(), 'correlation_id' => $e['correlationId'] ?? null],
                $e['activityDateTime'] ?? null);
        }

        if (in_array('app_consent', $rules, true)) {
            foreach ($grants as $k => $g) {
                $roles = array_values(array_unique($g['roles']));
                $sensitive = array_values(array_filter($roles, fn ($r) => preg_match('/ReadWrite|\.Send|FullControl|full_access_as_app|RoleManagement|AppRoleAssignment|Mail\.Read|Files\.Read\.All|Sites\.Read\.All|Directory\.Read\.All|UserAuthenticationMethod|PrivilegedOperations/i', $r)));
                $this->raise('app_consent', 'grant:'.$k, $sensitive ? 'high' : 'medium',
                    'Application permissions granted to "'.$g['app'].'"',
                    'By '.$g['by'].'. '.count($roles).' application permission(s): '.implode(', ', array_slice($roles, 0, 12)).(count($roles) > 12 ? ' …' : '').'. Application permissions work without a signed-in user; confirm the app is known and needs them.',
                    ['initiated_by' => $g['by'], 'app_id' => $g['app_id'], 'sensitive' => $sensitive ? implode(', ', array_slice($sensitive, 0, 10)) : null, 'permissions' => count($roles)], $g['at']);
            }
        }
    }

    private function ourServicePrincipal(string $appId): ?string
    {
        try {
            return $this->graph->get("/servicePrincipals(appId='{$appId}')?\$select=id")['id'] ?? null;
        } catch (Throwable) {
            return null;
        }
    }

    private function breakGlassSignIns(Carbon $since): void
    {
        $accounts = self::breakGlass($this->tenant);
        if (! $accounts) {
            $this->status['break_glass_signin'] = 'ok';

            return;
        }
        foreach ($accounts as $upn) {
            $filter = 'createdDateTime ge '.$since->copy()->utc()->format('Y-m-d\TH:i:s\Z')." and userPrincipalName eq '".str_replace("'", "''", $upn)."'";
            foreach ($this->graph->getAll('/auditLogs/signIns?$filter='.rawurlencode($filter).'&$top=100') as $s) {
                $ok = (int) ($s['status']['errorCode'] ?? 0) === 0;
                $where = trim(($s['location']['city'] ?? '').', '.($s['location']['countryOrRegion'] ?? ''), ', ');
                $this->raise('break_glass_signin', (string) ($s['id'] ?? md5(json_encode($s))), $ok ? 'critical' : 'high',
                    "Break-glass account {$upn} ".($ok ? 'signed in' : 'failed to sign in'),
                    'From '.($s['ipAddress'] ?? 'unknown IP').($where ? " ({$where})" : '').' to '.($s['appDisplayName'] ?? 'an application').'. Confirm this was a planned emergency or test; otherwise treat it as a compromise.',
                    ['ip' => $s['ipAddress'] ?? null, 'location' => $where, 'app' => $s['appDisplayName'] ?? null, 'error' => $s['status']['failureReason'] ?? null],
                    $s['createdDateTime'] ?? null);
            }
        }
    }

    // ------------------------------------------------------------------ state rules

    private function riskyUsers(): void
    {
        $seen = [];
        foreach ($this->graph->getAll("/identityProtection/riskyUsers?\$filter=riskLevel eq 'high'") as $u) {
            if (! in_array($u['riskState'] ?? '', ['atRisk', 'confirmedCompromised'], true)) {
                continue;
            }
            $seen[] = $key = (string) $u['id'];
            $this->raise('risky_user', $key, ($u['riskState'] ?? '') === 'confirmedCompromised' ? 'critical' : 'high',
                ($u['userPrincipalName'] ?? 'A user').' is at high risk',
                'Risk detail: '.($u['riskDetail'] ?? 'none').'. Reset the password and revoke sessions, or confirm safe in Entra ID Protection.',
                ['risk_state' => $u['riskState'] ?? null, 'risk_detail' => $u['riskDetail'] ?? null], $u['riskLastUpdatedDateTime'] ?? null);
        }
        $this->clearMissing('risky_user', $seen);
    }

    private function defenderAlerts(): void
    {
        $map = ['high' => 'high', 'medium' => 'medium', 'low' => 'low'];
        $since = now()->subDays(30)->utc()->format('Y-m-d\TH:i:s\Z');
        foreach ($this->graph->getAll('/security/alerts_v2?$filter='.rawurlencode("createdDateTime ge {$since}").'&$top=200') as $a) {
            $key = (string) $a['id'];
            if (($a['status'] ?? '') === 'resolved') {
                $this->clear('defender_alert', $key);

                continue;
            }
            if (! isset($map[$a['severity'] ?? ''])) {
                continue; // informational
            }
            $this->raise('defender_alert', $key, $map[$a['severity']], (string) ($a['title'] ?? 'Defender alert'),
                trim(($a['description'] ?? '').' Source: '.($a['serviceSource'] ?? 'Microsoft Defender').'.'),
                ['category' => $a['category'] ?? null, 'status' => $a['status'] ?? null, 'url' => $a['alertWebUrl'] ?? null, 'incident' => $a['incidentId'] ?? null],
                $a['createdDateTime'] ?? null);
        }
    }

    private function serviceIncidents(): void
    {
        $seen = [];
        foreach ($this->graph->getAll('/admin/serviceAnnouncement/issues?$filter='.rawurlencode('isResolved eq false')) as $i) {
            if (($i['classification'] ?? '') !== 'incident') {
                continue;
            }
            $seen[] = $key = (string) $i['id'];
            $this->raise('service_incident', $key, 'medium', ($i['service'] ?? 'Microsoft 365').': '.($i['title'] ?? $key),
                (string) ($i['impactDescription'] ?? ''), ['id' => $key, 'service' => $i['service'] ?? null, 'feature' => $i['feature'] ?? null, 'status' => $i['status'] ?? null],
                $i['startDateTime'] ?? null);
        }
        $this->clearMissing('service_incident', $seen);
    }

    // ------------------------------------------------------------------ persistence

    private function raise(string $rule, string $key, string $severity, string $title, string $detail, array $evidence, ?string $at): void
    {
        $alert = SecurityAlert::firstOrNew(['tenant_id' => $this->tenant->id, 'rule' => $rule, 'dedupe_key' => Str::limit($key, 180, '')]);
        $isNew = ! $alert->exists;
        $reopened = ! $isNew && $alert->status === 'resolved' && $alert->resolution === 'cleared_at_source' && in_array($rule, self::STATE_RULES, true);
        if (! $isNew && ! $reopened) {
            $alert->fill(['last_seen_at' => now(), 'severity' => $severity, 'detail' => Str::limit($detail, 2000), 'evidence' => $evidence]);
            if (! in_array($rule, self::STATE_RULES, true)) {
                $alert->occurrences++;
            }
            $alert->save();

            return;
        }
        $alert->fill([
            'severity' => $severity, 'title' => Str::limit($title, 250), 'detail' => Str::limit($detail, 2000), 'evidence' => $evidence,
            'status' => 'new', 'occurred_at' => $at ? Carbon::parse($at) : now(), 'last_seen_at' => now(),
            'resolved_at' => null, 'resolved_by' => null, 'resolution' => null,
        ])->save();
        $this->new++;

        ActivityLog::record('alert.raised', $this->tenant, in_array($severity, ['critical', 'high'], true) ? 'failure' : 'info',
            '['.SecurityAlert::SEVERITIES[$severity][0].'] '.$alert->title, null, ['alert' => $alert->id, 'rule' => $rule]);
        if (in_array($severity, ['critical', 'high'], true)) {
            Notifier::send('security_alert', SecurityAlert::SEVERITIES[$severity][0].' alert: '.$alert->title, $detail, $this->tenant);
        }
    }

    private function clear(string $rule, string $key): void
    {
        $this->cleared += SecurityAlert::where('tenant_id', $this->tenant->id)->where('rule', $rule)->where('dedupe_key', $key)->open()
            ->update(['status' => 'resolved', 'resolution' => 'cleared_at_source', 'resolved_at' => now(), 'updated_at' => now()]);
    }

    private function clearMissing(string $rule, array $seen): void
    {
        $this->cleared += SecurityAlert::where('tenant_id', $this->tenant->id)->where('rule', $rule)->open()->whereNotIn('dedupe_key', $seen ?: ['-'])
            ->update(['status' => 'resolved', 'resolution' => 'cleared_at_source', 'resolved_at' => now(), 'updated_at' => now()]);
    }
}
