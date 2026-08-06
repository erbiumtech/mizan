<?php

namespace App\Modules\PersonalFinance\Services;

use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\JournalEntryLine;
use App\Modules\PersonalFinance\Models\TaxSchedule;
use App\Modules\PersonalFinance\Models\TaxSurcharge;
use RuntimeException;

/**
 * Estimates a person's Pakistani income tax from their own books.
 *
 * The bracket arithmetic is payroll's, because it is right:
 * `fixed_tax + percentage% x (income - min_amount)`, where `min_amount` is the
 * exceeding threshold and `fixed_tax` is the cumulative tax below it. Two
 * deliberate differences from TaxCalculatorService:
 *
 *  - it returns a breakdown, not a bare float, because a screen telling somebody
 *    what they owe has to be able to show its working;
 *  - it *raises* when no bracket matches instead of returning zero. Returning
 *    zero is how payroll came to assess its highest earners at nothing when a
 *    top bracket was left bounded, and that failure was invisible for as long as
 *    it existed.
 *
 * This is an estimate. It applies the section 4AB surcharge and the automatic
 * repair allowance on property income, both as data rather than code. It does
 * NOT know about tax already withheld at source, tax credits, or the receipted
 * deductions specific to a property — and it says so on screen.
 */
class PersonalTaxService
{
    /**
     * The repair allowance on property income: one fifth of the rent, allowed
     * automatically with no receipts (s.15A).
     *
     * Applied here because rental income for an individual is not taxed on the
     * gross. Ignoring it overstates the liability by a fifth of the rent, which
     * on any real rent is a large number to be wrong by.
     *
     * The other s.15A deductions — property tax, insurance, loan interest, ground
     * rent, collection charges, irrecoverable rent — are receipted and specific to
     * the property, so the estimate cannot know them. It says so on screen.
     */
    private const RENTAL_REPAIR_ALLOWANCE = 0.20;

    /**
     * Income for the year grouped by the schedule it is taxed under.
     *
     * The regime is a property of the account, so somebody tags "Salary" once
     * and every entry against it is classified from then on. Income with no
     * regime set is returned under `unclassified` and reported rather than
     * silently taxed as salary.
     *
     * @return array{by_regime: array<string, float>, unclassified: float}
     */
    public function incomeByRegime(int $fiscalYearId): array
    {
        $accounts = Account::ofType('income')->get();

        $byRegime = [];
        $unclassified = 0.0;

        foreach ($accounts as $account) {
            $earned = $this->creditedInYear($account, $fiscalYearId);

            if ($earned <= 0) {
                continue;
            }

            if ($account->tax_regime === null) {
                $unclassified += $earned;

                continue;
            }

            $byRegime[$account->tax_regime] = round(
                ($byRegime[$account->tax_regime] ?? 0) + $earned,
                2
            );
        }

        return ['by_regime' => $byRegime, 'unclassified' => round($unclassified, 2)];
    }

    /**
     * What one amount of income costs under one schedule, and how that figure
     * was arrived at.
     *
     * @return array{taxable: float, tax: float, bracket: ?TaxSchedule, marginal_rate: float, effective_rate: float}
     */
    public function taxFor(float $taxable, string $regime, int $fiscalYearId): array
    {
        $taxable = round($taxable, 2);

        if ($taxable <= 0) {
            return [
                'taxable' => 0.0,
                'tax' => 0.0,
                'surcharge' => 0.0,
                'surcharge_rate' => 0.0,
                'total' => 0.0,
                'bracket' => null,
                'marginal_rate' => 0.0,
                'effective_rate' => 0.0,
            ];
        }

        $bracket = TaxSchedule::query()
            ->where('fiscal_year_id', $fiscalYearId)
            ->where('regime', $regime)
            ->where('min_amount', '<', $taxable)
            ->where(function ($query) use ($taxable) {
                $query->where('max_amount', '>=', $taxable)->orWhereNull('max_amount');
            })
            ->orderByDesc('min_amount')
            ->first();

        if ($bracket === null) {
            // Never silently zero. Either the year has no schedule seeded for
            // this regime, or its top bracket is bounded and this income is above
            // it — both are wrong, and both look identical to "you owe nothing"
            // unless somebody says so.
            throw new RuntimeException(sprintf(
                'No %s tax bracket covers %s for that year. Either the schedule is not '
                .'seeded, or its top bracket has an upper bound and this income is above it.',
                TaxSchedule::REGIMES[$regime] ?? $regime,
                number_format($taxable, 2),
            ));
        }

        $tax = (float) $bracket->fixed_tax
            + ($taxable - (float) $bracket->min_amount) * (float) $bracket->percentage / 100;

        $tax = round(max(0, $tax), 2);

        // Section 4AB, a percentage OF THE TAX once taxable income passes a
        // threshold. Absent for a year and regime where it does not apply — the
        // 2026 Act withdrew it for salaried individuals — so no row means none.
        $surcharge = TaxSurcharge::where('fiscal_year_id', $fiscalYearId)
            ->where('regime', $regime)
            ->first();

        $surchargeAmount = $surcharge?->amountOn($taxable, $tax) ?? 0.0;
        $total = round($tax + $surchargeAmount, 2);

        return [
            'taxable' => $taxable,
            'tax' => $tax,
            'surcharge' => $surchargeAmount,
            'surcharge_rate' => $surchargeAmount > 0 ? (float) $surcharge->percentage : 0.0,
            'total' => $total,
            'bracket' => $bracket,
            'marginal_rate' => (float) $bracket->percentage,
            // Against the total, since that is what would actually be paid.
            'effective_rate' => $taxable > 0 ? round($total / $taxable * 100, 2) : 0.0,
        ];
    }

    /**
     * The whole picture for a year: each regime's income, its tax, and the totals.
     *
     * Each regime is assessed on its own schedule and the results added. That is
     * a simplification — a real return aggregates some heads before applying one
     * schedule — and it is stated on the screen rather than hidden.
     *
     * @return array{regimes: array<int, array<string, mixed>>, unclassified: float, total_income: float, total_tax: float}
     */
    public function estimate(int $fiscalYearId): array
    {
        $income = $this->incomeByRegime($fiscalYearId);

        $regimes = [];
        $totalIncome = 0.0;
        $totalTax = 0.0;

        $totalSurcharge = 0.0;

        foreach ($income['by_regime'] as $regime => $amount) {
            $taxable = $this->taxableAmountFor($regime, $amount);
            $result = $this->taxFor($taxable, $regime, $fiscalYearId);

            $regimes[] = [
                'regime' => $regime,
                'label' => TaxSchedule::REGIMES[$regime] ?? $regime,
                // Gross and taxable are both shown, because for rental they
                // differ and a reader has to see why the tax is not on the rent.
                'income' => $amount,
                'allowance' => round($amount - $taxable, 2),
            ] + $result;

            $totalIncome += $amount;
            $totalTax += $result['tax'];
            $totalSurcharge += $result['surcharge'];
        }

        return [
            'regimes' => $regimes,
            'unclassified' => $income['unclassified'],
            'total_income' => round($totalIncome, 2),
            'total_tax' => round($totalTax, 2),
            'total_surcharge' => round($totalSurcharge, 2),
            'total_payable' => round($totalTax + $totalSurcharge, 2),
        ];
    }

    /**
     * What of this income is actually taxable.
     *
     * Only rental differs: property income carries an automatic repair allowance
     * of one fifth of the rent, so the tax is never on the gross. Everything else
     * is taxable as earned, as far as this estimate can know — it cannot see
     * receipted deductions.
     */
    private function taxableAmountFor(string $regime, float $amount): float
    {
        if ($regime !== TaxSchedule::REGIME_RENTAL) {
            return $amount;
        }

        return round($amount * (1 - self::RENTAL_REPAIR_ALLOWANCE), 2);
    }

    /**
     * What was credited to an income account inside one tax year.
     *
     * Posted entries only, matching every other report in the app: an entry that
     * has not been posted has not happened as far as the books are concerned,
     * and taxing it would be taxing an intention.
     */
    private function creditedInYear(Account $account, int $fiscalYearId): float
    {
        $lines = JournalEntryLine::query()
            ->where('account_id', $account->id)
            ->whereHas('journalEntry', fn ($query) => $query
                ->where('is_posted', true)
                ->where('fiscal_year_id', $fiscalYearId));

        $credits = (float) (clone $lines)->sum('credit_amount');
        $debits = (float) (clone $lines)->sum('debit_amount');

        // Debits against an income account are refunds or corrections.
        return round($credits - $debits, 2);
    }
}
