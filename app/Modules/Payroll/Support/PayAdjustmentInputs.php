<?php

namespace App\Modules\Payroll\Support;

use App\Modules\Payroll\Models\PayAdjustment;
use App\Modules\Payroll\Models\PayComponent;
use App\Support\Contracts\PayrollInputs;

/** The contract's Payroll side: a dated adjustment record, which reaches the payslip's input store — §14.1. */
class PayAdjustmentInputs implements PayrollInputs
{
    public function add(int $employeeId, string $month, int $fiscalYearId, string $componentCode, float $amount, string $reason): void
    {
        $component = PayComponent::query()->where('code', $componentCode)->first();

        if ($component === null || abs($amount) < 0.01) {
            return;
        }

        PayAdjustment::create([
            'employee_id' => $employeeId,
            'fiscal_year_id' => $fiscalYearId,
            'month' => $month,
            'pay_component_id' => $component->getKey(),
            'amount' => round($amount, 2),
            'reason' => $reason,
        ]);
    }
}
