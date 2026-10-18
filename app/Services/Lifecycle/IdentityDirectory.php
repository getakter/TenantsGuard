<?php

namespace App\Services\Lifecycle;

use App\Models\Tenant;
use App\Services\Graph\GraphException;
use App\Services\Scanner;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * Read-only directory views for Identity management: users, guests and groups of one client,
 * enriched with licences, MFA registration, admin status, sign-in activity and group membership counts.
 * Cached for 10 minutes per client; "Refresh" re-reads Microsoft Graph.
 */
class IdentityDirectory
{
    public const STALE_DAYS = 90;

    public function __construct(private readonly Tenant $tenant) {}

    private function key(string $what): string
    {
        return "tg:idir:{$what}:".$this->tenant->id;
    }

    public function forget(): void
    {
        foreach (['people', 'groups'] as $w) {
            Cache::forget($this->key($w));
        }
        Cache::forget('tg:people:'.$this->tenant->id); // top-bar search cache
    }

    /** @return array{users: array, guests: array, errors: array, read_at: string} */
    public function people(): array
    {
        if ($this->tenant->isDemo()) {
            Cache::forget($this->key('people'));
        }

        return Cache::remember($this->key('people'), now()->addMinutes(10), function () {
            $ctx = Scanner::context($this->tenant);
            $g = $ctx->graph;
            $errors = [];
            $users = $g->getAll('/users?$select=id,displayName,userPrincipalName,mail,accountEnabled,userType,jobTitle,department,assignedLicenses,createdDateTime,signInActivity,externalUserState,externalUserStateChangeDateTime,creationType,onPremisesSyncEnabled,usageLocation&$top=999');
            try {
                $skus = collect((new Directory($this->tenant))->licences())->pluck('name', 'skuId');
            } catch (Throwable) {
                $skus = collect();
                $errors[] = 'Licence names could not be read.';
            }
            try {
                $reg = collect($ctx->registration())->keyBy('id');
            } catch (Throwable) {
                $reg = collect();
                $errors[] = 'MFA registration could not be read (AuditLog.Read.All, Entra ID P1).';
            }

            $out = ['users' => [], 'guests' => []];
            foreach ($users as $u) {
                $last = $u['signInActivity']['lastSignInDateTime'] ?? null;
                $days = $last ? (int) Carbon::parse($last)->diffInDays(now()) : null;
                $created = $u['createdDateTime'] ?? null;
                $r = $reg[$u['id']] ?? null;
                $row = [
                    'id' => $u['id'], 'name' => $u['displayName'] ?? '', 'upn' => $u['userPrincipalName'] ?? '', 'mail' => $u['mail'] ?? null,
                    'enabled' => (bool) ($u['accountEnabled'] ?? true), 'title' => $u['jobTitle'] ?? null, 'department' => $u['department'] ?? null,
                    'licences' => array_values(array_map(fn ($l) => $skus[$l['skuId']] ?? 'Unknown licence', $u['assignedLicenses'] ?? [])),
                    'created' => $created, 'last_signin' => $last, 'days_since' => $days,
                    'stale' => $last ? $days > self::STALE_DAYS : ($created && Carbon::parse($created)->lt(now()->subDays(self::STALE_DAYS))),
                    'synced' => (bool) ($u['onPremisesSyncEnabled'] ?? false),
                    'mfa' => $r ? (bool) ($r['isMfaRegistered'] ?? false) : null,
                    'methods' => $r['methodsRegistered'] ?? [], 'admin' => (bool) ($r['isAdmin'] ?? false),
                ];
                if (strtolower($u['userType'] ?? 'Member') === 'guest') {
                    $email = $u['mail'] ?? str_replace('_', '@', explode('#EXT#', $row['upn'])[0]);
                    $row += ['email' => $email, 'domain' => strtolower(substr(strrchr((string) $email, '@') ?: '', 1)),
                        'state' => $u['externalUserState'] ?? 'Unknown', 'state_changed' => $u['externalUserStateChangeDateTime'] ?? null];
                    $out['guests'][] = $row;
                } else {
                    $out['users'][] = $row;
                }
            }
            usort($out['users'], fn ($a, $b) => strcasecmp($a['name'], $b['name']));
            usort($out['guests'], fn ($a, $b) => strcasecmp($a['name'], $b['name']));

            return $out + ['errors' => $errors, 'read_at' => now()->toIso8601String()];
        });
    }

    /** @return array{groups: array, read_at: string} */
    public function groups(): array
    {
        if ($this->tenant->isDemo()) {
            Cache::forget($this->key('groups'));
        }

        return Cache::remember($this->key('groups'), now()->addMinutes(10), function () {
            $g = Scanner::context($this->tenant)->graph;
            $groups = [];
            foreach (array_slice($g->getAll('/groups?$select=id,displayName,description,mail,mailEnabled,securityEnabled,groupTypes,visibility,resourceProvisioningOptions,onPremisesSyncEnabled,createdDateTime,membershipRule&$top=999'), 0, 500) as $x) {
                $types = $x['groupTypes'] ?? [];
                $dynamic = in_array('DynamicMembership', $types, true);
                $unified = in_array('Unified', $types, true);
                $team = in_array('Team', $x['resourceProvisioningOptions'] ?? [], true);
                $count = function (string $rel) use ($g, $x) {
                    try {
                        return count($g->getAll('/groups/'.$x['id'].'/'.$rel.'?$select=id&$top=999'));
                    } catch (GraphException) {
                        return null;
                    }
                };
                $groups[] = [
                    'id' => $x['id'], 'name' => $x['displayName'] ?? '', 'description' => $x['description'] ?? null, 'mail' => $x['mail'] ?? null,
                    'kind' => match (true) {
                        $team => 'Team', $unified => 'Microsoft 365', $dynamic && ! $unified => 'Dynamic security',
                        ($x['mailEnabled'] ?? false) && ($x['securityEnabled'] ?? false) => 'Mail-enabled security',
                        ($x['mailEnabled'] ?? false) => 'Distribution list', default => 'Security',
                    },
                    'dynamic' => $dynamic, 'visibility' => $unified ? ($x['visibility'] ?? 'Public') : null,
                    'synced' => (bool) ($x['onPremisesSyncEnabled'] ?? false), 'created' => $x['createdDateTime'] ?? null,
                    'members' => $count('members'), 'owners' => $unified || ! ($x['mailEnabled'] ?? false) ? $count('owners') : null,
                    'rule' => $x['membershipRule'] ?? null,
                ];
            }
            usort($groups, fn ($a, $b) => strcasecmp($a['name'], $b['name']));

            return ['groups' => $groups, 'read_at' => now()->toIso8601String()];
        });
    }

    public function group(string $id): array
    {
        $g = Scanner::context($this->tenant)->graph;
        $info = collect($this->groups()['groups'])->firstWhere('id', $id) ?? abort(404);
        $map = fn (array $list) => array_values(array_map(fn ($m) => ['id' => $m['id'], 'name' => $m['displayName'] ?? $m['id'], 'upn' => $m['userPrincipalName'] ?? ($m['mail'] ?? ''),
            'type' => str_replace('#microsoft.graph.', '', $m['@odata.type'] ?? 'user'), 'guest' => strtolower($m['userType'] ?? '') === 'guest'], $list));

        return $info + [
            'member_list' => $map($g->getAll('/groups/'.$id.'/members?$select=id,displayName,userPrincipalName,mail,userType&$top=999')),
            'owner_list' => $map($g->getAll('/groups/'.$id.'/owners?$select=id,displayName,userPrincipalName,mail,userType&$top=999')),
        ];
    }
}
