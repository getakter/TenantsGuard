<?php

namespace App\Console\Commands;

use App\Models\ActivityLog;
use App\Models\Setting;
use App\Models\Tenant;
use App\Services\Notifier;
use App\Services\Scanner;
use Illuminate\Console\Command;
use Throwable;

class ScanDueTenants extends Command
{
    protected $signature = 'tenants:scan {--all : Scan every connected tenant, not only those that are due}';

    protected $description = 'Rescan connected tenants whose last scan is older than SCAN_INTERVAL_HOURS (drift detection).';

    public function handle(Scanner $scanner): int
    {
        $cutoff = now()->subHours(config('tenantsguard.scan_interval_hours'));
        $tenants = Tenant::all()->filter(fn ($t) => $t->isReady()
            && ($this->option('all') || ! $t->last_scanned_at || $t->last_scanned_at->lt($cutoff)));

        foreach ($tenants as $tenant) {
            try {
                $scan = $scanner->scan($tenant, 'scheduled');
                $this->info("{$tenant->name}: {$scan->score}%".($scan->drifted ? ' drift: '.implode(', ', $scan->drifted) : ''));
            } catch (Throwable $e) {
                ActivityLog::record('scan', $tenant, 'failure', 'Scheduled scan failed: '.$e->getMessage());
                Notifier::send('scan_failed', 'Scheduled scan failed', $e->getMessage(), $tenant);
                $this->error("{$tenant->name}: {$e->getMessage()}");
            }
        }
        // Heartbeat shown in Settings > System health.
        Setting::put('scheduler_last_run', now()->toIso8601String());
        $this->line($tenants->count().' tenants scanned.');

        return self::SUCCESS;
    }
}
