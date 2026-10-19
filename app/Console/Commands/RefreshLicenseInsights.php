<?php

namespace App\Console\Commands;

use App\Models\Tenant;
use App\Services\Licensing\LicenseInsights;
use App\Services\Notifier;
use Illuminate\Console\Command;
use Throwable;

/** Re-reads licence usage for every connected client (daily); on Mondays sends the savings summary. */
class RefreshLicenseInsights extends Command
{
    protected $signature = 'licensing:refresh {--summary : Send the savings summary to the alert channel}';

    protected $description = 'Refresh licence optimisation data for all connected clients.';

    public function handle(): int
    {
        @set_time_limit(0);
        $lines = [];
        $total = 0.0;
        $currency = LicenseInsights::currency();
        foreach (Tenant::orderBy('name')->get() as $tenant) {
            if (! $tenant->isReady()) {
                continue;
            }
            try {
                $r = (new LicenseInsights($tenant))->analyse(90, true);
                if ($r['totals']['savings'] > 0) {
                    $total += $r['totals']['savings'];
                    $lines[] = "{$tenant->name}: {$currency} ".number_format($r['totals']['savings'], 2).'/month';
                }
            } catch (Throwable $e) {
                $this->warn("{$tenant->name}: {$e->getMessage()}");
            }
        }
        if ($this->option('summary') && $lines) {
            Notifier::send('licensing', 'Licence optimisation: '.$currency.' '.number_format($total, 2).' per month to recover', implode("\n", $lines));
        }
        $this->info(count($lines).' client(s) with potential savings.');

        return self::SUCCESS;
    }
}
