<?php

namespace Tests\Feature;

use App\Modules\Core\Models\FiscalYear;
use App\Support\PayrollMonth;
use Tests\TestCase;

/**
 * Which calendar year a named payroll month falls in.
 *
 * Payslips are keyed by month *name* and fiscal year, so this arithmetic decides what date a month means —
 * and therefore which month an advance recovery is dated in, which period a bill covers, and what a bank file
 * is labelled. A wrong answer here is invisible on the payslip and wrong everywhere downstream.
 *
 * **There were two implementations and they disagreed.** `App\Support\Banking\PayrollMonth::yearFor()` derived
 * the boundary from the fiscal year's start month; `App\Modules\Payroll\Support\PayrollMonth::firstDay()`
 * hardcoded `month <= 6`. The second ignored the fiscal year's shape entirely, so it returned the July–June
 * answer for every year — correct for July–June and for a calendar year, wrong for any other start month.
 *
 * The consolidation was accepted only after characterising every month against five fiscal-year shapes before
 * and after: **July–June, January–December and the no-fiscal-year fallback came out byte-identical**, and
 * every other difference was a previously-wrong answer corrected. This file is that characterisation, kept.
 */
class PayrollMonthTest extends TestCase
{
    private function year(string $start, string $end): FiscalYear
    {
        return new FiscalYear(['name' => "{$start}..{$end}", 'start_date' => $start, 'end_date' => $end]);
    }

    /**
     * The rule, stated once: a month belongs to the start year from the start month onwards, and to the end
     * year before it.
     *
     * @return array<string, array{string, string, string, int}>
     */
    public static function months(): array
    {
        return [
            // Pakistan's fiscal year, and the shape this application is actually sold into. Unchanged by
            // the consolidation — asserted first because a regression here reaches every company.
            'jul-jun: July is the first month' => ['2026-07-01', '2027-06-30', 'July', 2026],
            'jul-jun: December still the start year' => ['2026-07-01', '2027-06-30', 'December', 2026],
            'jul-jun: January crosses over' => ['2026-07-01', '2027-06-30', 'January', 2027],
            'jul-jun: June is the last month' => ['2026-07-01', '2027-06-30', 'June', 2027],

            // A calendar year never crosses over. Also unchanged.
            'jan-dec: January' => ['2026-01-01', '2026-12-31', 'January', 2026],
            'jan-dec: December' => ['2026-01-01', '2026-12-31', 'December', 2026],

            // April–March: the old implementation put April, May and June a year late.
            'apr-mar: April is the first month' => ['2026-04-01', '2027-03-31', 'April', 2026],
            'apr-mar: June is still the start year' => ['2026-04-01', '2027-03-31', 'June', 2026],
            'apr-mar: March is the last month' => ['2026-04-01', '2027-03-31', 'March', 2027],

            // October–September: the old implementation put July, August and September a year early.
            'oct-sep: October is the first month' => ['2026-10-01', '2027-09-30', 'October', 2026],
            'oct-sep: September is the last month' => ['2026-10-01', '2027-09-30', 'September', 2027],
            'oct-sep: July belongs to the end year' => ['2026-10-01', '2027-09-30', 'July', 2027],

            // February–January: the old implementation was wrong for five of the twelve.
            'feb-jan: February is the first month' => ['2026-02-01', '2027-01-31', 'February', 2026],
            'feb-jan: January is the last month' => ['2026-02-01', '2027-01-31', 'January', 2027],
        ];
    }

    /**
     * @dataProvider months
     */
    public function test_a_month_falls_in_the_right_calendar_year(string $start, string $end, string $month, int $expected): void
    {
        $fiscalYear = $this->year($start, $end);

        $this->assertSame($expected, PayrollMonth::yearFor($month, $fiscalYear));
        $this->assertSame($expected, PayrollMonth::firstDay($month, $fiscalYear)->year, 'firstDay disagrees with yearFor');
        $this->assertSame($expected, PayrollMonth::lastDay($month, $fiscalYear)->year, 'lastDay disagrees with yearFor');
    }

    /** The three methods are one rule, so they can never disagree — asserted across a whole year. */
    public function test_first_and_last_day_bracket_the_same_month(): void
    {
        $fiscalYear = $this->year('2026-07-01', '2027-06-30');

        foreach (['January', 'April', 'July', 'October', 'December'] as $month) {
            $first = PayrollMonth::firstDay($month, $fiscalYear);
            $last = PayrollMonth::lastDay($month, $fiscalYear);

            $this->assertSame(1, $first->day);
            $this->assertSame($first->month, $last->month, 'lastDay left the month');
            $this->assertSame($first->year, $last->year);
            $this->assertSame($last->daysInMonth, $last->day, 'lastDay is not the last day');
        }
    }

    /**
     * No fiscal year means no boundary, so the fallback answers whatever the month.
     *
     * Preserved from the old implementation deliberately: without a fiscal year there is nothing to place the
     * month against, and any cleverer answer would be a guess dressed as arithmetic.
     */
    public function test_with_no_fiscal_year_every_month_uses_the_fallback(): void
    {
        foreach (['January', 'June', 'July', 'December'] as $month) {
            $this->assertSame(2026, PayrollMonth::yearFor($month, null));
            $this->assertSame(2030, PayrollMonth::yearFor($month, null, 2030));
        }
    }

    /** A whole fiscal year's months are twelve consecutive months, in order, with no gap or repeat. */
    public function test_a_fiscal_years_twelve_months_are_consecutive(): void
    {
        foreach ([['2026-07-01', '2027-06-30'], ['2026-04-01', '2027-03-31'], ['2026-10-01', '2027-09-30']] as [$start, $end]) {
            $fiscalYear = $this->year($start, $end);
            $cursor = \Carbon\Carbon::parse($start)->startOfMonth();

            for ($i = 0; $i < 12; $i++) {
                $this->assertSame(
                    $cursor->toDateString(),
                    PayrollMonth::firstDay($cursor->format('F'), $fiscalYear)->toDateString(),
                    "{$start}: month {$i} ({$cursor->format('F')}) is not where the fiscal year puts it",
                );

                $cursor->addMonth();
            }
        }
    }

    /**
     * One implementation, so the old pair cannot come back.
     *
     * Asserted on the files rather than with `class_exists()`, which was the first attempt and is the wrong
     * tool: a deleted class still in composer's classmap makes `class_exists()` *throw* on the missing
     * include rather than return false. The files are what the two-implementation problem was made of.
     */
    public function test_there_is_only_one_payroll_month(): void
    {
        $this->assertFileDoesNotExist(app_path('Support/Banking/PayrollMonth.php'));
        $this->assertFileDoesNotExist(app_path('Modules/Payroll/Support/PayrollMonth.php'));
        $this->assertFileExists(app_path('Support/PayrollMonth.php'));
    }
}
