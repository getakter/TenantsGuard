<?php

namespace App\Services\Compliance;

use App\Services\Checks\Catalog;
use App\Services\Intune\IntuneCatalog;

/** Default rollout phases and how controls are applied. */
final class PlanDefaults
{
    public const PHASES = [
        ['n' => 1, 'name' => 'Phase 1: Foundations and quick wins', 'description' => 'Prerequisites, tenant hygiene, app consent and sharing settings with little or no user impact.'],
        ['n' => 2, 'name' => 'Phase 2: Identity and access', 'description' => 'MFA, administrator protection, authentication methods. Conditional Access is created in report-only mode.'],
        ['n' => 3, 'name' => 'Phase 3: Device baseline (pilot)', 'description' => 'Intune compliance, endpoint security, update rings and app protection for the pilot group.'],
        ['n' => 4, 'name' => 'Phase 4: Enforcement and high impact', 'description' => 'Changes that affect every user: high-impact device settings and broad assignments. Plan communications first.'],
    ];

    public const TYPES = [
        'auto' => ['Automatic fix', 'Microsoft 365 setting changed by TenantsGuard'],
        'intune' => ['Intune deployment', 'Policy created and assigned in Intune'],
        'manual' => ['Manual / evidence', 'Done in a portal or by process; verify, attest or annotate'],
    ];

    public static function phaseFor(string $controlId): int
    {
        if ($c = Catalog::find($controlId)) {
            return in_array($c['category'], ['Identity', 'Privileged access'], true) ? 2 : 1;
        }
        if ($t = IntuneCatalog::find($controlId)) {
            return match (true) {
                $t['kind'] === 'manual' => 1,
                $t['category'] === 'Conditional Access' => 2,
                $t['impact'] === 'high' => 4,
                default => 3,
            };
        }

        return 1;
    }

    public static function typeFor(string $controlId): string
    {
        if ($c = Catalog::find($controlId)) {
            return empty($c['fix']) ? 'manual' : 'auto';
        }
        if ($t = IntuneCatalog::find($controlId)) {
            return $t['kind'] === 'manual' ? 'manual' : 'intune';
        }

        return 'manual';
    }

    public static function assignmentFor(string $controlId): ?string
    {
        $t = IntuneCatalog::find($controlId);

        return $t && $t['kind'] !== 'manual' ? $t['default_target'] : null;
    }

    /** Impact shown to admins: Intune templates declare it, posture fixes are low unless they touch sign-in. */
    public static function impactFor(string $controlId): string
    {
        if ($t = IntuneCatalog::find($controlId)) {
            return $t['impact'];
        }

        // Settings that change how people sign in or share are visible to users.
        return in_array($controlId, ['ID-01', 'ID-02', 'ID-03', 'ID-04', 'ID-13', 'COL-03', 'COL-02'], true) ? 'medium' : 'low';
    }
}
