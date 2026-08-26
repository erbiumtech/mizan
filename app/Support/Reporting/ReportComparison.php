<?php

namespace App\Support\Reporting;

use Illuminate\Support\Carbon;

/**
 * What a statement is being compared against — `docs/reports-expansion-plan.md` Phase 4.2.
 *
 * "Comparison periods beyond the previous year — previous month, previous quarter, budget."
 *
 * **The subtlety that shapes this whole class: a comparison basis shorter than the reporting period is a
 * category error, not a shorter comparison.** A profit and loss is the financial year to date. Compared
 * against "the previous month" by shifting its range back thirty days, it would read 1 June–20 January
 * against 1 July–20 February — two overlapping eight-month spans differing by a month at each end, whose
 * difference is almost entirely the same trading counted twice. The figure would look plausible and mean
 * nothing.
 *
 * So a month or a quarter basis narrows the **current** period to match: pick *previous month* on a profit
 * and loss and you get February against January, not a year-to-date against a shifted year-to-date. The
 * basis chooses the length of both columns, which is why `currentRange()` exists at all — the alternative
 * was a comparison column that silently lied.
 *
 * **A balance sheet has no such problem**, because it is an as-at rather than a period: whatever the basis,
 * the current figure is the balance today and the comparison is the balance at an earlier date. That is why
 * `shift()` and `currentRange()` are separate — the two kinds of statement need different questions answered.
 *
 * **Budget is deliberately not a basis here, and that is a decision rather than an omission.** The plan lists
 * it, and `BudgetVsActual` already *is* that report: per account, Planned against Actual with the variance,
 * and its own budget picker. Putting budget in the comparison slot would be a second implementation of an
 * existing comparison — and a poorer one, since the statement kind has nowhere to ask which budget. This
 * plan's own Phase 5 states the principle for widgets and it holds here: two paths to one number is how they
 * come to disagree, and the reader who spots it cannot tell which to believe.
 */
class ReportComparison
{
    public const NONE = 'none';

    public const PREVIOUS_YEAR = 'previous_year';

    public const PREVIOUS_QUARTER = 'previous_quarter';

    public const PREVIOUS_MONTH = 'previous_month';

    /**
     * The bases, in the order the picker offers them: widest first, because the previous year is what a
     * statement is normally read against and the narrower ones are the deliberate choice.
     *
     * @var array<string, string>
     */
    public const BASES = [
        self::PREVIOUS_YEAR => 'vs previous year',
        self::PREVIOUS_QUARTER => 'vs previous quarter',
        self::PREVIOUS_MONTH => 'vs previous month',
        self::NONE => 'No comparison',
    ];

    /**
     * A basis that arrived from a URL, made safe.
     *
     * An unrecognised value becomes the previous year rather than no comparison, because the commonest way
     * to arrive here with a bad basis is an old or hand-edited link — and answering that with a column
     * silently removed is worse than answering it with the conventional one.
     */
    public static function normalise(?string $basis): string
    {
        return array_key_exists((string) $basis, self::BASES) ? (string) $basis : self::PREVIOUS_YEAR;
    }

    /**
     * The basis a legacy `?comparison=` link means.
     *
     * The hub carried a boolean before this phase and people keep links. `true` meant the previous year and
     * `false` meant none, so both still land where they used to.
     */
    public static function fromLegacyFlag(bool $comparison): string
    {
        return $comparison ? self::PREVIOUS_YEAR : self::NONE;
    }

    /**
     * The comparison date for an **as-at** statement — a balance sheet.
     *
     * Null where there is no comparison. Quarters are three calendar months rather than fiscal quarters,
     * because a balance at "the end of the previous quarter" means three months ago to everybody who asks
     * for it, and a fiscal quarter would move the answer for companies whose year does not start in January.
     */
    public static function shift(string $basis, string $asOf): ?string
    {
        $date = Carbon::parse($asOf);

        return match (self::normalise($basis)) {
            self::PREVIOUS_YEAR => $date->copy()->subYear()->toDateString(),
            self::PREVIOUS_QUARTER => $date->copy()->subMonthsNoOverflow(3)->toDateString(),
            self::PREVIOUS_MONTH => $date->copy()->subMonthNoOverflow()->toDateString(),
            default => null,
        };
    }

    /**
     * The period a **period** statement covers under this basis — a profit and loss, a cash flow.
     *
     * The previous year and no comparison both leave it as the financial year to date, which is what these
     * statements have always shown. A month or a quarter basis narrows it, for the reason at the top of this
     * class: the comparison has to be the same length as the thing compared.
     *
     * `subMonthsNoOverflow` throughout. Plain `subMonth()` from 31 March lands on 3 March, which would make
     * a month-on-month comparison of a period ending on the 31st quietly wrong.
     *
     * @return array{from: string, to: string}
     */
    public static function currentRange(string $basis, string $asOf): array
    {
        $date = Carbon::parse($asOf);

        return match (self::normalise($basis)) {
            self::PREVIOUS_MONTH => [
                'from' => $date->copy()->startOfMonth()->toDateString(),
                'to' => $asOf,
            ],
            self::PREVIOUS_QUARTER => [
                'from' => $date->copy()->subMonthsNoOverflow(2)->startOfMonth()->toDateString(),
                'to' => $asOf,
            ],
            // The financial year to date — 1 July here, not 1 January. See ReportPeriod.
            default => ['from' => ReportPeriod::toDate($asOf)['from'], 'to' => $asOf],
        };
    }

    /**
     * The range the comparison column covers, or null where there is no comparison.
     *
     * The current range shifted whole, so the two columns are the same number of days wherever the calendar
     * allows it. For the previous year that is `ReportPeriod::previous()`, which shifts by twelve months
     * rather than looking up the preceding fiscal year — its own docblock gives the reason, and it is a good
     * one: a company that changed its year end has one short year in its history, and comparing nine months
     * against twelve would show a fall in income that never happened.
     *
     * @return array{from: string, to: string}|null
     */
    public static function previousRange(string $basis, string $asOf): ?array
    {
        $basis = self::normalise($basis);

        if ($basis === self::NONE) {
            return null;
        }

        ['from' => $from, 'to' => $to] = self::currentRange($basis, $asOf);

        return match ($basis) {
            self::PREVIOUS_MONTH => self::shiftRange($from, $to, 1),
            self::PREVIOUS_QUARTER => self::shiftRange($from, $to, 3),
            default => ReportPeriod::previous($from, $to),
        };
    }

    /**
     * A range moved back a whole number of months.
     *
     * The start is snapped to the start of its month and the end to the end of *its* month, so a partial
     * current period compares against a whole previous one: February to the 20th against the whole of
     * January. Comparing twenty days against twenty days would be tidier and would answer a question nobody
     * asks — what a month is worth is what the month came to.
     *
     * **The snap and the overflow guard on `$from` are defensive rather than load-bearing**, and no test
     * pretends otherwise: the only two bases that reach here are month and quarter, and `currentRange()`
     * always hands them a `from` that is already the first of a month, which can neither be snapped nor
     * overflow. They stay because they are what keeps this correct if `currentRange()` ever narrows
     * differently. The guard on `$to` *is* load-bearing — that one is a real as-at date.
     *
     * @return array{from: string, to: string}
     */
    private static function shiftRange(string $from, string $to, int $months): array
    {
        return [
            'from' => Carbon::parse($from)->subMonthsNoOverflow($months)->startOfMonth()->toDateString(),
            'to' => Carbon::parse($to)->subMonthsNoOverflow($months)->endOfMonth()->toDateString(),
        ];
    }
}
