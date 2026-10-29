<?php

namespace App\Support\Contracts;

/**
 * A figure for a payslip line, from a module that may not name Payroll —
 * docs/saas-launch-plan.md §14.4's leave encashment. Payroll binds this to its
 * adjustment records; without Payroll the figure has nowhere to go and is dropped.
 */
interface PayrollInputs
{
    /** @param  string  $componentCode  a payslip override line — bonus, advances, meal_deduction … */
    public function add(int $employeeId, string $month, int $fiscalYearId, string $componentCode, float $amount, string $reason): void;
}
