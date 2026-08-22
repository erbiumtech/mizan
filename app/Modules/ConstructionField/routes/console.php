<?php

use App\Modules\ConstructionField\Console\Commands\CheckDelayNotices;
use Illuminate\Support\Facades\Schedule;

/**
 * **The notice clock, and §13 calls it "the most valuable thing in this section".**
 *
 * "A due-date with a notification attached is worth more commercially than the entire programme: a valid claim lost to
 * a missed notice is the single most common way a contractor donates money, and it fails in absolute silence."
 *
 * Daily rather than weekly, for the reason the compliance warning gives: the tightest threshold is one day, and a
 * weekly run would step straight over it — an event would go from "notice due in six days" to time-barred with nothing
 * sent in between, which is the case the warning exists for. Early enough that the mail is waiting rather than
 * arriving mid-morning. TenantAware, so it runs once per company, and skipped for companies without the module.
 */
Schedule::command(CheckDelayNotices::class)
    ->dailyAt('05:40')
    ->withoutOverlapping();
