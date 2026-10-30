<?php

use App\Modules\Employees\Console\Commands\ApplyDueJobChanges;
use App\Modules\Employees\Console\Commands\PublishDueAnnouncements;
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

/**
 * Announcements whose publish date has arrived — docs/saas-launch-plan.md §14.10.
 *
 * At seven, not at midnight. A notice is read when somebody opens their mail in the
 * morning, and one that arrives at 00:05 is at the bottom of the overnight pile.
 *
 * The command tells each audience once, inside a one-day window. Without
 * `schedule:run` on cron, a scheduled announcement still appears on the dashboard on
 * its day — the dashboard has always respected a future date — but nobody is told.
 */
Schedule::command(PublishDueAnnouncements::class)
    ->dailyAt('07:00')
    ->withoutOverlapping();
