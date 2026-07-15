<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\SalarySlab;
use App\Models\FiscalYear;

class SalarySlabSeeder extends Seeder
{
    public function run()
    {
        $fiscalYear = FiscalYear::where('name', '2026-2027')->first()
            ?? FiscalYear::where('is_active', true)->first();
        $fyId = $fiscalYear ? $fiscalYear->id : 1;

        // FBR salaried-person slabs (Finance Act 2025, per salary_tax.xlsx Rate sheet).
        // min_amount is the "exceeding" threshold: tax = fixed_tax + percentage% of (income - min_amount).
        $slabs = [
            ['min_amount' => 0,       'max_amount' => 600000,  'fixed_tax' => 0,      'percentage' => 0],
            ['min_amount' => 600000,  'max_amount' => 1200000, 'fixed_tax' => 0,      'percentage' => 1],
            ['min_amount' => 1200000, 'max_amount' => 2200000, 'fixed_tax' => 6000,   'percentage' => 11],
            ['min_amount' => 2200000, 'max_amount' => 3200000, 'fixed_tax' => 116000, 'percentage' => 23],
            ['min_amount' => 3200000, 'max_amount' => 4100000, 'fixed_tax' => 346000, 'percentage' => 30],
            ['min_amount' => 4100000, 'max_amount' => null,    'fixed_tax' => 616000, 'percentage' => 35],
        ];

        // Replace stale slabs (old thresholds/rates) for this fiscal year.
        SalarySlab::where('fiscal_year_id', $fyId)->delete();

        foreach ($slabs as $slab) {
            SalarySlab::create($slab + ['fiscal_year_id' => $fyId]);
        }
    }
}
