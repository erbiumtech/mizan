<?php

namespace App\Support\Contracts;

use App\Support\PayslipSettlement;

/**
 * No claims process: nothing owed back, nothing settled, nothing to release.
 *
 * Bound as the default so a company without the Expenses module gets a payslip whose
 * `expense_reimbursement` is whatever a clerk typed and nothing more — which is what Payroll's
 * `modules()->enabled('expenses')` guard produced before this became a contract.
 */
class NoReimbursableClaims implements ReimbursableClaims
{
    public function reimbursableFor(int|string $employeeId, int|string|null $includingPayslipId = null): float
    {
        return 0.0;
    }

    public function settleForPayslip(PayslipSettlement $settlement): void {}

    public function pendingReleaseFor(int|string $payslipId): array
    {
        return [];
    }

    public function releaseAll(array $claimIds): void {}
}
