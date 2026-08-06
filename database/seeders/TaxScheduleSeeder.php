<?php

namespace Database\Seeders;

use App\Modules\Core\Models\FiscalYear;
use App\Modules\PersonalFinance\Models\TaxSchedule;
use Illuminate\Database\Seeder;

/**
 * Pakistani income tax brackets for the Personal Finance module's estimate.
 *
 * Rates are data, on purpose. A Finance Act should be a re-seed, not a code
 * change, which is the same reason payroll keeps its slabs in a table.
 *
 * SOURCE AND CONFIDENCE, because a wrong number here becomes a wrong number on
 * somebody's screen:
 *
 *  - The salaried brackets are the Finance Act 2025 schedule for tax year 2026
 *    (the July 2025 - June 2026 year). They match, bracket for bracket, what
 *    payroll's SalarySlabSeeder holds for 2025-2026, which is independently
 *    verifiable against that Act.
 *  - The business brackets are the corresponding non-salaried individual / AOP
 *    schedule for the same year.
 *  - Rental and capital gains are seeded as SINGLE FLAT BRACKETS and are the
 *    least trustworthy of the four. Both are genuinely more complicated than a
 *    slab table: property income has its own schedule, and capital gains
 *    depend on the asset and how long it was held. They are here so the regime
 *    exists end to end and so the number shown is not silently zero, and the
 *    Tax Estimate screen says outright that they are indicative.
 *
 * Only seeded for years that exist, and only where nothing is seeded yet — a
 * re-run will not overwrite rates somebody has corrected by hand. That is the
 * opposite of SalarySlabSeeder, which deletes and recreates.
 */
class TaxScheduleSeeder extends Seeder
{
    public function run(): void
    {
        $year = FiscalYear::where('name', '2025-2026')->first();

        if (! $year) {
            return;
        }

        $this->seedRegime($year->id, TaxSchedule::REGIME_SALARIED, [
            ['min_amount' => 0,       'max_amount' => 600000,  'fixed_tax' => 0,      'percentage' => 0],
            ['min_amount' => 600000,  'max_amount' => 1200000, 'fixed_tax' => 0,      'percentage' => 1],
            ['min_amount' => 1200000, 'max_amount' => 2200000, 'fixed_tax' => 6000,   'percentage' => 11],
            ['min_amount' => 2200000, 'max_amount' => 3200000, 'fixed_tax' => 116000, 'percentage' => 23],
            ['min_amount' => 3200000, 'max_amount' => 4100000, 'fixed_tax' => 346000, 'percentage' => 30],
            ['min_amount' => 4100000, 'max_amount' => null,    'fixed_tax' => 616000, 'percentage' => 35],
        ]);

        $this->seedRegime($year->id, TaxSchedule::REGIME_BUSINESS, [
            ['min_amount' => 0,       'max_amount' => 600000,  'fixed_tax' => 0,      'percentage' => 0],
            ['min_amount' => 600000,  'max_amount' => 1200000, 'fixed_tax' => 0,      'percentage' => 15],
            ['min_amount' => 1200000, 'max_amount' => 1600000, 'fixed_tax' => 90000,  'percentage' => 20],
            ['min_amount' => 1600000, 'max_amount' => 3200000, 'fixed_tax' => 170000, 'percentage' => 30],
            ['min_amount' => 3200000, 'max_amount' => 5600000, 'fixed_tax' => 650000, 'percentage' => 40],
            ['min_amount' => 5600000, 'max_amount' => null,    'fixed_tax' => 1610000, 'percentage' => 45],
        ]);

        // Indicative flat rates — see the class comment. A single unbounded
        // bracket means the estimate is never silently zero for these regimes,
        // and the screen labels them as approximate.
        $this->seedRegime($year->id, TaxSchedule::REGIME_RENTAL, [
            ['min_amount' => 0, 'max_amount' => 300000, 'fixed_tax' => 0, 'percentage' => 0],
            ['min_amount' => 300000, 'max_amount' => null, 'fixed_tax' => 0, 'percentage' => 15],
        ]);

        $this->seedRegime($year->id, TaxSchedule::REGIME_CAPITAL_GAINS, [
            ['min_amount' => 0, 'max_amount' => null, 'fixed_tax' => 0, 'percentage' => 15],
        ]);
    }

    /**
     * @param  array<int, array<string, mixed>>  $brackets
     */
    private function seedRegime(int $fiscalYearId, string $regime, array $brackets): void
    {
        $exists = TaxSchedule::where('fiscal_year_id', $fiscalYearId)
            ->where('regime', $regime)
            ->exists();

        if ($exists) {
            return;
        }

        foreach ($brackets as $bracket) {
            TaxSchedule::create($bracket + [
                'fiscal_year_id' => $fiscalYearId,
                'regime' => $regime,
            ]);
        }
    }
}
