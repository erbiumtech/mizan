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
 *  - SALARIED, both years: verified. 2025-2026 is the Finance Act 2025 schedule
 *    for tax year 2026; 2026-2027 is the Finance Act 2026 schedule (assent
 *    25 June 2026), which restructured the brackets from six to eight. Both
 *    match payroll's SalarySlabSeeder for the same years, so the two calculators
 *    cannot disagree.
 *  - BUSINESS: the 45% ceiling above 5,600,000 is confirmed, and the schedule was
 *    not restructured by the 2026 Act. The intermediate brackets are the
 *    long-standing non-salaried table.
 *  - RENTAL and CAPITAL GAINS: INDICATIVE flat bands, not statutory schedules.
 *    Both are genuinely more complicated than a bracket table — property income
 *    has its own rates and deductions, and capital gains depend on the asset and
 *    the holding period. They exist so the regime works end to end and so the
 *    figure is never silently zero, and the Tax Estimate screen says outright
 *    that they are approximate.
 *
 * NOT MODELLED, deliberately: surcharges. The 9% surcharge on salaried income
 * over 10,000,000 was withdrawn by the 2026 Act, but the 10% equivalent for
 * non-salaried individuals and AOPs was not. The estimate does not apply either,
 * and says so on screen — a half-applied surcharge is worse than an absent one.
 *
 * Only seeded for years that exist, and only where nothing is seeded yet — a
 * re-run will not overwrite rates somebody has corrected by hand. That is the
 * opposite of SalarySlabSeeder, which deletes and recreates.
 */
class TaxScheduleSeeder extends Seeder
{
    /**
     * Non-salaried individuals and AOPs. Opens at 15% over the exempt threshold
     * and tops out at 45% above 5,600,000 — the 45% ceiling is confirmed by the
     * Finance Act 2026 summaries, and the schedule was not restructured the way
     * the salaried one was.
     *
     * A 10% surcharge on tax payable above 10,000,000 applies to this category
     * and was NOT withdrawn for it. Deliberately not implemented: the estimate
     * does not model surcharges at all, and the screen says so, which is better
     * than a half-applied one.
     *
     * @var array<int, array<string, mixed>>
     */
    private const BUSINESS_BRACKETS = [
        ['min_amount' => 0,       'max_amount' => 600000,  'fixed_tax' => 0,       'percentage' => 0],
        ['min_amount' => 600000,  'max_amount' => 1200000, 'fixed_tax' => 0,       'percentage' => 15],
        ['min_amount' => 1200000, 'max_amount' => 1600000, 'fixed_tax' => 90000,   'percentage' => 20],
        ['min_amount' => 1600000, 'max_amount' => 3200000, 'fixed_tax' => 170000,  'percentage' => 30],
        ['min_amount' => 3200000, 'max_amount' => 5600000, 'fixed_tax' => 650000,  'percentage' => 40],
        ['min_amount' => 5600000, 'max_amount' => null,    'fixed_tax' => 1610000, 'percentage' => 45],
    ];

    /**
     * INDICATIVE, not the statutory schedule. Property income has its own rate
     * table and its own deductions, and I could not find an authoritative
     * enacted version to encode. A single flat band over an exempt threshold
     * keeps the regime working end to end and keeps the number non-zero; the Tax
     * Estimate screen labels it as approximate.
     *
     * @var array<int, array<string, mixed>>
     */
    private const RENTAL_BRACKETS = [
        ['min_amount' => 0,      'max_amount' => 300000, 'fixed_tax' => 0, 'percentage' => 0],
        ['min_amount' => 300000, 'max_amount' => null,   'fixed_tax' => 0, 'percentage' => 15],
    ];

    /**
     * INDICATIVE, and the least trustworthy of the four. Real capital gains tax
     * depends on the asset class and the holding period — securities, immovable
     * property and debt instruments are all treated differently, and the 2026 Act
     * moved the withholding rate on debt-security disposals from 15% to 20%.
     * A flat band cannot represent that and is not trying to.
     *
     * @var array<int, array<string, mixed>>
     */
    private const CAPITAL_GAINS_BRACKETS = [
        ['min_amount' => 0, 'max_amount' => null, 'fixed_tax' => 0, 'percentage' => 15],
    ];

    public function run(): void
    {
        $this->seedYear('2025-2026', [
            // Finance Act 2025, tax year 2026. Matches payroll's SalarySlabSeeder
            // for the same year, which is independently verifiable against the Act.
            TaxSchedule::REGIME_SALARIED => [
                ['min_amount' => 0,       'max_amount' => 600000,  'fixed_tax' => 0,      'percentage' => 0],
                ['min_amount' => 600000,  'max_amount' => 1200000, 'fixed_tax' => 0,      'percentage' => 1],
                ['min_amount' => 1200000, 'max_amount' => 2200000, 'fixed_tax' => 6000,   'percentage' => 11],
                ['min_amount' => 2200000, 'max_amount' => 3200000, 'fixed_tax' => 116000, 'percentage' => 23],
                ['min_amount' => 3200000, 'max_amount' => 4100000, 'fixed_tax' => 346000, 'percentage' => 30],
                ['min_amount' => 4100000, 'max_amount' => null,    'fixed_tax' => 616000, 'percentage' => 35],
            ],
            TaxSchedule::REGIME_BUSINESS => self::BUSINESS_BRACKETS,
            TaxSchedule::REGIME_RENTAL => self::RENTAL_BRACKETS,
            TaxSchedule::REGIME_CAPITAL_GAINS => self::CAPITAL_GAINS_BRACKETS,
        ]);

        $this->seedYear('2026-2027', [
            // Finance Act 2026 (assent 25 June 2026, effective 1 July 2026).
            // The Act restructured the salaried brackets from six to eight;
            // identical to what payroll seeds for the same year.
            TaxSchedule::REGIME_SALARIED => [
                ['min_amount' => 0,       'max_amount' => 600000,  'fixed_tax' => 0,       'percentage' => 0],
                ['min_amount' => 600000,  'max_amount' => 1200000, 'fixed_tax' => 0,       'percentage' => 1],
                ['min_amount' => 1200000, 'max_amount' => 2200000, 'fixed_tax' => 6000,    'percentage' => 11],
                ['min_amount' => 2200000, 'max_amount' => 3200000, 'fixed_tax' => 116000,  'percentage' => 20],
                ['min_amount' => 3200000, 'max_amount' => 4100000, 'fixed_tax' => 316000,  'percentage' => 25],
                ['min_amount' => 4100000, 'max_amount' => 5600000, 'fixed_tax' => 541000,  'percentage' => 29],
                ['min_amount' => 5600000, 'max_amount' => 7000000, 'fixed_tax' => 976000,  'percentage' => 32],
                ['min_amount' => 7000000, 'max_amount' => null,    'fixed_tax' => 1424000, 'percentage' => 35],
            ],
            // Unchanged by the 2026 Act as far as the brackets go: the maximum
            // rate for non-salaried individuals remains 45%.
            TaxSchedule::REGIME_BUSINESS => self::BUSINESS_BRACKETS,
            TaxSchedule::REGIME_RENTAL => self::RENTAL_BRACKETS,
            TaxSchedule::REGIME_CAPITAL_GAINS => self::CAPITAL_GAINS_BRACKETS,
        ]);
    }

    /**
     * @param  array<string, array<int, array<string, mixed>>>  $schedules
     */
    private function seedYear(string $yearName, array $schedules): void
    {
        $year = FiscalYear::where('name', $yearName)->first();

        if (! $year) {
            return;
        }

        foreach ($schedules as $regime => $brackets) {
            $this->seedRegime($year->id, $regime, $brackets);
        }
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
