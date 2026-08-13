<?php

use App\Modules\Quotations\Console\Commands\ExpireQuotations;
use Illuminate\Support\Facades\Schedule;

/**
 * Expire quotes whose validity has passed.
 *
 * Daily, and **one transition per quote rather than a daily nag** — a quote moves to `expired`
 * once and is never reported again. The health-check alerts taught that lesson here: a job that
 * mails the same warning every morning trains somebody to filter it.
 *
 * TenantAware, and it skips companies with the module switched off.
 */
Schedule::command(ExpireQuotations::class)->dailyAt('05:30');
