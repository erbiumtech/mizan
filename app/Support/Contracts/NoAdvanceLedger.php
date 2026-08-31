<?php

namespace App\Support\Contracts;

use App\Support\PayslipSettlement;

/**
 * No advance ledger: nothing owed, nothing to record, nothing to reopen.
 *
 * Bound as the default so payroll runs identically for a company that never bought Advances. An instalment of
 * `0.0` makes `PayslipService` fall through to the figure on the employee's settings, which is exactly what
 * the `modules()->enabled('advances')` guard produced before this became a contract — the behaviour
 * `ModuleDegradationTest` and `PayslipAttendanceProrationTest` already assert.
 */
class NoAdvanceLedger implements AdvanceLedger
{
    public function instalmentFor(int|string $employeeId, int|string|null $excludingPayslipId = null, ?string $periodOn = null): float
    {
        return 0.0;
    }

    public function recordRecoveryFor(PayslipSettlement $settlement): void {}

    public function reopenSettledFor(int|string $employeeId): void {}
}
