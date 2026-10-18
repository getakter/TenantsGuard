<?php

namespace App\Console\Commands;

use App\Models\Scan;
use App\Models\ScriptRunTarget;
use App\Models\SecureScoreSnapshot;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

class PruneScans extends Command
{
    protected $signature = 'tenants:prune';

    protected $description = 'Delete scans older than SCAN_RETENTION_DAYS, always keeping the latest scan of each tenant.';

    public function handle(): int
    {
        $latest = Scan::selectRaw('MAX(id) as id')->groupBy('tenant_id')->pluck('id');
        $deleted = Scan::where('created_at', '<', now()->subDays(config('tenantsguard.retention_days')))
            ->whereNotIn('id', $latest)
            ->delete();
        $keepSs = SecureScoreSnapshot::selectRaw('MAX(id) as id')->groupBy('tenant_id')->pluck('id');
        $ss = SecureScoreSnapshot::where('created_at', '<', now()->subDays(config('tenantsguard.retention_days')))->whereNotIn('id', $keepSs)->delete();
        // Script output can contain client data: keep the audit trail (who ran what, where) but drop output after the retention period.
        $scripts = ScriptRunTarget::where('finished_at', '<', now()->subDays(config('tenantsguard.scripts.retention_days')))
            ->where(fn ($q) => $q->whereNotNull('output')->orWhereNotNull('results'))
            ->update(['output' => null, 'results' => null]);
        // Leftover work folders from crashed workers.
        foreach (glob(storage_path('app/script-runs/*'), GLOB_ONLYDIR) ?: [] as $dir) {
            if (filemtime($dir) < time() - 86400) {
                File::deleteDirectory($dir);
            }
        }
        $this->info("Pruned {$deleted} scans, {$ss} Secure Score snapshots and output of {$scripts} script run(s).");

        return self::SUCCESS;
    }
}
