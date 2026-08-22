<?php

namespace App\Support\Contracts;

/**
 * The answer when Payroll is not installed: nothing is settled, so nothing is
 * frozen.
 *
 * Identical to what `modules()->enabled('payroll')` returning false always
 * produced — the guard has moved from the call site to the binding, which is the
 * whole of the change.
 */
class NeverLocked implements PeriodLock
{
    public function isLocked(int $year, int $month): bool
    {
        return false;
    }
}
