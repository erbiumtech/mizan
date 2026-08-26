<?php

namespace App\Support\Reporting;

use Illuminate\Support\Carbon;

/**
 * The spans a saved report definition may hold — `docs/reports-expansion-plan.md` Phase 6, item 3.
 *
 * **Relative, never absolute, and that is the one decision in this class.** Item 3 lists `period` among the
 * things a definition's state holds, and Phase 4.5 already argued the trap: a saved view holding 30 June
 * "would open on 30 June for ever and nobody would notice for a while". A definition is worse than a view in
 * that respect, because Phase 8 will *send* it — "the aged receivables every Monday" has to resolve its own
 * dates each Monday or it is a report that mails last quarter for ever.
 *
 * So a definition stores `last_month`, and reading it on any day of any month gives that month. For a fixed
 * span there is already a better mechanism and Phase 4c built it: the URL carries the whole state, so "the
 * ledger for March" is a link somebody sends. A link for a moment, a definition for a habit.
 *
 * **Every span is bounded**, which is half of item 5's cost guard arriving for free: there is deliberately no
 * "all time". An unbounded report is the one thing a builder must not be able to ask for, and the honest place
 * to prevent it is the list of choices rather than a check further down.
 *
 * **Quarters and years are financial.** `DashboardPeriod::fiscalQuarterStart()` does that arithmetic and this
 * calls it rather than repeating it — the reasoning there (count back from the year *end*, then clamp to the
 * start, because a company that joined in November still has July–September quarters) took a test to get
 * right, and a second copy of it is a second answer waiting to disagree.
 *
 * **Not `DashboardPeriod` itself**, though the two overlap. That one is a dashboard's picker: four choices,
 * one of them a custom range, all ending today. This one has no custom range — that is the whole point — and
 * its "last month" and "last quarter" are *complete* spans that do not end today, which is what a report filed
 * monthly means and what a dashboard never asks for.
 */
final class RelativePeriod
{
    public const THIS_MONTH = 'this_month';

    public const LAST_MONTH = 'last_month';

    public const THIS_QUARTER = 'this_quarter';

    public const LAST_QUARTER = 'last_quarter';

    public const YEAR_TO_DATE = 'year_to_date';

    public const LAST_YEAR = 'last_year';

    /**
     * The spans, narrowest first.
     *
     * The "last" ones are complete and the "this" ones run to today, which is the distinction the labels have
     * to carry: somebody choosing "last month" for a report they file wants the whole month, and somebody
     * choosing "this month" wants what has happened so far.
     *
     * @var array<string, string>
     */
    public const PERIODS = [
        self::THIS_MONTH => 'This month',
        self::LAST_MONTH => 'Last month',
        self::THIS_QUARTER => 'This quarter',
        self::LAST_QUARTER => 'Last quarter',
        self::YEAR_TO_DATE => 'Financial year to date',
        self::LAST_YEAR => 'Last financial year',
    ];

    /**
     * Anything unrecognised becomes the financial year to date.
     *
     * A wider default than the dashboard's "this month", because a *report* opened with no period set is
     * usually being explored rather than checked, and a year that shows something beats a month that shows
     * nothing. It is also the span every statement in this application already defaults to.
     */
    public static function normalise(?string $period): string
    {
        return array_key_exists((string) $period, self::PERIODS) ? (string) $period : self::YEAR_TO_DATE;
    }

    /**
     * The span, as dates, resolved against a day.
     *
     * `$on` is for tests and for Phase 8's scheduler, which resolves a period as at the morning it runs
     * rather than as at now.
     *
     * @return array{from: string, to: string}
     */
    public static function range(?string $period, ?string $on = null): array
    {
        $day = Carbon::parse($on ?? now()->toDateString());

        return match (self::normalise($period)) {
            self::THIS_MONTH => [
                'from' => $day->copy()->startOfMonth()->toDateString(),
                'to' => $day->toDateString(),
            ],
            self::LAST_MONTH => [
                'from' => $day->copy()->subMonthNoOverflow()->startOfMonth()->toDateString(),
                'to' => $day->copy()->subMonthNoOverflow()->endOfMonth()->toDateString(),
            ],
            self::THIS_QUARTER => [
                'from' => DashboardPeriod::fiscalQuarterStart($day),
                'to' => $day->toDateString(),
            ],
            self::LAST_QUARTER => self::previousQuarter($day),
            self::LAST_YEAR => self::previousYear($day),
            default => [
                'from' => ReportPeriod::toDate($day->toDateString())['from'],
                'to' => $day->toDateString(),
            ],
        };
    }

    public static function label(?string $period): string
    {
        return self::PERIODS[self::normalise($period)];
    }

    /**
     * The whole quarter before the one containing this day.
     *
     * Worked out by stepping back a day from this quarter's start and asking which quarter *that* day is in,
     * rather than by subtracting three months: on a short first financial year the quarter before a clamped
     * one is not three months earlier, and subtracting would invent a span the company did not have.
     *
     * @return array{from: string, to: string}
     */
    private static function previousQuarter(Carbon $day): array
    {
        $thisQuarterStart = Carbon::parse(DashboardPeriod::fiscalQuarterStart($day));
        $end = $thisQuarterStart->copy()->subDay();

        return [
            'from' => DashboardPeriod::fiscalQuarterStart($end),
            'to' => $end->toDateString(),
        ];
    }

    /**
     * The whole financial year before the one containing this day.
     *
     * From the current year's start, back a year, to the day before it — so a 1 July to 30 June year gives the
     * previous 1 July to 30 June exactly.
     *
     * **Where no `FiscalYear` row covers the day, `ReportPeriod::toDate()` falls back to the calendar year**,
     * and this inherits that: last year becomes the previous calendar year. A real state for a company
     * mid-setup, and answering with the convention beats refusing.
     *
     * @return array{from: string, to: string}
     */
    private static function previousYear(Carbon $day): array
    {
        $start = Carbon::parse(ReportPeriod::toDate($day->toDateString())['from']);

        return [
            'from' => $start->copy()->subYear()->toDateString(),
            'to' => $start->copy()->subDay()->toDateString(),
        ];
    }
}
