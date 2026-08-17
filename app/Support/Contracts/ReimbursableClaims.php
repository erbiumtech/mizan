<?php

namespace App\Support\Contracts;

use App\Support\PayslipSettlement;

/**
 * What an employee is owed back in approved expense claims, and the settling of it against a payslip.
 *
 * The same shape as `AdvanceLedger` and for the same reason: `expenses` **requires** `payroll`, because a
 * claim is reimbursed through the payslip, and Payroll reached back to `Expenses\Services\ExpenseClaimService`
 * and `Expenses\Models\ExpenseClaim` to put the figure on it. See docs/module-packaging-plan.md §11.
 *
 * The reimbursement lands in `payslips.expense_reimbursement`, which Payroll owns; what is *in* it is the sum
 * of claims somebody approved, which Expenses owns. An explicit amount typed by a clerk still wins, in
 * Payroll, as it always did — paying a reimbursement outside the claim process is a legitimate correction.
 *
 * **Deletion is two calls rather than one, and that is not an accident of this contract.**
 * `expense_claims.payslip_id` is `nullOnDelete`, so by the time Eloquent's `deleted` hook runs the database
 * has already cut the link and there is nothing left to find. The claims have to be noted while the payslip
 * still exists and released once it is gone. The noted list stays in Payroll's hook, where it already lived,
 * rather than becoming state on an implementation that would then have to survive between two callbacks.
 *
 * `NoReimbursableClaims` is the default: nothing owed, nothing settled, nothing to release.
 */
interface ReimbursableClaims
{
    /**
     * The total of this employee's approved claims, or 0.0 when there are none.
     *
     * `$includingPayslipId` counts the claims this payslip is already carrying, so recalculating it does not
     * see its own settled claims as having disappeared.
     */
    public function reimbursableFor(int|string $employeeId, int|string|null $includingPayslipId = null): float;

    /**
     * Settle as many approved claims against the payslip as its reimbursement covers, oldest first.
     *
     * Idempotent per payslip, like the advance recovery, and for the same reason: payroll recalculates on
     * every save.
     */
    public function settleForPayslip(PayslipSettlement $settlement): void;

    /**
     * The claims a payslip is carrying, asked for **before** it is deleted.
     *
     * @return array<int, int|string> claim identifiers, to be handed back to `releaseAll()`
     */
    public function pendingReleaseFor(int|string $payslipId): array;

    /**
     * Return the noted claims to "approved and unpaid" — a claim whose payslip is gone is owed again.
     *
     * @param  array<int, int|string>  $claimIds
     */
    public function releaseAll(array $claimIds): void;
}
