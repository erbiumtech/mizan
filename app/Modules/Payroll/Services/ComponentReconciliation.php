<?php

namespace App\Modules\Payroll\Services;

use App\Modules\Payroll\Models\PayComponent;
use App\Modules\Payroll\Models\Payslip;
use App\Modules\Payroll\Models\PayslipComponent;
use Illuminate\Support\Collection;

/**
 * Do a payslip's recorded components add up to the payslip?
 *
 * This is the gate `docs/akaunting-gap-plan.md` item 9 named and never built: *"Done when:
 * every existing payslip's gross and net are identical before and after."* The backfill
 * migration checked it once, in 2026-08, and nothing has checked it since — so the answer for
 * a company's actual payroll history has been unknown ever since.
 *
 * **Why it can drift at all.** `PayComponentRecorder` copies the columns into components on
 * every save, so anything saved through the model agrees by construction. What does not go
 * through the model is the interesting part: a figure corrected with a raw `UPDATE`, a payslip
 * left by an older version of the calculation, a component deleted after it was paid, or a
 * restore from a backup taken mid-migration. Each of those leaves the components and the
 * stored totals disagreeing, silently, and every report that reads one of the two is then
 * wrong in a way nothing announces.
 *
 * **What it is for.** Two things. Standing assurance that the reporting projection matches the
 * money — the billing statement reads components now, so a drift here mis-bills a client. And
 * it is the precondition for ever retiring the columns: that is not a migration anybody should
 * run against a payroll this has not been run clean against first.
 *
 * Reads nothing but data and writes nothing at all, which is what makes it safe to run against
 * production on a whim.
 */
class ComponentReconciliation
{
    /**
     * Earning components that are deliberately outside the gross.
     *
     * `expense_reimbursement` is paid with salary but is not earned — it is the employee's own
     * money coming back, and `PayslipService` leaves it out of `total_earnings`. Counting it
     * here would report every payslip carrying one as broken.
     */
    private const OUTSIDE_GROSS = ['expense_reimbursement'];

    /** Tolerance, in rupees. Each component is stored to two places, so sums accumulate. */
    private const TOLERANCE = 0.01;

    /**
     * Every payslip whose components do not reconcile.
     *
     * @return Collection<int, array{
     *     payslip_id: int, employee: string, month: string,
     *     stored_earnings: float, component_earnings: float, earnings_difference: float,
     *     stored_deductions: float, component_deductions: float, deductions_difference: float,
     * }>
     */
    public function discrepancies(): Collection
    {
        return $this->all()->filter(
            fn (array $row): bool => abs($row['earnings_difference']) > self::TOLERANCE
                || abs($row['deductions_difference']) > self::TOLERANCE
        )->values();
    }

    /**
     * How many payslips there are to reconcile.
     *
     * Its own query rather than `all()->count()`, so the command can tell "everything
     * reconciles" from "there is nothing here" without walking the whole history to find out.
     */
    public function count(): int
    {
        return Payslip::query()->count();
    }

    /**
     * Every payslip, reconciled, whether or not it balances.
     *
     * Chunked rather than loaded: this runs over a company's entire payroll history, and a
     * few thousand payslips with their components is not something to hold in memory at once
     * on a command that exists to be run casually.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function all(): Collection
    {
        $kinds = PayComponent::query()->pluck('kind', 'id');
        $codes = PayComponent::query()->pluck('code', 'id');

        $rows = new Collection;

        Payslip::with('employee.user')
            ->orderBy('id')
            ->chunk(200, function (Collection $payslips) use ($kinds, $codes, &$rows): void {
                // One query per chunk rather than one per payslip: the whole point of this
                // being cheap to run is that somebody actually runs it.
                $components = PayslipComponent::query()
                    ->whereIn('payslip_id', $payslips->modelKeys())
                    ->get()
                    ->groupBy('payslip_id');

                foreach ($payslips as $payslip) {
                    $rows->push($this->reconcile(
                        $payslip,
                        $components->get($payslip->getKey()) ?? new Collection,
                        $kinds,
                        $codes,
                    ));
                }
            });

        return $rows;
    }

    /**
     * @param  Collection<int, PayslipComponent>  $components
     * @return array<string, mixed>
     */
    private function reconcile(Payslip $payslip, Collection $components, Collection $kinds, Collection $codes): array
    {
        $earnings = 0.0;
        $deductions = 0.0;

        foreach ($components as $row) {
            $kind = $kinds[$row->pay_component_id] ?? null;
            $code = $codes[$row->pay_component_id] ?? null;

            // A component row pointing at a component that no longer exists. Counted in
            // neither total, which makes the payslip report as a discrepancy — correctly: the
            // money is on the payslip and nothing can say what it was for.
            if ($kind === null) {
                continue;
            }

            if ($kind === PayComponent::KIND_DEDUCTION) {
                $deductions = round($deductions + (float) $row->amount, 2);

                continue;
            }

            if (in_array($code, self::OUTSIDE_GROSS, true)) {
                continue;
            }

            $earnings = round($earnings + (float) $row->amount, 2);
        }

        $storedEarnings = round((float) $payslip->total_earnings, 2);
        $storedDeductions = round((float) $payslip->total_deductions, 2);

        return [
            'payslip_id' => $payslip->getKey(),
            'employee' => $payslip->employee?->user?->name ?? 'Unnamed employee',
            'month' => (string) $payslip->month,
            'stored_earnings' => $storedEarnings,
            'component_earnings' => $earnings,
            'earnings_difference' => round($earnings - $storedEarnings, 2),
            'stored_deductions' => $storedDeductions,
            'component_deductions' => $deductions,
            'deductions_difference' => round($deductions - $storedDeductions, 2),
        ];
    }
}
