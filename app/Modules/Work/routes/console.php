<?php

use App\Modules\Work\Console\Commands\PullTimeEntries;
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

/**
 * The time tracker pull — §8.8: every fifteen minutes for the last two days, nightly for the last
 * thirty. The backstop where the workspace sends webhooks and the only path where it cannot.
 */
Schedule::command(PullTimeEntries::class, ['--days' => 2])
    ->everyFifteenMinutes()
    ->withoutOverlapping();

Schedule::command(PullTimeEntries::class, ['--days' => 30])
    ->dailyAt('03:10')
    ->withoutOverlapping();
