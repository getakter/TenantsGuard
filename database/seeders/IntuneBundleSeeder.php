<?php

namespace Database\Seeders;

use App\Models\IntuneBundle;
use Illuminate\Database\Seeder;

/**
 * Prebuilt bundles that reflect common MSP customer contexts. Admins duplicate and adapt them.
 * Everything is assigned to the pilot group (or report-only for Conditional Access) by default,
 * so a first deployment never affects the whole company.
 */
class IntuneBundleSeeder extends Seeder
{
    public static function bundles(): array
    {
        $i = fn (string $template, string $assignment = 'pilot', array $params = []) => compact('template', 'params', 'assignment');

        return [
            [
                'name' => 'SMB standard: Business Premium (corporate PCs, BYOD phones)',
                'description' => 'The typical small business: company-owned Windows laptops fully managed, staff use personal phones protected with app protection (no enrollment). Balanced security with low user friction.',
                'context' => ['plan' => 'business_premium', 'ownership' => 'mixed', 'industry' => 'general', 'platforms' => ['windows', 'ios', 'android']],
                'items' => [
                    $i('PRE-03', 'none'), $i('PRE-05', 'none'), $i('ENR-01', 'none'),
                    $i('WIN-ENR-01', 'all_devices'),
                    $i('WIN-CMP-01'), $i('WIN-SEC-01'), $i('WIN-SEC-02'), $i('WIN-SEC-03'), $i('WIN-SEC-04', 'pilot', ['mode' => '2']), $i('WIN-SEC-05'),
                    $i('WIN-CFG-01'), $i('WIN-CFG-02'), $i('WIN-UPD-01'), $i('WIN-UPD-02', 'all_devices'),
                    $i('IOS-APP-01', 'pilot', ['level' => '2']), $i('AND-APP-01', 'pilot', ['level' => '2']),
                    $i('ZT-CA-01'), $i('ZT-CA-02'),
                ],
            ],
            [
                'name' => 'BYOD mobile: app protection only (no enrollment)',
                'description' => 'For customers whose staff refuse device enrollment, or for contractors. Corporate data is protected inside Microsoft apps. The personal device stays private. Works with Intune Plan 1. Conditional Access needs Entra ID P1.',
                'context' => ['plan' => 'business_premium', 'ownership' => 'byod', 'industry' => 'general', 'platforms' => ['ios', 'android']],
                'items' => [
                    $i('ENR-01', 'none'),
                    $i('IOS-APP-01', 'pilot', ['level' => '2']), $i('AND-APP-01', 'pilot', ['level' => '2']),
                    $i('ZT-CA-02'), $i('ZT-CA-03'),
                ],
            ],
            [
                'name' => 'Corporate-owned mobile: fully managed iOS and Android',
                'description' => 'Company-issued phones and tablets: device compliance, restrictions and app protection. Requires the Apple push certificate and the Managed Google Play connection.',
                'context' => ['plan' => 'e3', 'ownership' => 'corporate', 'industry' => 'general', 'platforms' => ['ios', 'android']],
                'items' => [
                    $i('PRE-01', 'none'), $i('PRE-02', 'none'), $i('ENR-01', 'none'),
                    $i('IOS-CMP-01'), $i('IOS-CFG-01'), $i('IOS-APP-01', 'pilot', ['level' => '2']),
                    $i('AND-CMP-02'), $i('AND-CFG-02'), $i('AND-APP-01', 'pilot', ['level' => '2']),
                ],
            ],
            [
                'name' => 'Healthcare / HIPAA: high security',
                'description' => 'For covered entities and business associates handling ePHI. Level 3 app protection, encryption with USB write block, ASR in block mode, short screen lock, fast patching, Defender risk in compliance, full Zero Trust Conditional Access.',
                'context' => ['plan' => 'e5', 'ownership' => 'mixed', 'industry' => 'healthcare', 'platforms' => ['windows', 'ios', 'android']],
                'items' => [
                    $i('PRE-01', 'none'), $i('PRE-02', 'none'), $i('PRE-03', 'none'), $i('PRE-04', 'none'), $i('PRE-05', 'none'), $i('ENR-01', 'none'),
                    $i('WIN-ENR-01', 'all_devices'),
                    $i('WIN-CMP-01', 'pilot', ['mde_level' => 'low', 'grace_hours' => 12]),
                    $i('WIN-SEC-01', 'pilot', ['cloud_level' => '4']), $i('WIN-SEC-02', 'pilot', ['block_usb_write' => true]), $i('WIN-SEC-03'),
                    $i('WIN-SEC-04', 'pilot', ['mode' => '1']), $i('WIN-SEC-05'), $i('WIN-SEC-06'),
                    $i('WIN-CFG-01', 'pilot', ['lock_seconds' => 600]), $i('WIN-CFG-02'),
                    $i('WIN-UPD-01'), $i('WIN-UPD-02', 'all_devices', ['quality_deferral' => 3, 'quality_deadline' => 2]),
                    $i('IOS-CMP-01'), $i('IOS-APP-01', 'pilot', ['level' => '3', 'offline_wipe_days' => 30]),
                    $i('AND-CMP-01'), $i('AND-CFG-01'), $i('AND-APP-01', 'pilot', ['level' => '3', 'offline_wipe_days' => 30]),
                    $i('ZT-CA-01'), $i('ZT-CA-02'), $i('ZT-CA-03', 'pilot', ['allow_macos' => false]),
                ],
            ],
            [
                'name' => 'Windows endpoint hardening only',
                'description' => 'Security uplift for an existing Windows fleet, without touching mobile: compliance, Defender, BitLocker, firewall, ASR (audit), LAPS, Credential Guard and update rings.',
                'context' => ['plan' => 'business_premium', 'ownership' => 'corporate', 'industry' => 'general', 'platforms' => ['windows']],
                'items' => [
                    $i('PRE-03', 'none'), $i('PRE-05', 'none'),
                    $i('WIN-CMP-01'), $i('WIN-SEC-01'), $i('WIN-SEC-02'), $i('WIN-SEC-03'), $i('WIN-SEC-04', 'pilot', ['mode' => '2']),
                    $i('WIN-SEC-05'), $i('WIN-SEC-06'), $i('WIN-CFG-01'), $i('WIN-UPD-01'), $i('WIN-UPD-02', 'all_devices'), $i('WIN-UPD-03'),
                ],
            ],
        ];
    }

    public function run(): void
    {
        foreach (self::bundles() as $b) {
            IntuneBundle::updateOrCreate(['name' => $b['name'], 'is_system' => true], $b + ['is_system' => true]);
        }
    }
}
