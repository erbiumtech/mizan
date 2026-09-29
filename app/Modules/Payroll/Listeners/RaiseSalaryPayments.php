<?php

namespace App\Modules\Payroll\Listeners;

use App\Events\RaisingMonthlyPayments;
use App\Modules\Payroll\Services\SalaryPaymentGenerator;

/**
 * Raise the month's salary payables when a bank-file page asks.
 *
 * The generator is idempotent on `payslip_id`, so opening the page is what brings a new
 * month's payroll into the payables list — relied on by both bank-file pages and asserted
 * by PaymentBatchTest. `salary` is the transaction-type code this raises; the event's
 * $only filter compares against it, so a page filtered to another type raises nothing.
 */
class RaiseSalaryPayments
{
    public function handle(RaisingMonthlyPayments $event): void
    {
        if ($event->only !== null && $event->only !== 'salary') {
            return;
        }

        app(SalaryPaymentGenerator::class)->generate($event->month, $event->fiscalYear);
    }
}
