<?php

namespace App\Support\Contracts;

use App\Support\PayslipSettlement;

/**
 * What an employee still owes on a salary advance, and the recording of what payroll took.
 *
 * `advances` **requires** `payroll` — an advance is repaid out of salary, so the module is unsellable without
 * it — and Payroll reached back to `Advances\Services\AdvanceService` and `Advances\Models\Advance` to put the
 * instalment on a payslip. That is a two-cycle between a module and the one it declares, and with `expenses`
 * in the same shape it was the last cycle left after the linchpin came out. See
 * docs/module-packaging-plan.md §11.
 *
 * **The direction that is correct is the one the licence already points.** Advances may name `Payslip`
 * freely; Payroll may name nothing of Advances. So Payroll asks this, Advances binds it, and the deduction
 * still lands in `payslips.advances` — a column Payroll owns, whose value comes from a ledger it does not.
 *
 * `NoAdvanceLedger` is the default: nothing owed, nothing recorded, nothing to reopen. That is also what an
 * *unlicensed* Advances must look like, and that guard lives in the implementation rather than in Payroll's
 * hooks — Payroll asking "is advances enabled" is Payroll knowing about Advances by another name. The
 * fallback behaviour is unchanged either way: an instalment of `0.0` makes `PayslipService` fall through to
 * the figure on the employee's settings, exactly as the old guard did.
 */
interface AdvanceLedger
{
    /**
     * This month's instalment across every active advance, or 0.0 when there is none.
     *
     * `$excludingPayslipId` measures the balance as it stood *before* the payslip being recalculated, so a
     * re-save allocates against the same room the first save saw rather than compounding its own recovery.
     */
    public function instalmentFor(int|string $employeeId, int|string|null $excludingPayslipId = null): float;

    /**
     * Book what the payslip deducted against the employee's advances, oldest first.
     *
     * Idempotent per payslip: payroll recalculates on every save — a clerk correcting a phone number re-saves
     * the payslip — and a second row would recover the same instalment twice.
     */
    public function recordRecoveryFor(PayslipSettlement $settlement): void;

    /**
     * Reopen anything that a now-deleted payslip had settled.
     *
     * The money was never taken, so a balance that reached zero only because of that recovery is owed again.
     * The recovery rows themselves cascade on `payslip_id`; the *status* is what needs correcting.
     */
    public function reopenSettledFor(int|string $employeeId): void;
}
