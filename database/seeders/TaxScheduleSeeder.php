<?php

namespace Database\Seeders;

use App\Modules\Core\Models\FiscalYear;
use App\Modules\PersonalFinance\Models\TaxSchedule;
use App\Modules\PersonalFinance\Models\TaxSurcharge;
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
     * Property income is NOT taxed on its own rate table.
     *
     * The separate property-income schedule was abolished in 2019: for an
     * individual, net rental is added to total income and taxed at the ordinary
     * slabs. So this deliberately mirrors the non-salaried brackets rather than
     * inventing a rental-specific table, and PersonalTaxService applies the
     * automatic one-fifth repair allowance (s.15A) before taxing it.
     *
     * It was a flat 15% band here at first, which was simply wrong — a made-up
     * rate on a gross figure that should never have been taxed gross.
     *
     * Remaining simplification, stated on screen: a real return aggregates rental
     * with the taxpayer's other income and applies one schedule to the total,
     * whereas this assesses each head separately. For somebody whose only income
     * is rent the two agree; for somebody with a salary as well, the separate
     * assessment understates.
     *
     * @var array<int, array<string, mixed>>
     */
    private const RENTAL_BRACKETS = self::BUSINESS_BRACKETS;

    /**
     * Capital gains: a flat 15%, which is the enacted rate for the mainstream
     * case rather than a placeholder.
     *
     * For a filer, gains on securities (s.37A) and on immovable property (s.37)
     * acquired on or after 1 July 2024 are taxed at a flat 15% with no
     * holding-period relief — the holding-period tables were retired for assets
     * acquired from that date.
     *
     * Two cases this does NOT cover, and the screen says so:
     *  - assets acquired between 1 July 2022 and 30 June 2024, which still use the
     *    old holding-period slabs;
     *  - non-filers, who are taxed at slab rates instead of the flat 15%.
     *
     * Both depend on facts about the asset that the ledger does not record, so
     * they cannot be inferred here.
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

        // Section 4AB, tax year 2026: 9% of the tax for salaried, 10% for
        // non-salaried and AOPs, once taxable income passes 10,000,000. Rental is
        // taxed on the non-salaried schedule, so it carries the same 10%.
        //
        // Capital gains has no row: the flat 15% under s.37/37A is a separate
        // block charge and the surcharge does not sit on top of it.
        $this->seedSurcharges('2025-2026', [
            TaxSchedule::REGIME_SALARIED => ['threshold' => 10000000, 'percentage' => 9],
            TaxSchedule::REGIME_BUSINESS => ['threshold' => 10000000, 'percentage' => 10],
            TaxSchedule::REGIME_RENTAL => ['threshold' => 10000000, 'percentage' => 10],
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

        // Tax year 2027: NO salaried row, because the Finance Act 2026 withdrew
        // the 9% surcharge for individuals deriving salary income. Expressing
        // that as an absent row rather than a code branch is the point of keeping
        // surcharges as data.
        //
        // The non-salaried 10% is kept. Reporting on this is less consistent than
        // on the salaried withdrawal — one summary says s.4AB is abolished
        // outright from tax year 2027, others describe only the salaried
        // withdrawal. Kept because an estimate that overstates is the safer error
        // for somebody planning around it, and because removing a charge on the
        // strength of one ambiguous sentence is the worse bet. Confirm and delete
        // these two rows if it did go.
        $this->seedSurcharges('2026-2027', [
            TaxSchedule::REGIME_BUSINESS => ['threshold' => 10000000, 'percentage' => 10],
            TaxSchedule::REGIME_RENTAL => ['threshold' => 10000000, 'percentage' => 10],
        ]);
    }

    /**
     * @param  array<string, array{threshold: float|int, percentage: float|int}>  $surcharges
     */
    private function seedSurcharges(string $yearName, array $surcharges): void
    {
        $year = FiscalYear::where('name', $yearName)->first();

        if (! $year) {
            return;
        }

        foreach ($surcharges as $regime => $rule) {
            TaxSurcharge::firstOrCreate(
                ['fiscal_year_id' => $year->id, 'regime' => $regime],
                ['threshold' => $rule['threshold'], 'percentage' => $rule['percentage']],
            );
        }
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
