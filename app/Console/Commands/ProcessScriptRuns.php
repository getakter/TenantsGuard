<?php

namespace App\Console\Commands;

use App\Services\Scripts\ScriptService;
use Illuminate\Console\Command;

/** Executes queued PowerShell script runs. Started on submit (spawn) and every minute by the scheduler as a safety net. */
class ProcessScriptRuns extends Command
{
    protected $signature = 'scripts:process {--max=3300 : Stop picking up new work after this many seconds}';

    protected $description = 'Run queued PowerShell scripts against their target tenants (one tenant at a time, isolated processes).';

    public function handle(ScriptService $service): int
    {
        $n = $service->process((int) $this->option('max'));
        if ($n) {
            $this->info("Processed {$n} tenant run(s).");
        }

        return self::SUCCESS;
    }
}
