<?php

use App\Modules\Employees\Console\Commands\ApplyDueJobChanges;
use Illuminate\Support\Facades\Schedule;

/**
 * Employee job changes that were dated ahead of time.
 *
 * Early, before anything reads an employee's manager for the day: leave approval
 * routes through `manager_id`, so a transfer effective today should be in force
 * before the first request of the morning is filed against the old chain.
 *
 * TenantAware and gated on the module, so a company without Employees is skipped
 * rather than erroring per tenant at 00:05.
 *
 * Needs `schedule:run` on cron. Without it, future-dated rows are recorded and
 * never applied — the columns simply stay behind, silently, which is why
 * ApplyDueJobChanges reports the employees it touched by name.
 */
Schedule::command(ApplyDueJobChanges::class)
    ->dailyAt('00:05')
    ->withoutOverlapping();
