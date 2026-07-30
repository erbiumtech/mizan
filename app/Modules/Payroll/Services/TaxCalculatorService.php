<?php

namespace App\Modules\Payroll\Services;

use App\Modules\Payroll\Models\SalarySlab;

class TaxCalculatorService
{
    /**
     * Calculate annual income tax for a taxable annual income using the
     * salary slabs of the given fiscal year.
     *
     * Slabs store min_amount as the "exceeding" threshold (e.g. 600000),
     * so tax = fixed_tax + percentage% of (income - min_amount).
     */
    public function annualTax(float $annualTaxable, int $fiscalYearId): float
    {
        if ($annualTaxable <= 0) {
            return 0.0;
        }

        $slab = SalarySlab::where('fiscal_year_id', $fiscalYearId)
            ->where('min_amount', '<', $annualTaxable)
            ->where(function ($q) use ($annualTaxable) {
                $q->where('max_amount', '>=', $annualTaxable)->orWhereNull('max_amount');
            })
            ->orderByDesc('min_amount')
            ->first();

        if (! $slab) {
            return 0.0;
        }

        $tax = (float) $slab->fixed_tax
            + ($annualTaxable - (float) $slab->min_amount) * (float) $slab->percentage / 100;

        return round(max(0, $tax), 2);
    }

    public function monthlyTax(float $annualTaxable, int $fiscalYearId): float
    {
        return round($this->annualTax($annualTaxable, $fiscalYearId) / 12, 2);
    }
}
