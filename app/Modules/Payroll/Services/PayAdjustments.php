<?php

namespace App\Modules\Payroll\Services;

use App\Modules\Payroll\Models\PayAdjustment;
use App\Modules\Payroll\Models\Payslip;

/**
 * Adjustments into the input store — docs/saas-launch-plan.md §14.1.
 *
 * The store holds a typed figure per override key that outranks the package.
 * An adjustment adds to what the month would otherwise pay for that key —
 * the package's bonus plus this bonus, the package's advance recovery plus this
 * one — so the typed figure written is fallback plus adjustments, and the
 * payslip recalculates from it exactly as if a clerk had typed the sum.
 */
class PayAdjustments
{
    public function __construct(private readonly PayslipService $payslips) {}

    /** @return array<string, float> component code => adjustment total, override keys only */
    public function totalsFor(int $employeeId, string $month, int $fiscalYearId): array
    {
        return PayAdjustment::query()
            ->where('employee_id', $employeeId)
            ->where('month', $month)
            ->where('fiscal_year_id', $fiscalYearId)
            ->with('component:id,code')
            ->get()
            ->toBase()
            ->groupBy(fn (PayAdjustment $a): string => (string) $a->component?->code)
            ->only(PayslipService::OVERRIDE_KEYS)
            ->map(fn ($group): float => round((float) $group->sum('amount'), 2))
            ->filter(fn (float $amount): bool => abs($amount) >= 0.01)
            ->all();
    }

    /** The payslip for the month, if any and still open, recalculated with the adjustments in. */
    public function refresh(int $employeeId, string $month, int $fiscalYearId): ?Payslip
    {
        $payslip = Payslip::query()
            ->where('employee_id', $employeeId)
            ->where('month', $month)
            ->where('fiscal_year_id', $fiscalYearId)
            ->first();

        if ($payslip === null || $payslip->payrollRun?->isLocked()) {
            return null;
        }

        return $this->applyTo($payslip);
    }

    public function applyTo(Payslip $payslip): Payslip
    {
        $totals = $this->totalsFor((int) $payslip->employee_id, $payslip->month, (int) $payslip->fiscal_year_id);

        // What the month would pay without any adjustment: the package, the ledgers, the claims.
        $fallback = $this->payslips->calculateByParams($payslip->employee_id, $payslip->month, $payslip->fiscal_year_id, [], $payslip->id, null, false) ?: [];
        $typed = $this->payslips->typedOverridesOf($payslip);

        foreach (PayslipService::OVERRIDE_KEYS as $key) {
            if (! isset($totals[$key])) {
                continue;
            }

            $typed[$key] = round((float) ($fallback[$key] ?? 0) + $totals[$key], 2);
        }

        $payslip->typedOverrides = $typed;

        // An override alone leaves no column dirty, so Eloquent would skip the save and the
        // recalculation in its hook — the edit page forces it the same way.
        if (! $payslip->isDirty()) {
            $stamp = $payslip->freshTimestamp();
            // Within the same second as the last write the fresh stamp is not a change either.
            $payslip->updated_at = $payslip->updated_at !== null && $stamp->equalTo($payslip->updated_at) ? $stamp->copy()->addSecond() : $stamp;
        }

        $payslip->save();

        PayAdjustment::query()
            ->where('employee_id', $payslip->employee_id)->where('month', $payslip->month)->where('fiscal_year_id', $payslip->fiscal_year_id)
            ->whereNull('payslip_id')
            ->update(['payslip_id' => $payslip->getKey()]);

        return $payslip->refresh();
    }
}
