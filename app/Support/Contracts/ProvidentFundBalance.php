<?php

namespace App\Support\Contracts;

use Carbon\CarbonInterface;

/**
 * What an employee's provident fund stands at — docs/saas-launch-plan.md §14.1.
 * Payroll keeps the ledger and binds this; the final settlement asks, and gets
 * nothing without Payroll rather than a class it may not name.
 */
interface ProvidentFundBalance
{
    /** Employee plus employer contributions and profit, less withdrawals, to the date. */
    public function balanceFor(int $employeeId, ?CarbonInterface $asOf = null): float;
}
