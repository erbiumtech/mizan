<?php

namespace App\Modules\Accounting\Support;

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
