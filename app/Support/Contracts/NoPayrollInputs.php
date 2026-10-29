<?php

namespace App\Support\Contracts;

class NoPayrollInputs implements PayrollInputs
{
    public function add(int $employeeId, string $month, int $fiscalYearId, string $componentCode, float $amount, string $reason): void
    {
        //
    }
}
