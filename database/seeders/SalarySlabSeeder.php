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
        //
        // VERIFIED against the Finance Act 2026 (presidential assent 25 June 2026,
        // effective 1 July 2026). Eight brackets, 0/1/11/20/25/29/32/35, top
        // bracket "1,424,000 + 35% above 7,000,000". This is the enacted salaried
        // schedule for tax year 2027, and it is what payroll uses, since
        // FiscalYearSeeder activates both years and FiscalYear::booted() stands
        // down whichever came first, leaving 2026-2027 current.
        //
        // Recorded because I got this wrong once: the eight-bracket shape and the
        // 20/25/29/32 middle look like a departure from the six-bracket
        // 2025-2026 schedule, and I flagged them as probably provisional. They
        // are not — the Act genuinely restructured the salaried brackets, and
        // this table is right. Two independent sources plus a mobility-tax firm's
        // summary agree on every figure.
        //
        // Note the 9% surcharge under s.4AB on taxable income over 10,000,000 was
        // withdrawn for salaried individuals by the same Act. Payroll never
        // implemented that surcharge, so nothing here needs removing — but if it
        // is ever added, it must not be applied to a 2026-2027 salary.
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
