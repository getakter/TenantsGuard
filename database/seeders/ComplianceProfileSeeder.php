<?php

namespace Database\Seeders;

use App\Models\ComplianceProfile;
use Illuminate\Database\Seeder;

class ComplianceProfileSeeder extends Seeder
{
    public static function profiles(): array
    {
        return [
            ['Australia: Essential Eight ML2 + ASD ISM', 'Recommended target for most Australian organisations and government suppliers. Essential Eight Maturity Level 2 plus the ASD ISM guideline areas for Microsoft 365 and endpoints.', [['key' => 'e8', 'target_level' => 2], ['key' => 'asd_ism']]],
            ['Australia: Essential Eight ML1 (small business)', 'Entry-level baseline for small Australian businesses (ASD Small Business guidance).', [['key' => 'e8', 'target_level' => 1]]],
            ['Australia: Essential Eight ML3 (high threat)', 'For organisations targeted by capable adversaries, such as critical infrastructure and defence supply chain.', [['key' => 'e8', 'target_level' => 3], ['key' => 'asd_ism']]],
            ['US healthcare: HIPAA + NIST CSF + CIS v8', 'Covered entities and business associates handling ePHI.', [['key' => 'hipaa'], ['key' => 'nistcsf'], ['key' => 'cisv8']]],
            ['General: CIS M365 + CIS Controls + NIST CSF', 'Vendor-neutral baseline for any organisation.', [['key' => 'cis_m365'], ['key' => 'cisv8'], ['key' => 'nistcsf']]],
            ['UK: Cyber Essentials + ISO 27001', 'UK organisations pursuing Cyber Essentials (Plus) and ISO 27001 certification.', [['key' => 'ce'], ['key' => 'iso27001']]],
            ['Payments: PCI DSS + CIS Controls', 'Merchants and service providers in scope for PCI DSS v4.0.', [['key' => 'pci'], ['key' => 'cisv8']]],
            ['US federal / defence: NIST 800-53 + NIST CSF', 'Federal contractors and CMMC-aligned organisations.', [['key' => 'nist80053'], ['key' => 'nistcsf']]],
        ];
    }

    public function run(): void
    {
        foreach (self::profiles() as [$name, $description, $frameworks]) {
            ComplianceProfile::updateOrCreate(['name' => $name], [
                'description' => $description,
                'frameworks' => $frameworks,
                'is_system' => true,
            ] + (ComplianceProfile::where('name', $name)->exists() ? [] : ['excluded_controls' => [], 'exclude_from_score' => true]));
        }
    }
}
