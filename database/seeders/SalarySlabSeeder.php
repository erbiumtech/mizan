<?php

namespace Database\Seeders;

use App\Modules\Core\Models\FiscalYear;
use App\Modules\Payroll\Models\SalarySlab;
use Illuminate\Database\Seeder;

class SalarySlabSeeder extends Seeder
{
    public function run()
    {
        // ==========================================
        // 1. Fiscal Year 2025-2026 Slabs
        // ==========================================
        $fy2025 = FiscalYear::where('name', '2025-2026')->first();

        if ($fy2025) {
            $slabs2025 = [
                ['min_amount' => 0,       'max_amount' => 600000,  'fixed_tax' => 0,      'percentage' => 0],
                ['min_amount' => 600000,  'max_amount' => 1200000, 'fixed_tax' => 0,      'percentage' => 1],
                ['min_amount' => 1200000, 'max_amount' => 2200000, 'fixed_tax' => 6000,  'percentage' => 11],
                ['min_amount' => 2200000, 'max_amount' => 3200000, 'fixed_tax' => 116000, 'percentage' => 23],
                ['min_amount' => 3200000, 'max_amount' => 4100000, 'fixed_tax' => 346000, 'percentage' => 30],
                ['min_amount' => 4100000, 'max_amount' => null,    'fixed_tax' => 616000, 'percentage' => 35],
            ];

            SalarySlab::where('fiscal_year_id', $fy2025->id)->delete();

            foreach ($slabs2025 as $slab) {
                SalarySlab::create($slab + ['fiscal_year_id' => $fy2025->id]);
            }
        }

        // ==========================================
        // 2. Fiscal Year 2026-2027 Slabs
        // ==========================================
        $fy2026 = FiscalYear::where('name', '2026-2027')->first();

        if ($fy2026) {
            $slabs2026 = [
                ['min_amount' => 0,       'max_amount' => 600000,  'fixed_tax' => 0,      'percentage' => 0],
                ['min_amount' => 600000,  'max_amount' => 1200000, 'fixed_tax' => 0,      'percentage' => 1],
                ['min_amount' => 1200000, 'max_amount' => 2200000, 'fixed_tax' => 6000,   'percentage' => 11],
                ['min_amount' => 2200000, 'max_amount' => 3200000, 'fixed_tax' => 116000, 'percentage' => 20],
                ['min_amount' => 3200000, 'max_amount' => 4100000, 'fixed_tax' => 316000, 'percentage' => 25],
                ['min_amount' => 4100000, 'max_amount' => 5600000,    'fixed_tax' => 541000, 'percentage' => 29],
                ['min_amount' => 5600000, 'max_amount' => 7000000,    'fixed_tax' => 976000, 'percentage' => 32],
                // Null, not a figure. TaxCalculatorService matches
                // `max_amount >= income OR max_amount IS NULL`, so a bounded top
                // slab leaves everything above it matching no slab at all — and
                // the service returns 0.0 rather than raising, so the highest
                // earners are silently taxed at nothing. This used to read
                // 50,000,000.
                ['min_amount' => 7000000, 'max_amount' => null,    'fixed_tax' => 1424000, 'percentage' => 35],
            ];

            SalarySlab::where('fiscal_year_id', $fy2026->id)->delete();

            foreach ($slabs2026 as $slab) {
                SalarySlab::create($slab + ['fiscal_year_id' => $fy2026->id]);
            }
        }
    }
}
