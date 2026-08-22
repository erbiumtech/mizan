<?php

use App\Modules\ConstructionQhse\Console\Commands\CheckCompetencyExpiry;
use Illuminate\Support\Facades\Schedule;

/**
 * The competency clock — §17.5.
 *
 * A lapsed mandatory ticket is somebody doing work they are no longer certified for, and like §13's notice it fails
 * quietly: nothing stops them, nobody is told, and the first anybody hears of it is an inspector or an accident.
 *
 * Daily, for the reason §13's clock and §12's compliance warning both give: the tightest threshold is the day itself,
 * and a weekly run would step over it. Twenty minutes before the delay-notice run so the two do not contend, and early
 * enough that the mail is waiting rather than arriving mid-morning. TenantAware, so it runs once per company, and
 * skipped for companies without the module.
 */
Schedule::command(CheckCompetencyExpiry::class)
    ->dailyAt('05:20')
    ->withoutOverlapping();
