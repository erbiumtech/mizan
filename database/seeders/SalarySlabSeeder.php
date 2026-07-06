<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\SalarySlab;
use App\Models\FiscalYear;

class SalarySlabSeeder extends Seeder
{
    public function run()
    {
        // Pehle fiscal year ki ID dhoondhein (2026-2027)
        $fiscalYear = FiscalYear::where('name', '2026-2027')->first();
        $fyId = $fiscalYear ? $fiscalYear->id : 1; // Agar na mile to default 1 use karein

        $slabs = [
            ['min_amount' => 0, 'max_amount' => 600000, 'fixed_tax' => 0, 'percentage' => 0],
            ['min_amount' => 600001, 'max_amount' => 1200000, 'fixed_tax' => 0, 'percentage' => 1],
            ['min_amount' => 1200001, 'max_amount' => 2200000, 'fixed_tax' => 6000, 'percentage' => 11],
            ['min_amount' => 2200001, 'max_amount' => 3200000, 'fixed_tax' => 116000, 'percentage' => 20],
            ['min_amount' => 3200001, 'max_amount' => 4100000, 'fixed_tax' => 316000, 'percentage' => 25],
            ['min_amount' => 4100001, 'max_amount' => 5600000, 'fixed_tax' => 541000, 'percentage' => 29],
            ['min_amount' => 5600001, 'max_amount' => 7000000, 'fixed_tax' => 976000, 'percentage' => 32],
            ['min_amount' => 7000001, 'max_amount' => null, 'fixed_tax' => 1424000, 'percentage' => 35],
        ];

        foreach ($slabs as $slab) {
            SalarySlab::updateOrCreate(
                [
                    'fiscal_year_id' => $fyId,
                    'min_amount' => $slab['min_amount']
                ],
                $slab
            );
        }
    }
}
