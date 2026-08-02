<?php

use App\Modules\Payroll\Console\Commands\OpenPayrollMonth;
use Illuminate\Support\Facades\Schedule;

/**
 * The month's payroll is opened on the 26th, before the run at month end, so
 * there are several days to record attendance, correct a figure and have each
 * employee accept their payslip before anything is released.
 *
 * TenantAware, so it runs once per company, and skipped for companies without
 * Payroll. Needs `schedule:run` on cron.
 */
Schedule::command(OpenPayrollMonth::class)
    ->monthlyOn(26, '02:00')
    ->withoutOverlapping();
