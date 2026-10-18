<?php

namespace App\Console\Commands;

use App\Services\Intune\IntuneDeployer;
use Illuminate\Console\Command;

/** Runs queued Intune deployments. Started on submit (spawn) and every minute by the scheduler as a safety net. */
class ProcessIntuneDeployments extends Command
{
    protected $signature = 'intune:process {--max=3300 : Stop picking up new work after this many seconds}';

    protected $description = 'Run queued Intune bundle deployments and previews, one client at a time.';

    public function handle(IntuneDeployer $deployer): int
    {
        $n = $deployer->processQueued((int) $this->option('max'));
        if ($n) {
            $this->info("Processed {$n} deployment(s).");
        }

        return self::SUCCESS;
    }
}
