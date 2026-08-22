<?php

namespace App\Modules\Payroll\Services;

use App\Modules\Attendance\Services\WorkPatternResolver;
use App\Modules\Core\Models\FiscalYear;
use App\Modules\Employees\Models\Employee;
use App\Modules\Payroll\Models\EmployeeSetting;
use App\Support\PayrollMonth;

/**
 * Phase 3a: what an hour of overtime is worth.
 *
 * An earlier draft of docs/hrms-plan.md §4.2 said `overtime_minutes` could simply feed
 * `extra_work_hours`. **It cannot, and the column name is what caused the mistake:**
 * `extra_work_hours` is a RUPEE AMOUNT — `decimal(10,2)`, typed into the payslip form,
 * summed straight into earnings and debited as `bonus_overtime`. So the join is
 * minutes → money with nothing in between, and nothing in this system previously knew
 * what an hour of anybody's time cost.
 *
 * Three pieces had to exist, and this class is the first two:
 *
 *  1. **An hourly rate**, derived rather than stored: the basic wage over the month's
 *     contracted hours. `work_pattern_days.expected_hours` is the only place this
 *     system records how long a working day is, which is why phase 3a depends on
 *     phase 2 rather than the reverse.
 *  2. **A multiplier**, `attendance.overtime_multiplier`, defaulting to 2.0 because
 *     the Factories Act mandates double the ordinary rate. Provincial establishments
 *     differ, which is why it is a setting rather than a constant.
 *  3. **Caps that warn and never adjust** — `warnings()`, below. Silently capping paid
 *     overtime hides an employer's compliance problem *and* underpays somebody: two
 *     wrongs from one line of code.
 *
 * The rate is recorded on the payslip when used, for the same reason the proration
 * divisor is: a rate recomputed next year against a changed package would restate a
 * settled month.
 */
class OvertimeRate
{
    public function __construct(private readonly WorkPatternResolver $patterns) {}

    /**
     * The ordinary hourly rate for an employee in a month, or null when it cannot be
     * derived.
     *
     * Null rather than a guess in three cases: no agreed package, no work pattern
     * saying how long a day is, or a month with no working days. Each would otherwise
     * produce a rate by dividing by something meaningless, and an overtime payment is
     * the last place to discover that.
     */
    public function hourlyFor(Employee $employee, string $month, FiscalYear $fiscalYear): ?float
    {
        $firstDay = PayrollMonth::firstDay($month, $fiscalYear);

        $setting = EmployeeSetting::getActiveSettingForDate(
            $employee->getKey(),
            $firstDay->toDateString(),
            $fiscalYear->getKey(),
        );

        if (! $setting || (float) $setting->basic_wage <= 0) {
            return null;
        }

        $contractedHours = $this->contractedHoursIn($employee, $firstDay);

        if ($contractedHours <= 0) {
            return null;
        }

        return round((float) $setting->basic_wage / $contractedHours, 4);
    }

    /**
     * What the month's overtime is worth, or null when the rate cannot be derived.
     *
     * Guarded on `payroll.pay_overtime`: with the switch off this returns null and
     * recorded overtime stays a recorded fact, which is where phase 2 left it.
     */
    public function amountFor(Employee $employee, string $month, FiscalYear $fiscalYear, int $overtimeMinutes): ?OvertimePay
    {
        if (! setting('payroll.pay_overtime') || $overtimeMinutes <= 0) {
            return null;
        }

        $hourly = $this->hourlyFor($employee, $month, $fiscalYear);

        if ($hourly === null) {
            return null;
        }

        $multiplier = max(0.0, (float) setting('attendance.overtime_multiplier', 2.0));

        return new OvertimePay(
            hourlyRate: $hourly,
            multiplier: $multiplier,
            minutes: $overtimeMinutes,
            amount: round($hourly * $multiplier * ($overtimeMinutes / 60), 2),
        );
    }

    /**
     * Cap breaches, as warnings. **Never as adjustments.**
     *
     * The daily figure is the Factories Act's two hours and the weekly one is twelve,
     * both configurable. A company over them has a compliance problem that somebody
     * needs to see — and quietly paying less would hide it while also underpaying the
     * person who did the work.
     *
     * @return array<int, string>
     */
    public function warnings(int $overtimeMinutes, int $workingDaysInMonth): array
    {
        $warnings = [];

        $weeklyCap = (int) setting('attendance.overtime_weekly_cap_minutes', 720);
        $dailyCap = (int) setting('attendance.overtime_daily_cap_minutes', 120);

        // Roughly four and a third weeks in a month. Approximate on purpose: this is a
        // flag for a human to look at, not a figure anything is calculated from.
        $monthlyWeeklyCap = (int) round($weeklyCap * 4.33);

        if ($overtimeMinutes > $monthlyWeeklyCap) {
            $warnings[] = sprintf(
                'Overtime this month is %s hours, above the weekly cap of %s hours sustained over a month. Paid in full — check the hours are right and the law allows them.',
                round($overtimeMinutes / 60, 1),
                round($weeklyCap / 60, 1),
            );
        }

        if ($workingDaysInMonth > 0 && $dailyCap > 0
            && ($overtimeMinutes / $workingDaysInMonth) > $dailyCap) {
            $warnings[] = sprintf(
                'Overtime averages %s hours per working day, above the daily cap of %s hours. Paid in full — this is a warning, not a reduction.',
                round($overtimeMinutes / $workingDaysInMonth / 60, 1),
                round($dailyCap / 60, 1),
            );
        }

        return $warnings;
    }

    /**
     * The hours an employee is contracted for in a month, from their work pattern.
     *
     * Walks the month rather than multiplying a weekly figure, because months differ:
     * a five-day pattern gives 21 working days in one month and 23 in another, and an
     * hourly rate that ignored that would be several percent out in both directions
     * across a year.
     *
     * Zero without `attendance`, which makes the whole overtime path decline — there
     * is no pattern to say how long a day is, and inventing eight hours would be a
     * rate derived from an assumption nobody made.
     */
    private function contractedHoursIn(Employee $employee, \Illuminate\Support\Carbon $firstDay): float
    {
        if (! modules()->enabled('attendance')) {
            return 0.0;
        }

        $hours = 0.0;
        $end = $firstDay->copy()->endOfMonth();

        for ($date = $firstDay->copy(); $date->lte($end); $date->addDay()) {
            $hours += (float) ($this->patterns->expectedHours($employee, $date) ?? 0);
        }

        return $hours;
    }
}
