<?php

namespace App\Support\Contracts;

/**
 * Whether a calendar month has been settled and may no longer be corrected.
 *
 * Attendance refuses a regularisation inside a locked payroll month. Payroll is
 * what knows a month is locked; Attendance only needs the answer, and must stay
 * sellable to a factory that runs no payroll at all — which is exactly why the
 * question belongs in shared code and the answer in Payroll.
 *
 * Deletes the `attendance -> payroll` import. See docs/module-packaging-plan.md §6.
 */
interface PeriodLock
{
    /**
     * Locked, or simply not knowable — the default implementation answers false,
     * which is the same answer a company with no payroll always got.
     */
    public function isLocked(int $year, int $month): bool;
}
