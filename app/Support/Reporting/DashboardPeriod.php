<?php

namespace App\Support\Reporting;

use Illuminate\Support\Carbon;

/**
 * The span the dashboard is answering for — `docs/reports-expansion-plan.md` Phase 5.1.
 *
 * "Carrying a **period filter** (this month / this quarter / financial year to date / a custom range through
 * `ReportPeriod`) in the URL, so a dashboard someone links to opens on the period they meant. Widgets read
 * the page's filter; none of them keeps its own idea of 'now'."
 *
 * **Every period ends today, and only its start moves.** A dashboard answers "how are we doing", and nobody
 * has revenue from the rest of the month — so "this quarter" means the quarter so far, not the quarter as a
 * whole with two thirds of it empty. A custom range is the exception, because somebody naming both ends means
 * both ends.
 *
 * **Quarters are fiscal, not calendar.** The financial year here runs 1 July to 30 June, so its quarters are
 * July–September, October–December, January–March, April–June. A dashboard whose "this quarter" was
 * January–March while the accounts called that Q3 would have the two screens using one word for two spans,
 * and the dashboard is the screen people quote from.
 *
 * **Deliberately not `ReportComparison::currentRange()`, which looks like it would do.** That class answers
 * "the three months up to this date" because a comparison has to be the same length as the thing compared.
 * This one answers "the quarter we are in". They agree only when today happens to be a quarter end, and
 * sharing the method would have made one of the two silently wrong for the rest of the time.
 */
class DashboardPeriod
{
    public const THIS_MONTH = 'this_month';

    public const THIS_QUARTER = 'this_quarter';

    public const YEAR_TO_DATE = 'year_to_date';

    public const CUSTOM = 'custom';

    /**
     * The periods the picker offers, narrowest first.
     *
     * This month leads because it is the one somebody opens the dashboard for, and it is the cheapest set of
     * queries of the four — which matters on the most-loaded page in the panel.
     *
     * @var array<string, string>
     */
    public const PERIODS = [
        self::THIS_MONTH => 'This month',
        self::THIS_QUARTER => 'This quarter',
        self::YEAR_TO_DATE => 'Financial year to date',
        self::CUSTOM => 'Custom range',
    ];

    /**
     * A period that arrived from a URL, made safe.
     *
     * Falls back to this month rather than to a custom range, because a custom range with nothing in it is a
     * dashboard with no period at all — and the commonest way to arrive with a bad value is an old link.
     */
    public static function normalise(?string $period): string
    {
        return array_key_exists((string) $period, self::PERIODS) ? (string) $period : self::THIS_MONTH;
    }

    /**
     * The span, as dates.
     *
     * A custom range with either end missing falls back to the named period's shape rather than to half a
     * range: a `from` with no `to` would otherwise read to the end of time, and a `to` with no `from` from
     * the beginning of it.
     *
     * @return array{from: string, to: string}
     */
    public static function range(?string $period, ?string $from = null, ?string $to = null, ?string $today = null): array
    {
        $now = Carbon::parse($today ?? now()->toDateString());
        $period = self::normalise($period);

        if ($period === self::CUSTOM) {
            return filled($from) && filled($to)
                ? self::ordered($from, $to)
                : self::range(self::THIS_MONTH, today: $now->toDateString());
        }

        return [
            'from' => match ($period) {
                self::THIS_QUARTER => self::fiscalQuarterStart($now),
                self::YEAR_TO_DATE => ReportPeriod::toDate($now->toDateString())['from'],
                default => $now->copy()->startOfMonth()->toDateString(),
            },
            'to' => $now->toDateString(),
        ];
    }

    /**
     * The start of the fiscal quarter containing this date.
     *
     * **Counted back from the year *end*, not forward from its start**, and a test is what forced that.
     * `FiscalYear` enforces a 30 June end and says why: "a company joining part-way through gets a shorter
     * year ending on the same date". So a company that joined in November has a year running 1 November to
     * 30 June — and counting three-month blocks from *that* start would give it quarters beginning in
     * November, February and May, while its accounts and every other report treat the quarters as
     * July–September, October–December, January–March, April–June. A short first year is an artefact of when
     * somebody signed up, not a different quarter structure.
     *
     * Counting from the end fixes it, because the end is the fixed point: 30 June plus a day less a year is
     * 1 July, whatever the row's own start says.
     *
     * **Then clamped to the year's start**, because a short first year cannot report from before it began: on
     * 15 November that company's quarter began on 1 October, and it has no October.
     *
     * Falls back to calendar quarters where no fiscal year covers the date — a real state for a company
     * mid-setup, and answering with the convention beats guessing.
     *
     * **Public since Phase 6.3, and only for that reason.** `RelativePeriod` needs this quarter's start and
     * the one before it, and the paragraphs above are what a second implementation would have to get right
     * again. One arithmetic, two callers.
     */
    public static function fiscalQuarterStart(Carbon $date): string
    {
        $year = ReportPeriod::yearFor($date);

        if ($year === null) {
            return $date->copy()->firstOfQuarter()->toDateString();
        }

        // The year this *would* have started on had the company been here for all of it. Always a 1 July,
        // because the end is always a 30 June.
        $canonical = Carbon::parse($year->end_date)->addDay()->subYear()->startOfMonth();

        // Whole months elapsed, floored to a multiple of three. Both ends taken to the start of their month
        // so the day of the month cannot shift the count.
        $months = (int) $canonical->diffInMonths($date->copy()->startOfMonth());

        $quarterStart = $canonical->copy()->addMonthsNoOverflow(intdiv($months, 3) * 3);
        $yearStart = Carbon::parse($year->start_date);

        return $quarterStart->lt($yearStart)
            ? $yearStart->toDateString()
            : $quarterStart->toDateString();
    }

    /**
     * A custom range with its ends the right way round.
     *
     * Somebody who types the later date first means the range between them, not an empty one — and a widget
     * handed `from` after `to` reports nought rather than erroring, which reads as a quiet month.
     *
     * @return array{from: string, to: string}
     */
    private static function ordered(string $from, string $to): array
    {
        return Carbon::parse($from)->lte(Carbon::parse($to))
            ? ['from' => Carbon::parse($from)->toDateString(), 'to' => Carbon::parse($to)->toDateString()]
            : ['from' => Carbon::parse($to)->toDateString(), 'to' => Carbon::parse($from)->toDateString()];
    }

    /**
     * What the period is called on screen, with a custom range naming its own dates.
     *
     * "Custom range" over a set of figures says nothing about which figures; the dates do.
     */
    public static function label(?string $period, ?string $from = null, ?string $to = null): string
    {
        $period = self::normalise($period);

        if ($period !== self::CUSTOM) {
            return self::PERIODS[$period];
        }

        $range = self::range($period, $from, $to);

        return filled($from) && filled($to)
            ? Carbon::parse($range['from'])->format('j M Y').' to '.Carbon::parse($range['to'])->format('j M Y')
            : self::PERIODS[self::THIS_MONTH];
    }
}
