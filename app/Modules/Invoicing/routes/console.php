<?php

use App\Modules\Invoicing\Console\Commands\RaiseRecurringInvoices;
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
