<?php

use Illuminate\Support\Facades\Schedule;

// Continuous drift detection: rescan tenants whose last scan is older than SCAN_INTERVAL_HOURS.
Schedule::command('tenants:scan')->hourly()->withoutOverlapping();

// Keep the configured history window (default 120 days).
Schedule::command('tenants:prune')->dailyAt('03:00');

// Staff lifecycle: start-date enablement, delayed licence removal, scheduled account deletion.
Schedule::command('lifecycle:process')->everyFiveMinutes()->withoutOverlapping();

// Intune deployments: safety net for deployments whose spawned worker did not start.
Schedule::command('intune:process --max=50')->everyMinute()->withoutOverlapping(60);

// PowerShell scripts: safety net for runs whose spawned worker did not start (or TG_SCRIPTS_DISPATCH=scheduler).
Schedule::command('scripts:process --max=50')->everyMinute()->withoutOverlapping(60);
