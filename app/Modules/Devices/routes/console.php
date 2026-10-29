<?php

use App\Modules\Devices\Console\Commands\CheckDeviceSilence;
use App\Modules\Devices\Console\Commands\FoldDevicePunches;
use Illuminate\Support\Facades\Schedule;

// Biometric devices — docs/saas-launch-plan.md §8.7. Punches fold every five minutes,
// per company; the silence check once a morning, landlord-side.
Schedule::command(FoldDevicePunches::class)
    ->everyFiveMinutes()
    ->withoutOverlapping();

Schedule::command(CheckDeviceSilence::class)
    ->dailyAt('08:30')
    ->withoutOverlapping();
