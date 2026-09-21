<?php

namespace App\Support\Contracts;

/**
 * What an hour of an employee's time cost the company — asked by Timesheets, answered by Payroll.
 *
 * The project margin report multiplies hours booked by this. Timesheets cannot name `Payslip` (its module
 * does not require Payroll), so the question is a contract, like `BillableTime` and `AdvanceLedger` before
 * it; a company without payroll gets the null answer and the report says the hours are uncosted rather than
 * inventing a rate.
 */
interface LabourCost
{
    /**
     * The cost of one hour of this employee's time in the month containing `$on`, in the base currency — or
     * null when nothing on record can say (no payslip for the month, no hours in it).
     */
    public function hourlyFor(int|string $employeeId, string $on): ?float;
}
