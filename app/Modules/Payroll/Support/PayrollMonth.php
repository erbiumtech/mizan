<?php

namespace App\Modules\Payroll\Support;

use App\Modules\Core\Models\FiscalYear;
use Carbon\Carbon;

/**
 * Which calendar month a payroll month name means.
 *
 * Payslips are keyed by month name and fiscal year rather than by date, so
 * "January" in fiscal year 2026-2027 is January 2027 while "July" is July 2026.
 * Anything that has to turn one into a date range — billing a month's expenses,
 * for one — has to resolve it the same way payroll does, so the rule lives here
 * rather than in each caller.
 */
class PayrollMonth
{
    public static function firstDay(string $month, ?FiscalYear $fiscalYear, int $fallbackYear = 2026): Carbon
    {
        $startYear = $fiscalYear ? Carbon::parse($fiscalYear->start_date)->year : $fallbackYear;

        $tentative = Carbon::parse("{$month} 1, {$startYear}");

        // The second half of a fiscal year falls in the next calendar year.
        $year = ($tentative->month <= 6 && $fiscalYear && Carbon::parse($fiscalYear->end_date)->year > $startYear)
            ? Carbon::parse($fiscalYear->end_date)->year
            : $startYear;

        return Carbon::parse("{$month} 1, {$year}")->startOfDay();
    }

    public static function lastDay(string $month, ?FiscalYear $fiscalYear, int $fallbackYear = 2026): Carbon
    {
        return static::firstDay($month, $fiscalYear, $fallbackYear)->endOfMonth();
    }
}
