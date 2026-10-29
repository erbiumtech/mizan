<?php

namespace App\Support\Contracts;

use Carbon\CarbonInterface;

class NoProvidentFund implements ProvidentFundBalance
{
    public function balanceFor(int $employeeId, ?CarbonInterface $asOf = null): float
    {
        return 0.0;
    }
}
