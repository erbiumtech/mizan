<?php

namespace App\Modules\PersonalFinance\Services;

use App\Modules\PersonalFinance\Models\PersonalAccount;
use App\Modules\PersonalFinance\Models\TaxSchedule;
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
 * This is an estimate. It does not know about tax already withheld at source,
 * credits, deductible allowances, or the surcharge on high salaried income, and
 * it says so on screen.
 */
class PersonalTaxService
{
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
        $accounts = PersonalAccount::ofType(PersonalAccount::TYPE_INCOME)->get();

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

        return [
            'taxable' => $taxable,
            'tax' => $tax,
            'bracket' => $bracket,
            'marginal_rate' => (float) $bracket->percentage,
            'effective_rate' => $taxable > 0 ? round($tax / $taxable * 100, 2) : 0.0,
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

        foreach ($income['by_regime'] as $regime => $amount) {
            $result = $this->taxFor($amount, $regime, $fiscalYearId);

            $regimes[] = [
                'regime' => $regime,
                'label' => TaxSchedule::REGIMES[$regime] ?? $regime,
                'income' => $amount,
            ] + $result;

            $totalIncome += $amount;
            $totalTax += $result['tax'];
        }

        return [
            'regimes' => $regimes,
            'unclassified' => $income['unclassified'],
            'total_income' => round($totalIncome, 2),
            'total_tax' => round($totalTax, 2),
        ];
    }

    /** What was credited to an income account inside one tax year. */
    private function creditedInYear(PersonalAccount $account, int $fiscalYearId): float
    {
        $credits = (float) $account->lines()
            ->whereHas('entry', fn ($query) => $query->where('fiscal_year_id', $fiscalYearId))
            ->sum('credit');

        $debits = (float) $account->lines()
            ->whereHas('entry', fn ($query) => $query->where('fiscal_year_id', $fiscalYearId))
            ->sum('debit');

        // Debits against an income account are refunds or corrections.
        return round($credits - $debits, 2);
    }
}
