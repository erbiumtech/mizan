<?php

namespace App\Modules\Advances\Services;

use App\Modules\Advances\Models\Advance;
use App\Modules\Advances\Models\AdvanceRecovery;
use App\Support\Contracts\AdvanceLedger;
use App\Support\PayslipSettlement;
use Illuminate\Support\Collection;
use InvalidArgumentException;

/**
 * Recovering advances through payroll.
 *
 * The payslip's `advances` deduction used to be typed in — from the employee's
 * settings, or by hand each month — which recorded what was taken but never what
 * was left. It now comes from here, so the figure on the payslip and the balance
 * owed are the same fact rather than two.
 *
 * **Payroll asks; it does not name this class.** `advances` requires `payroll`, so Payroll reaching back here
 * was a two-cycle between a module and the one it declares — the last cycle in the application, together with
 * the identical one through Expenses. Bound to `App\Support\Contracts\AdvanceLedger` from
 * `AdvancesServiceProvider`; see docs/module-packaging-plan.md §11. This module may still name `Payslip`
 * freely, and does not need to: the settlement carries the four values these methods ever read from one.
 *
 * The licence guard lives here rather than in Payroll's model hooks, for the same reason it lives in
 * `PayrollRunPeriodLock`: an unlicensed module answering for itself is one fewer thing the caller has to know.
 */
class AdvanceService implements AdvanceLedger
{
    /**
     * What payroll should deduct from this employee this month.
     *
     * Zero when there is no active advance, which leaves the existing behaviour
     * untouched for everybody who has not been lent anything.
     */
    public function instalmentFor(int|string $employeeId, int|string|null $excludingPayslipId = null): float
    {
        if (! modules()->enabled('advances')) {
            return 0.0;
        }

        // Normalised once. The contract widens these to `int|string` because an identifier crossing a module
        // boundary should not care which the far side uses, but the two calls below must be given the same
        // value or they disagree about which payslip to exclude — and that is a wrong deduction, not a
        // type warning.
        $excluding = $excludingPayslipId === null ? null : (int) $excludingPayslipId;

        return round(
            $this->activeFor((int) $employeeId, $excluding)
                ->sum(fn (Advance $advance): float => $advance->instalmentDue($excluding)),
            2
        );
    }

    /**
     * @return Collection<int, Advance>
     */
    public function activeFor(int $employeeId, ?int $excludingPayslipId = null): Collection
    {
        return Advance::active()
            ->where('employee_id', $employeeId)
            ->orderBy('started_on')
            ->get()
            ->filter(fn (Advance $advance): bool => $advance->remainingAmount($excludingPayslipId) > 0)
            ->values();
    }

    /**
     * Book this payslip's deduction against the employee's advances, oldest
     * first, and settle any that clear.
     *
     * Idempotent per payslip: re-saving a payslip updates its recovery rather
     * than adding another, because payroll recalculates on every save and a
     * second row would take the instalment twice. A payslip whose deduction is
     * reduced to nothing gives the recovery back.
     *
     * Takes a `PayslipSettlement` rather than a `Payslip` because the contract Payroll asks through cannot
     * name a Payroll model — see App\Support\PayslipSettlement. The four values it carries are all this
     * method ever read from one.
     */
    public function recordRecoveryFor(PayslipSettlement $settlement): void
    {
        if (! modules()->enabled('advances')) {
            return;
        }

        $this->recover($settlement);
    }

    /**
     * The allocation itself, returning what it wrote.
     *
     * Separate from the contract method so the return value survives: the interface is `void` because no
     * caller across the boundary uses it, but this module's own tests assert on the recovery rows.
     *
     * @return Collection<int, AdvanceRecovery>
     */
    public function recover(PayslipSettlement $settlement): Collection
    {
        $advances = $this->allAdvancesFor($settlement->employeeId);
        $remaining = round($settlement->amount, 2);
        $payslipId = (int) $settlement->payslipId;

        // Measured without this payslip's own recovery, so a re-save allocates
        // against the balances as they stood before it.
        $room = [];
        $allocation = [];

        foreach ($advances as $advance) {
            $room[$advance->getKey()] = $advance->remainingAmount(excludingPayslipId: $payslipId);
            $allocation[$advance->getKey()] = 0.0;
        }

        // Each advance takes its own instalment, oldest first. Allocating purely
        // by what an advance can absorb would let the oldest swallow the whole
        // deduction and pay itself off years early while the others sat untouched.
        foreach ($advances as $advance) {
            if ($remaining <= 0) {
                break;
            }

            $due = min((float) $advance->monthly_instalment, $room[$advance->getKey()]);
            $take = round(min($remaining, max($due, 0)), 2);

            $allocation[$advance->getKey()] = $take;
            $remaining = round($remaining - $take, 2);
        }

        // Anything beyond the instalments — a clerk deducting extra this month —
        // goes against the remaining balances in the same order.
        foreach ($advances as $advance) {
            if ($remaining <= 0) {
                break;
            }

            $spare = round($room[$advance->getKey()] - $allocation[$advance->getKey()], 2);
            $take = round(min($remaining, max($spare, 0)), 2);

            $allocation[$advance->getKey()] += $take;
            $remaining = round($remaining - $take, 2);
        }

        $recorded = collect();

        foreach ($advances as $advance) {
            $existing = $advance->recoveries()->where('payslip_id', $payslipId)->first();
            $amount = round($allocation[$advance->getKey()], 2);

            if ($amount <= 0) {
                $existing?->delete();
                $advance->refresh()->settleIfCleared();

                continue;
            }

            if ($existing) {
                $existing->update(['amount' => $amount, 'recovered_on' => $settlement->effectiveOn]);
                $recorded->push($existing);
            } else {
                $recorded->push($advance->recoveries()->create([
                    // Without this the row belongs to no payslip: the lookup above
                    // never matches, every save adds another, and deleting the
                    // payslip cascades nothing.
                    'payslip_id' => $payslipId,
                    'amount' => $amount,
                    'recovered_on' => $settlement->effectiveOn,
                ]));
            }

            $advance->refresh()->settleIfCleared();
        }

        return $recorded;
    }

    /**
     * A repayment outside payroll — cash handed back, or a correction.
     */
    public function recordManualRecovery(Advance $advance, float $amount, string $on, ?string $note = null): AdvanceRecovery
    {
        if ($amount <= 0) {
            throw new InvalidArgumentException('A recovery must be a positive amount.');
        }

        if ($amount > $advance->remainingAmount()) {
            throw new InvalidArgumentException(
                'That is more than the '.number_format($advance->remainingAmount(), 2).' still outstanding.'
            );
        }

        $recovery = $advance->recoveries()->create([
            'amount' => round($amount, 2),
            'recovered_on' => $on,
            'note' => $note ?: 'Recorded by hand',
        ]);

        $advance->refresh()->settleIfCleared();

        return $recovery;
    }

    /**
     * Every advance that could still take a recovery, including ones already
     * settled — a settled advance may need to give a recovery back when the
     * payslip that cleared it is corrected downwards.
     *
     * @return Collection<int, Advance>
     */
    protected function allAdvancesFor(int|string $employeeId): Collection
    {
        return Advance::where('employee_id', $employeeId)
            ->whereIn('status', [Advance::STATUS_ACTIVE, Advance::STATUS_SETTLED])
            ->orderBy('started_on')
            ->get();
    }

    /**
     * Reopen anything a now-deleted payslip had settled.
     *
     * The money was never taken, so a balance that reached zero only because of that recovery is owed again.
     * The recovery rows cascade on `payslip_id` at the database level; the *status* is what needs correcting,
     * which is why this runs after the delete rather than before it.
     *
     * Lived in `Payslip::booted()`'s `deleted` hook, where it queried `Advance` directly — one of the two
     * references that made `payroll -> advances` a cycle. Deciding when an advance is owed again is this
     * module's judgement, so the loop came with it.
     */
    public function reopenSettledFor(int|string $employeeId): void
    {
        if (! modules()->enabled('advances')) {
            return;
        }

        Advance::where('employee_id', $employeeId)
            ->get()
            ->each(function (Advance $advance): void {
                $advance->refresh();

                if ($advance->status === Advance::STATUS_SETTLED && $advance->remainingAmount() > 0) {
                    $advance->update(['status' => Advance::STATUS_ACTIVE]);
                }
            });
    }
}
