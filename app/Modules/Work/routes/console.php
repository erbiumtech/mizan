<?php

use App\Modules\Work\Console\Commands\SendWorkDigest;
use Illuminate\Support\Facades\Schedule;

/**
 * The morning digest — docs/project-management-plan.md §10.3.
 *
 * Hourly, on the hour, and the command itself decides whether this is the company's hour: one
 * schedule entry serves every company's own `work.digest_time`. TenantAware, and it skips companies
 * with the module switched off.
 */
Schedule::command(SendWorkDigest::class)
    ->hourly()
    ->withoutOverlapping();
