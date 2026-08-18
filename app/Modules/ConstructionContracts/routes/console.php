<?php

use App\Modules\ConstructionContracts\Console\Commands\ReconcileRetention;
use Illuminate\Support\Facades\Schedule;

/**
 * Nightly, because §11 asks for a scheduled comparison rather than a screen somebody remembers to open: "two write
 * paths, one forgotten, and nobody reads both registers in the same week".
 *
 * TenantAware, so it runs once per company, and skipped for companies without the module. Needs `schedule:run` on
 * cron.
 */
Schedule::command(ReconcileRetention::class)
    ->dailyAt('02:40')
    ->withoutOverlapping();
