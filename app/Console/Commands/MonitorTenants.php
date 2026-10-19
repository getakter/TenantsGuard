<?php

namespace App\Console\Commands;

use App\Models\Tenant;
use App\Services\Monitoring\SecurityMonitor;
use Illuminate\Console\Command;
use Throwable;

/** Security monitoring pass for every connected client (read-only). Scheduled every 15 minutes. */
class MonitorTenants extends Command
{
    protected $signature = 'tenants:monitor {--tenant= : Only this tenant ID}';

    protected $description = 'Read audit logs, sign-ins, risky users, Defender alerts and service health, and raise security alerts.';

    public function handle(): int
    {
        @set_time_limit(0);
        $q = Tenant::query()->orderBy('id');
        if ($id = $this->option('tenant')) {
            $q->whereKey($id);
        }
        $new = 0;
        foreach ($q->get() as $tenant) {
            if (! $tenant->isReady()) {
                continue;
            }
            try {
                $r = (new SecurityMonitor($tenant))->run();
                $new += $r['new'];
            } catch (Throwable $e) {
                report($e);
                $this->warn("{$tenant->name}: {$e->getMessage()}");
            }
        }
        if ($new) {
            $this->info("{$new} new alert(s).");
        }

        return self::SUCCESS;
    }
}
