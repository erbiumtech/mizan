<?php

use App\Modules\Lifecycle\Console\Commands\CheckDocumentExpiry;
use Illuminate\Support\Facades\Schedule;

/**
 * Document expiry warnings, daily.
 *
 * Daily and idempotent rather than weekly: a threshold is crossed on a particular day,
 * and a weekly job would report it up to six days late — which for the seven-day
 * threshold means reporting it after the document has already lapsed.
 *
 * TenantAware, and skips companies with the module switched off.
 */
Schedule::command(CheckDocumentExpiry::class)
    ->dailyAt('06:30');

// Auto-check items and separations past their last day — docs/saas-launch-plan.md §14.5.
Schedule::command(\App\Modules\Lifecycle\Console\Commands\RunLifecycleChecks::class)
    ->dailyAt('01:15')
    ->withoutOverlapping();
