<?php

use App\Modules\Performance\Console\Commands\AwardBadges;
use App\Modules\Performance\Console\Commands\RollTasks;
use App\Modules\Performance\Console\Commands\SettleTaskPenalties;
use Illuminate\Support\Facades\Schedule;

// Recurring tasks — docs/saas-launch-plan.md §8.2. The roll at five past midnight,
// so yesterday's grace has run out for anything due before then; the settlement
// list on the last evening of the month, for the payroll clerk's morning.
Schedule::command(RollTasks::class)
    ->dailyAt('00:05')
    ->withoutOverlapping();

// Badges earned by rule — docs/saas-launch-plan.md §8.9 — judged each morning on
// yesterday's complete figures, after the roll has marked the misses.
Schedule::command(AwardBadges::class)
    ->dailyAt('06:00')
    ->withoutOverlapping();

Schedule::command(SettleTaskPenalties::class)
    ->lastDayOfMonth('23:30')
    ->withoutOverlapping();
