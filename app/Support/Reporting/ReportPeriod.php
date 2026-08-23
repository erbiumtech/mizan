<?php

namespace App\Support\Reporting;

use App\Modules\Core\Models\FiscalYear;
use Carbon\Carbon;

/**
 * The period a report covers when all it has been given is a date.
 *
 * The explorer's pane asks for one date, and half the reports there measure a *span* — a profit and loss,
 * a cash flow, a register. Something has to decide where that span starts, and the obvious answer is
 * wrong: `Carbon::startOfYear()` is 1 January, and this application's financial years run 1 July to
 * 30 June. A profit and loss to 30 June built from 1 January is six months of trading reported as twelve,
 * which is not a rounding difference — it is a materially wrong statement that looks entirely plausible.
 *
 * So the span starts where the fiscal year containing the date starts. Falls back to the calendar year
 * only when no fiscal year covers the date at all, which is a company that has not set one up.
 *
 * **Shared code rather than Accounting's, for the same reason `ReportShapes` is.** This was
 * `Accounting\Support\ReportPeriod` until CRM's five reports needed it, and a `crm -> accounting` edge
 * bought for a date pair is precisely what `docs/module-packaging-plan.md` §8 Group A calls
 * "host-application code filed inside a module". It imports nothing but Core and Carbon, and every module
 * that reports over a span needs it — Support, Timesheets and Lifecycle each would have bought the same
 * edge for the same reason. See `ModuleBoundaryTest`, whose tangled-module budget is nought.
 */
class ReportPeriod
{
    /**
     * The financial year to date, ending on the date given.
     *
     * @return array{from: string, to: string, year: ?FiscalYear}
     */
    public static function toDate(string $asOf): array
    {
        $date = Carbon::parse($asOf);
        $year = self::yearFor($date);

        $from = $year?->start_date
            ? Carbon::parse($year->start_date)->toDateString()
            : $date->copy()->startOfYear()->toDateString();

        return ['from' => $from, 'to' => $date->toDateString(), 'year' => $year];
    }

    /**
     * The same span a year earlier.
     *
     * Shifted by twelve months rather than looked up as "the previous fiscal year", so a comparison is
     * always like-for-like even where the years are not the same length — a company that changed its year
     * end has one short year in its history, and comparing a nine-month year against a twelve-month one
     * would show a fall in income that never happened.
     *
     * @return array{from: string, to: string}
     */
    public static function previous(string $from, string $to): array
    {
        return [
            'from' => Carbon::parse($from)->subYear()->toDateString(),
            'to' => Carbon::parse($to)->subYear()->toDateString(),
        ];
    }

    /**
     * The twelve months of the fiscal year containing a date, in the order they are paid.
     *
     * Fiscal order, not calendar order — a filing month picker on a 1 July year that opens on January is
     * asking somebody to scroll past the second half of the year to reach the first. Month *names* because
     * that is what payslips are stamped with in this application, and what the tax summary filters on.
     *
     * @return array<int, string>
     */
    public static function months(Carbon|string $date): array
    {
        $start = self::yearFor($date)?->start_date?->copy()
            ?? Carbon::parse($date instanceof Carbon ? $date->toDateString() : $date)->startOfYear();

        return collect(range(0, 11))
            ->map(fn (int $i): string => Carbon::parse($start)->addMonths($i)->format('F'))
            ->all();
    }

    /** The fiscal year covering a date, or the current one. */
    public static function yearFor(Carbon|string $date): ?FiscalYear
    {
        $date = $date instanceof Carbon ? $date : Carbon::parse($date);

        return FiscalYear::query()
            ->where('start_date', '<=', $date->toDateString())
            ->where('end_date', '>=', $date->toDateString())
            ->first() ?? FiscalYear::current();
    }
}
