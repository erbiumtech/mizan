<?php

namespace App\Modules\Expenses\Services;

use App\Modules\Expenses\Models\ExpenseClaim;
use App\Modules\Payroll\Models\Payslip;
use Illuminate\Support\Collection;

/**
 * Getting an approved claim paid.
 *
 * Reimbursement rides on the payslip, because that is where the money already
 * reached the employee: `payslips.expense_reimbursement` existed before claims did
 * and is already added to net pay and posted by PayrollPostingService. What was
 * missing was any record of what the figure was for.
 *
 * The shape is AdvanceService's, for the same reason: payroll recalculates a payslip
 * on every save, so anything attached to one has to be idempotent per payslip or the
 * employee is reimbursed twice.
 */
class ExpenseClaimService
{
    /**
     * What this employee is owed back, and not yet paid.
     *
     * Claims already settled against *this* payslip count, because the payslip is
     * being recalculated and its own figure must not shrink out from under it — the
     * same exclusion AdvanceService makes for a payslip's own recovery.
     */
    public function reimbursableFor(int $employeeId, ?int $includingPayslipId = null): float
    {
        return round((float) ExpenseClaim::query()
            ->where('employee_id', $employeeId)
            ->where(function ($query) use ($includingPayslipId): void {
                $query->where('status', ExpenseClaim::STATUS_APPROVED)
                    ->when(
                        $includingPayslipId,
                        fn ($q) => $q->orWhere(fn ($own) => $own
                            ->where('status', ExpenseClaim::STATUS_SETTLED)
                            ->where('payslip_id', $includingPayslipId)),
                    );
            })
            ->sum('amount'), 2);
    }

    /**
     * @return Collection<int, ExpenseClaim>
     */
    public function awaitingSettlementFor(int $employeeId): Collection
    {
        return ExpenseClaim::awaitingSettlement()
            ->where('employee_id', $employeeId)
            ->orderBy('claimed_on')
            ->get();
    }

    /**
     * Attach the claims this payslip reimburses, and release any it no longer does.
     *
     * Called on every payslip save, so it has to converge rather than accumulate: a
     * payslip whose reimbursement is edited down releases the claims it can no longer
     * cover, and one edited to nothing releases all of them.
     *
     * @return Collection<int, ExpenseClaim> the claims now settled by this payslip
     */
    public function settleAgainst(Payslip $payslip): Collection
    {
        $budget = round((float) $payslip->expense_reimbursement, 2);

        // Its own claims first: they are already counted in the figure above, and
        // dropping them to make room for a newer claim would be churn for nothing.
        $candidates = ExpenseClaim::query()
            ->where('employee_id', $payslip->employee_id)
            ->where(fn ($query) => $query
                ->where('payslip_id', $payslip->getKey())
                ->orWhere('status', ExpenseClaim::STATUS_APPROVED))
            ->orderByRaw('CASE WHEN payslip_id = ? THEN 0 ELSE 1 END', [$payslip->getKey()])
            ->orderBy('claimed_on')
            ->get();

        $settled = collect();

        foreach ($candidates as $claim) {
            $amount = round((float) $claim->amount, 2);

            if ($amount <= $budget) {
                $budget = round($budget - $amount, 2);

                if ($claim->payslip_id !== $payslip->getKey() || ! $claim->isSettled()) {
                    $claim->update([
                        'status' => ExpenseClaim::STATUS_SETTLED,
                        'payslip_id' => $payslip->getKey(),
                    ]);
                }

                $settled->push($claim);

                continue;
            }

            // Doesn't fit. If this payslip was carrying it, it no longer is —
            // partial reimbursement of one claim is not a thing this models.
            if ($claim->payslip_id === $payslip->getKey()) {
                $this->release($claim);
            }
        }

        return $settled;
    }

    /**
     * Put a claim back to approved and unpaid — when the payslip that reimbursed it
     * is deleted, or no longer covers it.
     */
    public function release(ExpenseClaim $claim): ExpenseClaim
    {
        $claim->update([
            'status' => ExpenseClaim::STATUS_APPROVED,
            'payslip_id' => null,
        ]);

        return $claim;
    }

    /**
     * Every claim a payslip is reimbursing, released.
     *
     * Only usable while the payslip still exists: expense_claims.payslip_id is
     * nullOnDelete, so once it is gone the link is gone with it and there is nothing
     * left to find. Payslip::booted() notes the ids before deleting for that reason.
     */
    public function releaseAllFor(Payslip $payslip): int
    {
        $claims = ExpenseClaim::where('payslip_id', $payslip->getKey())->get();

        foreach ($claims as $claim) {
            $this->release($claim);
        }

        return $claims->count();
    }
}
