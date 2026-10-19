<?php

namespace App\Services\Licensing;

use App\Models\Setting;
use App\Models\Tenant;
use App\Services\Graph\GraphException;
use App\Services\Lifecycle\Directory;
use App\Services\Scanner;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;

/**
 * Licence optimisation for one client (read-only):
 *  - unused paid seats (reduce at the next renewal or reassign)
 *  - disabled accounts that still hold licences
 *  - licensed members without a sign-in for N days (needs sign-in activity: Entra ID P1)
 *  - duplicate licences: a SKU whose service plans are all included in another SKU the same user has
 * Costs are estimates from an editable price list (Settings > Licence prices).
 */
class LicenseInsights
{
    public const INACTIVE_DAYS = [30, 60, 90, 180];

    /** Estimated monthly list prices (USD, annual commitment). Edit under Licence optimisation > Prices. */
    public const DEFAULT_PRICES = [
        'SPB' => 22.00, 'O365_BUSINESS_PREMIUM' => 12.50, 'O365_BUSINESS_ESSENTIALS' => 6.00, 'O365_BUSINESS' => 8.25,
        'SPE_E3' => 36.00, 'SPE_E5' => 57.00, 'SPE_F1' => 8.00, 'ENTERPRISEPACK' => 23.00, 'ENTERPRISEPREMIUM' => 38.00, 'STANDARDPACK' => 10.00, 'DESKLESSPACK' => 4.00,
        'EXCHANGESTANDARD' => 4.00, 'EXCHANGEENTERPRISE' => 8.00, 'EMS' => 10.60, 'EMSPREMIUM' => 16.40, 'INTUNE_A' => 8.00,
        'AAD_PREMIUM' => 6.00, 'AAD_PREMIUM_P2' => 9.00, 'ATP_ENTERPRISE' => 2.00, 'MDE_SMB' => 3.00, 'POWER_BI_PRO' => 14.00,
        'VISIOCLIENT' => 15.00, 'PROJECTPROFESSIONAL' => 30.00, 'MCOEV' => 10.00, 'Microsoft_Teams_Premium' => 10.00, 'Microsoft_365_Copilot' => 30.00,
    ];

    /** Service plans every SKU carries; ignored when deciding whether one licence includes another. */
    private const COMMON_PLANS = ['EXCHANGE_S_FOUNDATION', 'MICROSOFT_SEARCH', 'BPOS_S_TODO_1', 'BPOS_S_TODO_2', 'BPOS_S_TODO_3', 'MESH_AVATARS_FOR_TEAMS', 'MESH_AVATARS_ADDITIONAL_FOR_TEAMS', 'MESH_IMMERSIVE_FOR_TEAMS', 'PURVIEW_DISCOVERY', 'Nucleus', 'RMS_S_BASIC'];

    public function __construct(private readonly Tenant $tenant) {}

    public static function prices(): array
    {
        $saved = json_decode((string) Setting::find('license_prices')?->value, true);

        return array_merge(self::DEFAULT_PRICES, is_array($saved) ? array_map('floatval', $saved) : []);
    }

    public static function currency(): string
    {
        return Setting::find('license_currency')?->value ?: 'USD';
    }

    private static function key(Tenant $t, int $days): string
    {
        return "tg:licinsights:{$t->id}:{$days}";
    }

    /** Last analysis without calling Microsoft Graph (portfolio view). */
    public static function cached(Tenant $tenant, int $days = 90): ?array
    {
        return Cache::get(self::key($tenant, $days));
    }

    public function analyse(int $days = 90, bool $refresh = false): array
    {
        $days = in_array($days, self::INACTIVE_DAYS, true) ? $days : 90;
        if ($refresh || $this->tenant->isDemo()) {
            Cache::forget(self::key($this->tenant, $days));
        }

        return Cache::remember(self::key($this->tenant, $days), now()->addDays(7), fn () => $this->compute($days));
    }

    private function compute(int $days): array
    {
        $graph = Scanner::context($this->tenant)->graph;
        $prices = self::prices();

        $skus = collect($graph->getAll('/subscribedSkus'))->filter(fn ($s) => ($s['capabilityStatus'] ?? 'Enabled') !== 'Deleted')->keyBy('skuId');
        $name = fn (string $part) => Directory::SKU_NAMES[$part] ?? $part;
        $planSet = $skus->map(fn ($s) => array_values(array_diff(array_column($s['servicePlans'] ?? [], 'servicePlanName'), self::COMMON_PLANS)));
        $price = fn (string $skuId) => (float) ($prices[$skus[$skuId]['skuPartNumber'] ?? ''] ?? 0);

        $signins = true;
        try {
            $users = $graph->getAll('/users?$select=id,displayName,userPrincipalName,accountEnabled,userType,assignedLicenses,createdDateTime,signInActivity&$top=999');
        } catch (GraphException $e) {
            if (! $e->isPermissionOrLicense()) {
                throw $e;
            }
            $signins = false; // sign-in activity needs Entra ID P1 and AuditLog.Read.All
            $users = $graph->getAll('/users?$select=id,displayName,userPrincipalName,accountEnabled,userType,assignedLicenses,createdDateTime&$top=999');
        }

        $licensed = array_filter($users, fn ($u) => ! empty($u['assignedLicenses']));
        $disabled = $inactive = $overlaps = [];
        $flagged = [];

        foreach ($licensed as $u) {
            // Free, viral and trial SKUs (10,000+ units) cost nothing: ignore them.
            $ids = array_values(array_filter(array_column($u['assignedLicenses'], 'skuId'), fn ($id) => isset($skus[$id]) && (int) ($skus[$id]['prepaidUnits']['enabled'] ?? 0) < 10000));
            if (! $ids) {
                continue;
            }
            $cost = array_sum(array_map($price, $ids));
            $names = array_map(fn ($id) => $name($skus[$id]['skuPartNumber']), $ids);
            $row = ['id' => $u['id'], 'name' => $u['displayName'] ?? '', 'upn' => $u['userPrincipalName'] ?? '', 'licences' => $names, 'cost' => round($cost, 2)];

            if (! ($u['accountEnabled'] ?? true)) {
                $disabled[] = $row;
                $flagged[$u['id']] = true;

                continue;
            }
            if ($signins) {
                $last = $u['signInActivity']['lastSignInDateTime'] ?? null;
                $created = isset($u['createdDateTime']) ? Carbon::parse($u['createdDateTime']) : null;
                $idle = $last ? (int) Carbon::parse($last)->diffInDays(now()) : null;
                if (($last && $idle >= $days) || (! $last && $created && $created->lt(now()->subDays($days)))) {
                    $inactive[] = $row + ['last_signin' => $last, 'days' => $idle];
                    $flagged[$u['id']] = true;

                    continue;
                }
            }
            // Duplicate licences: every (non-common) plan of A is included in another assigned SKU B.
            foreach ($ids as $a) {
                foreach ($ids as $b) {
                    if ($a === $b || ! $planSet[$a] || count($planSet[$a]) >= count($planSet[$b])) {
                        continue;
                    }
                    if (! array_diff($planSet[$a], $planSet[$b])) {
                        $overlaps[] = ['id' => $u['id'], 'name' => $row['name'], 'upn' => $row['upn'], 'redundant' => $name($skus[$a]['skuPartNumber']),
                            'covered_by' => $name($skus[$b]['skuPartNumber']), 'cost' => round($price($a), 2)];
                        break;
                    }
                }
            }
        }

        $skuRows = $skus->map(function ($s) use ($name, $prices) {
            $total = (int) ($s['prepaidUnits']['enabled'] ?? 0);
            $used = (int) ($s['consumedUnits'] ?? 0);
            $p = (float) ($prices[$s['skuPartNumber']] ?? 0);
            $free = $total >= 10000; // viral, trial and free SKUs

            return [
                'skuId' => $s['skuId'], 'part' => $s['skuPartNumber'], 'name' => $name($s['skuPartNumber']), 'total' => $total, 'used' => $used,
                'unused' => $free ? 0 : max(0, $total - $used), 'price' => $p, 'priced' => isset($prices[$s['skuPartNumber']]), 'free' => $free,
                'monthly' => $free ? 0 : round($total * $p, 2), 'waste' => $free ? 0 : round(max(0, $total - $used) * $p, 2),
                'suspended' => (int) ($s['prepaidUnits']['suspended'] ?? 0) + (int) ($s['prepaidUnits']['warning'] ?? 0),
            ];
        })->sortByDesc('monthly')->values()->all();

        $sum = fn (array $rows) => round(array_sum(array_column($rows, 'cost')), 2);
        $totals = [
            'monthly' => round(array_sum(array_column($skuRows, 'monthly')), 2),
            'unused_seats' => array_sum(array_column($skuRows, 'unused')),
            'unused_cost' => round(array_sum(array_column($skuRows, 'waste')), 2),
            'disabled_cost' => $sum($disabled),
            'inactive_cost' => $sum($inactive),
            'overlap_cost' => $sum($overlaps),
        ];
        $totals['savings'] = round($totals['unused_cost'] + $totals['disabled_cost'] + $totals['inactive_cost'] + $totals['overlap_cost'], 2);

        return [
            'days' => $days, 'currency' => self::currency(), 'signins' => $signins, 'read_at' => now()->toIso8601String(),
            'skus' => $skuRows, 'disabled' => $disabled, 'inactive' => $inactive, 'overlaps' => $overlaps, 'totals' => $totals,
            'unpriced' => array_values(array_unique(array_column(array_filter($skuRows, fn ($r) => ! $r['priced'] && ! $r['free'] && $r['total'] > 0), 'name'))),
        ];
    }
}
