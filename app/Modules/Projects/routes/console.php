<?php

use App\Modules\Projects\Console\Commands\CheckEnvironmentCertificates;
use App\Modules\Projects\Console\Commands\CheckEnvironmentsHealth;
use App\Modules\Projects\Models\ProjectEnvironmentCheck;
use Illuminate\Support\Facades\Schedule;

/**
 * Project environment monitoring. Runs every minute and dispatches only the
 * environments whose own check_interval_min says they are due, so a per-project
 * interval works without a schedule entry per project. Each command is
 * TenantAware and skips companies with the module switched off.
 *
 * Both of these need `schedule:run` on cron AND a running queue worker
 * (QUEUE_CONNECTION defaults to `database`). Without them health_status stays
 * null and renders as "unknown" — deliberately never as a green tick.
 */
Schedule::command(CheckEnvironmentsHealth::class)
    ->everyMinute()
    ->withoutOverlapping();

Schedule::command(CheckEnvironmentCertificates::class)
    ->dailyAt('06:00');

// Retention for the check history (Prunable on ProjectEnvironmentCheck). The
// model is named as a class constant rather than a string: the old
// `--model=App\Modules\Projects\Models\ProjectEnvironmentCheck` string would have silently
// stopped resolving the moment this model moved, failing at 00:00 in a queue
// worker rather than in CI.
Schedule::command('tenants:artisan', ['model:prune --model='.ProjectEnvironmentCheck::class])
    ->daily();
