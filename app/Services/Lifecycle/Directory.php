<?php

namespace App\Services\Lifecycle;

use App\Models\Tenant;
use App\Services\Graph\GraphClientInterface;
use App\Services\Graph\GraphException;
use App\Services\Scanner;

/** Read model of a client's directory for staff onboarding and offboarding. */
class Directory
{
    public readonly GraphClientInterface $graph;

    /** Friendly licence names for common SKUs. */
    public const SKU_NAMES = [
        'SPB' => 'Microsoft 365 Business Premium',
        'Microsoft_365_Copilot' => 'Microsoft 365 Copilot',
        'Microsoft_365_Copilot_EDU' => 'Microsoft 365 Copilot (education)',
        'O365_BUSINESS_PREMIUM' => 'Microsoft 365 Business Standard',
        'O365_BUSINESS_ESSENTIALS' => 'Microsoft 365 Business Basic',
        'O365_BUSINESS' => 'Microsoft 365 Apps for business',
        'SPE_E3' => 'Microsoft 365 E3',
        'SPE_E5' => 'Microsoft 365 E5',
        'SPE_F1' => 'Microsoft 365 F3',
        'ENTERPRISEPACK' => 'Office 365 E3',
        'ENTERPRISEPREMIUM' => 'Office 365 E5',
        'STANDARDPACK' => 'Office 365 E1',
        'DESKLESSPACK' => 'Office 365 F3',
        'EXCHANGESTANDARD' => 'Exchange Online (Plan 1)',
        'EXCHANGEENTERPRISE' => 'Exchange Online (Plan 2)',
        'EMS' => 'Enterprise Mobility + Security E3',
        'EMSPREMIUM' => 'Enterprise Mobility + Security E5',
        'INTUNE_A' => 'Microsoft Intune Plan 1',
        'AAD_PREMIUM' => 'Microsoft Entra ID P1',
        'AAD_PREMIUM_P2' => 'Microsoft Entra ID P2',
        'ATP_ENTERPRISE' => 'Defender for Office 365 (Plan 1)',
        'MDE_SMB' => 'Defender for Business',
        'POWER_BI_PRO' => 'Power BI Pro',
        'VISIOCLIENT' => 'Visio Plan 2',
        'PROJECTPROFESSIONAL' => 'Project Plan 3',
        'MCOEV' => 'Teams Phone Standard',
        'Microsoft_Teams_Premium' => 'Teams Premium',
        'DEVELOPERPACK_E5' => 'Microsoft 365 E5 (developer)',
        'DEVELOPERPACK' => 'Office 365 E3 (developer)',
    ];

    /** Free and trial SKUs that would only clutter the licence picker. */
    private const HIDDEN_SKUS = ['FLOW_FREE', 'POWER_BI_STANDARD', 'TEAMS_EXPLORATORY', 'WINDOWS_STORE', 'MICROSOFT_BUSINESS_CENTER', 'CCIBOTS_PRIVPREV_VIRAL', 'POWERAPPS_VIRAL', 'STREAM', 'RIGHTSMANAGEMENT_ADHOC', 'MCOPSTNC', 'POWERAPPS_DEV', 'Power_Pages_vTrial_for_Makers', 'POWERAPPS_VIRAL', 'FLOW_PER_USER_TRIAL'];

    public function __construct(public readonly Tenant $tenant)
    {
        $this->graph = Scanner::context($tenant)->graph;
    }

    public function domains(): array
    {
        return collect($this->graph->getAll('/domains'))
            ->filter(fn ($d) => $d['isVerified'] ?? false)
            ->sortByDesc(fn ($d) => ($d['isDefault'] ?? false) ? 1 : 0)
            ->pluck('id')->values()->all();
    }

    public function country(): string
    {
        try {
            return (string) ($this->graph->get('/organization')['value'][0]['countryLetterCode'] ?? 'US') ?: 'US';
        } catch (GraphException) {
            return 'US';
        }
    }

    /** @return array<int, array{skuId: string, part: string, name: string, total: int, used: int, available: int}> */
    public function licences(): array
    {
        return collect($this->graph->getAll('/subscribedSkus'))
            ->filter(fn ($s) => ($s['capabilityStatus'] ?? '') === 'Enabled' && ! in_array($s['skuPartNumber'], self::HIDDEN_SKUS, true))
            ->map(fn ($s) => [
                'skuId' => $s['skuId'],
                'part' => $s['skuPartNumber'],
                'name' => self::SKU_NAMES[$s['skuPartNumber']] ?? $s['skuPartNumber'],
                'total' => (int) ($s['prepaidUnits']['enabled'] ?? 0),
                'used' => (int) ($s['consumedUnits'] ?? 0),
                'available' => max(0, (int) ($s['prepaidUnits']['enabled'] ?? 0) - (int) ($s['consumedUnits'] ?? 0)),
            ])->sortBy('name')->values()->all();
    }

    /** Groups with a kind label and whether membership can be managed through Graph. */
    public function groups(): array
    {
        return collect($this->graph->getAll('/groups?$select=id,displayName,description,groupTypes,mailEnabled,securityEnabled,onPremisesSyncEnabled,resourceProvisioningOptions&$top=999'))
            ->map(function ($g) {
                $types = $g['groupTypes'] ?? [];
                $dynamic = in_array('DynamicMembership', $types, true);
                $unified = in_array('Unified', $types, true);
                $team = in_array('Team', $g['resourceProvisioningOptions'] ?? [], true);
                $kind = match (true) {
                    $dynamic => 'Dynamic',
                    $team => 'Team',
                    $unified => 'Microsoft 365 group',
                    ($g['mailEnabled'] ?? false) && ($g['securityEnabled'] ?? false) => 'Mail-enabled security',
                    ($g['mailEnabled'] ?? false) => 'Distribution list',
                    default => 'Security group',
                };
                $assignable = ! $dynamic && ! ($g['onPremisesSyncEnabled'] ?? false) && ($unified || ! ($g['mailEnabled'] ?? false));

                return ['id' => $g['id'], 'name' => $g['displayName'], 'kind' => $kind, 'assignable' => $assignable,
                    'why' => $assignable ? null : ($dynamic ? 'Membership is rule-based' : (($g['onPremisesSyncEnabled'] ?? false) ? 'Synced from on-premises AD' : 'Manage in Exchange admin center'))];
            })->sortBy('name')->values()->all();
    }

    /** Enabled and disabled member accounts. */
    public function users(): array
    {
        return collect($this->graph->getAll('/users?$select=id,displayName,userPrincipalName,accountEnabled,jobTitle,department,userType,assignedLicenses&$top=999'))
            ->filter(fn ($u) => ($u['userType'] ?? 'Member') === 'Member')
            ->sortBy('displayName')->values()->all();
    }

    public function user(string $id): array
    {
        return $this->graph->get('/users/'.rawurlencode($id));
    }

    /** Everything a leaver has access to, captured before removal (audit and restore). */
    public function snapshot(string $userId, array $licences = []): array
    {
        $user = $this->user($userId);
        $memberOf = $this->tryAll('/users/'.$userId.'/memberOf');
        $skuNames = collect($licences ?: $this->licences())->pluck('name', 'skuId');

        return [
            'user' => array_intersect_key($user, array_flip(['id', 'displayName', 'userPrincipalName', 'jobTitle', 'department', 'accountEnabled', 'usageLocation'])),
            'licences' => array_map(fn ($l) => ['skuId' => $l['skuId'], 'name' => $skuNames[$l['skuId']] ?? $l['skuId']], $user['assignedLicenses'] ?? []),
            'groups' => array_values(array_map(fn ($g) => ['id' => $g['id'], 'name' => $g['displayName'], 'dynamic' => in_array('DynamicMembership', $g['groupTypes'] ?? [], true)],
                array_filter($memberOf, fn ($x) => ($x['@odata.type'] ?? '') === '#microsoft.graph.group'))),
            'roles' => array_values(array_map(fn ($r) => ['id' => $r['id'], 'name' => $r['displayName'], 'template' => $r['roleTemplateId'] ?? null],
                array_filter($memberOf, fn ($x) => ($x['@odata.type'] ?? '') === '#microsoft.graph.directoryRole'))),
            'devices' => array_map(fn ($d) => ['id' => $d['id'], 'name' => $d['deviceName'] ?? '?', 'os' => $d['operatingSystem'] ?? '', 'owner' => $d['managedDeviceOwnerType'] ?? 'unknown'],
                $this->tryAll("/deviceManagement/managedDevices?\$filter=userId eq '{$userId}'")),
            'methods' => array_values(array_filter(array_map(fn ($m) => ['id' => $m['id'], 'type' => $m['@odata.type'] ?? ''], $this->tryAll('/users/'.$userId.'/authentication/methods')),
                fn ($m) => $m['type'] !== '#microsoft.graph.passwordAuthenticationMethod')),
            'owned' => array_values(array_map(fn ($g) => ['id' => $g['id'], 'name' => $g['displayName'] ?? $g['id']],
                array_filter($this->tryAll('/users/'.$userId.'/ownedObjects'), fn ($o) => ($o['@odata.type'] ?? '#microsoft.graph.group') === '#microsoft.graph.group'))),
            'rules' => array_map(fn ($r) => ['id' => $r['id'], 'name' => $r['displayName'] ?? 'Rule',
                'forwards' => ! empty($r['actions']['forwardTo']) || ! empty($r['actions']['redirectTo']) || ! empty($r['actions']['forwardAsAttachmentTo'])],
                $this->tryAll('/users/'.$userId.'/mailFolders/inbox/messageRules')),
        ];
    }

    private function tryAll(string $path): array
    {
        try {
            return $this->graph->getAll($path);
        } catch (GraphException) {
            return [];
        }
    }

    public static function password(int $length = 16): string
    {
        $sets = ['ABCDEFGHJKLMNPQRSTUVWXYZ', 'abcdefghijkmnopqrstuvwxyz', '23456789', '!@#$%*?-+='];
        $chars = array_map(fn ($set) => $set[random_int(0, strlen($set) - 1)], $sets);
        $all = implode('', $sets);
        while (count($chars) < $length) {
            $chars[] = $all[random_int(0, strlen($all) - 1)];
        }
        shuffle($chars);

        return implode('', $chars);
    }
}
