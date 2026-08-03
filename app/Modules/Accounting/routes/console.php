<?php

use App\Modules\Accounting\Console\Commands\RaiseSubscriptionPayments;
use Illuminate\Support\Facades\Schedule;

/**
 * The month's standing payments are raised on the 26th, with the payroll, so one
 * batch covers the month: the rent and the internet leave with the salaries.
 *
 * Ten past the hour rather than on it — payroll runs at 02:00, and two commands
 * opening every tenant database at the same moment is a race worth not having.
 *
 * TenantAware, so it runs once per company, and skipped for companies without
 * Accounting. Needs `schedule:run` on cron.
 */
Schedule::command(RaiseSubscriptionPayments::class)
    ->monthlyOn(26, '02:10')
    ->withoutOverlapping();
