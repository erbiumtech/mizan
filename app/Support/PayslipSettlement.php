<?php

namespace App\Support;

/**
 * What one payslip settles against an employee's ledger somewhere else.
 *
 * A payslip deducts an advance instalment and reimburses approved expense claims, and those two ledgers live
 * in modules that **require** Payroll — so Payroll naming `AdvanceService` or `ExpenseClaimService` made both
 * pairs cycles, and they were the last cycles in the application. See
 * `App\Support\Contracts\AdvanceLedger`, `App\Support\Contracts\ReimbursableClaims` and
 * docs/module-packaging-plan.md §11.
 *
 * **This exists because a contract cannot name `Payslip`.** The obvious signature is
 * `recordRecoveryFor(Payslip $payslip)`, but a contract in a shared namespace naming a module's model reaches
 * into that module — which `ModuleBoundaryTest::test_shared_namespaces_do_not_reach_into_modules` forbids, and
 * rightly: the contract would then be undeployable without Payroll, which is the opposite of the point. So
 * the four values the two ledgers actually read from a payslip are passed instead, and nothing about a
 * `Payslip` crosses the boundary.
 *
 * `$effectiveOn` is computed by Payroll rather than by the far side, because "the last day of the payroll
 * month" is a payroll fact — `App\Support\PayrollMonth::lastDay()`, reached through
 * `Payslip::settlementOf()`. The ledger recording a recovery should not have to know how a payroll month
 * ends.
 */
final readonly class PayslipSettlement
{
    /**
     * @param  int|string  $payslipId  the row the settlement belongs to, so a re-save corrects rather than duplicates
     * @param  int|string  $employeeId  whose ledger is being settled
     * @param  float  $amount  what the payslip took (a deduction) or pays back (a reimbursement), always positive
     * @param  string  $effectiveOn  the date to record it on, as `Y-m-d`
     */
    public function __construct(
        public int|string $payslipId,
        public int|string $employeeId,
        public float $amount,
        public string $effectiveOn,
    ) {}
}
