<?php

namespace App\Support\Contracts;

/** No payroll module: nothing can say what an hour cost, and the report states the hours as uncosted. */
class NoLabourCost implements LabourCost
{
    public function hourlyFor(int|string $employeeId, string $on): ?float
    {
        return null;
    }
}
