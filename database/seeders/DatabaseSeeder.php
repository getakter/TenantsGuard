<?php

namespace Database\Seeders;

use App\Models\Baseline;
use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use RuntimeException;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $admin = config('tenantsguard.admin');
        if (blank($admin['password'])) {
            throw new RuntimeException('Set ADMIN_PASSWORD in .env before seeding.');
        }

        User::updateOrCreate(['email' => $admin['email']], [
            'name' => $admin['name'],
            'password' => Hash::make($admin['password']),
            'role_id' => Role::byKey(Role::GLOBAL_ADMIN)->id,
            'all_tenants' => true,
            'is_active' => true,
        ]);

        $baselines = [
            ['Identity essentials', 'MFA, legacy-auth blocking and admin session controls. Conditional Access is created in report-only mode.',
                ['ID-02', 'ID-03', 'ID-04', 'ID-05', 'ID-13', 'ID-14']],
            ['App consent lockdown', 'Stops consent phishing and shadow app registrations.',
                ['APP-01', 'APP-02', 'APP-03', 'APP-04']],
            ['External collaboration', 'Restricts guests and anonymous sharing.',
                ['COL-01', 'COL-02', 'COL-03', 'COL-04']],
            ['CIS Level 1 quick wins', 'Low-disruption CIS Microsoft 365 recommendations that can be applied automatically.',
                ['ID-15', 'APP-01', 'APP-02', 'APP-03', 'APP-04', 'COL-01', 'COL-02', 'COL-03']],
        ];
        foreach ($baselines as [$name, $description, $ids]) {
            Baseline::updateOrCreate(['name' => $name], ['description' => $description, 'check_ids' => $ids]);
        }

        $this->call(IntuneBundleSeeder::class);
        $this->call(ComplianceProfileSeeder::class);
    }
}
