<?php

namespace App\Modules\Advances\Services;

use App\Modules\Advances\Models\Advance;
use App\Modules\Advances\Models\AdvanceRecovery;
use App\Modules\Payroll\Models\Payslip;
use Illuminate\Support\Collection;
use InvalidArgumentException;

/**
 * Recovering advances through payroll.
 *
 * The payslip's `advances` deduction used to be typed in — from the employee's
 * settings, or by hand each month — which recorded what was taken but never what
 * was left. It now comes from here, so the figure on the payslip and the balance
 * owed are the same fact rather than two.
 */
class AdvanceService
{
    /**
     * What payroll should deduct from this employee this month.
     *
     * Zero when there is no active advance, which leaves the existing behaviour
     * untouched for everybody who has not been lent anything.
     */
    public function instalmentFor(int $employeeId, ?int $excludingPayslipId = null): float
    {
        return round(
            $this->activeFor($employeeId, $excludingPayslipId)
                ->sum(fn (Advance $advance): float => $advance->instalmentDue($excludingPayslipId)),
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
     * @return Collection<int, AdvanceRecovery>
     */
    public function recordRecoveryFor(Payslip $payslip): Collection
    {
        $advances = $this->allAdvancesFor($payslip->employee_id);
        $remaining = round((float) $payslip->advances, 2);

        // Measured without this payslip's own recovery, so a re-save allocates
        // against the balances as they stood before it.
        $room = [];
        $allocation = [];

        foreach ($advances as $advance) {
            $room[$advance->getKey()] = $advance->remainingAmount(excludingPayslipId: $payslip->id);
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
            $existing = $advance->recoveries()->where('payslip_id', $payslip->id)->first();
            $amount = round($allocation[$advance->getKey()], 2);

            if ($amount <= 0) {
                $existing?->delete();
                $advance->refresh()->settleIfCleared();

                continue;
            }

            if ($existing) {
                $existing->update(['amount' => $amount, 'recovered_on' => $this->recoveryDate($payslip)]);
                $recorded->push($existing);
            } else {
                $recorded->push($advance->recoveries()->create([
                    // Without this the row belongs to no payslip: the lookup above
                    // never matches, every save adds another, and deleting the
                    // payslip cascades nothing.
                    'payslip_id' => $payslip->id,
                    'amount' => $amount,
                    'recovered_on' => $this->recoveryDate($payslip),
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
    protected function allAdvancesFor(int $employeeId): Collection
    {
        return Advance::where('employee_id', $employeeId)
            ->whereIn('status', [Advance::STATUS_ACTIVE, Advance::STATUS_SETTLED])
            ->orderBy('started_on')
            ->get();
    }

    protected function recoveryDate(Payslip $payslip): string
    {
        return ($payslip->employee_reviewed_at ?? $payslip->created_at ?? now())->toDateString();
    }
}
