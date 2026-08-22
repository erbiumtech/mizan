<?php

use App\Modules\ConstructionContracts\Console\Commands\CheckComplianceExpiry;
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

/**
 * The compliance expiry warning (§12), early enough that the mail is waiting rather than arriving mid-morning.
 *
 * Daily and not weekly: the tightest threshold is one day, and a weekly run would step straight over it — the
 * document would go from "expires in eight days" to expired with nothing sent in between, which is the case the
 * warning exists for.
 */
Schedule::command(CheckComplianceExpiry::class)
    ->dailyAt('05:20')
    ->withoutOverlapping();
