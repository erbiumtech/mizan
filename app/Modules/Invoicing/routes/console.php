<?php

use App\Modules\Invoicing\Console\Commands\RaiseRecurringInvoices;
use App\Modules\Invoicing\Console\Commands\SendOverdueReminders;
use Illuminate\Support\Facades\Schedule;

/**
 * Recurring invoices are raised on the 1st, at the start of the month they cover, so
 * there is a whole month to correct one before it is issued. Drafts only — issuing is
 * a decision somebody makes after reading it.
 *
 * TenantAware, so it runs once per company, and skipped for companies without
 * Invoicing. Needs `schedule:run` on cron.
 */
Schedule::command(RaiseRecurringInvoices::class)
    ->monthlyOn(1, '03:00')
    ->withoutOverlapping();

/**
 * Overdue reminders, daily — docs/erpnext-gap-plan.md Phase 5.
 *
 * Daily rather than weekly because the *interval* is a company setting: the job asks each day who is past
 * due and has not been chased inside their repeat window, so a company wanting fortnightly reminders sets
 * fourteen days rather than needing this line changed. Sends nothing at all unless the company has switched
 * dunning on — the only scheduled job here that writes to somebody outside the company.
 *
 * Mid-morning on purpose: a payment chase that arrives at 3am reads as automated, which is a worse first
 * impression than the same sentence at 10.
 */
Schedule::command(SendOverdueReminders::class)
    ->dailyAt('10:00')
    ->withoutOverlapping();
