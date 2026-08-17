<?php

namespace App\Support;

use App\Modules\Core\Models\FiscalYear;
use Carbon\Carbon;

/**
 * Which calendar year a named month falls in, inside a given fiscal year.
 *
 * Payslips are keyed by month *name* and fiscal year rather than by date, so "January" in a year running
 * 1 July 2026 to 30 June 2027 is January 2027 while "July" is July 2026. Anything that has to turn one into a
 * date — billing a month's expenses, dating an advance recovery, labelling a bank file — has to resolve it the
 * same way payroll does, so the rule lives here rather than in each caller.
 *
 * **There were two of these, and they disagreed.** The one in `App\Support\Banking` derived the boundary from
 * the fiscal year's own start month; the one in `App\Modules\Payroll\Support` hardcoded `month <= 6`, which is
 * the July–June boundary written as a constant. The second was wrong for every fiscal year that does not start
 * in July or January, and wrong silently — it ignored the start month altogether, so it returned the July–June
 * answer whatever the year's actual shape:
 *
 * | Fiscal year | Months it placed in the wrong calendar year |
 * |---|---|
 * | 1 Jul – 30 Jun | none |
 * | 1 Jan – 31 Dec | none |
 * | 1 Apr – 31 Mar | April, May, June |
 * | 1 Oct – 30 Sep | July, August, September |
 * | 1 Feb – 31 Jan | February through June |
 *
 * Nothing constrains a `FiscalYear` to July–June — `start_date` and `end_date` are free-form, and the test
 * suite already contains January and February starts. July–June is the Pakistani norm and calendar-year is the
 * other shape anyone would pick, which is why this survived: the two cases in use were the two it got right.
 *
 * The rule, stated once: **a month belongs to the fiscal year's start year if its month number is at or after
 * the start month, and to the end year otherwise.** That is the whole of it, and it reduces to the old
 * hardcoded behaviour for a July–June year.
 *
 * Lives in `App\Support` rather than `App\Support\Banking`, where §7 first put it: three modules read it
 * (Payroll, Billing and Accounting) and none of it is banking. Accounting is the reason it must be shared at
 * all — it labels a bank payment file by month without requiring Payroll.
 */
class PayrollMonth
{
    /**
     * The calendar year the named month falls in.
     *
     * `$fallbackYear` answers for a payslip with no fiscal year attached, which the callers below can produce:
     * the old implementation returned the fallback whatever the month, and that is preserved deliberately —
     * without a fiscal year there is no boundary to place the month against, so any other answer would be a
     * guess dressed as arithmetic.
     */
    public static function yearFor(string $month, ?FiscalYear $fiscalYear, int $fallbackYear = 2026): int
    {
        if ($fiscalYear === null) {
            return $fallbackYear;
        }

        $start = Carbon::parse($fiscalYear->start_date);
        $monthNumber = Carbon::parse("{$month} 1, {$start->year}")->month;

        return $monthNumber >= $start->month
            ? $start->year
            : Carbon::parse($fiscalYear->end_date)->year;
    }

    public static function firstDay(string $month, ?FiscalYear $fiscalYear, int $fallbackYear = 2026): Carbon
    {
        $year = self::yearFor($month, $fiscalYear, $fallbackYear);

        return Carbon::parse("{$month} 1, {$year}")->startOfDay();
    }

    public static function lastDay(string $month, ?FiscalYear $fiscalYear, int $fallbackYear = 2026): Carbon
    {
        return self::firstDay($month, $fiscalYear, $fallbackYear)->endOfMonth();
    }
}
