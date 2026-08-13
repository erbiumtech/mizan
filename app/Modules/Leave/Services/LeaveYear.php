<?php

namespace App\Modules\Leave\Services;

use App\Modules\Employees\Models\Employee;
use Illuminate\Support\Carbon;
use InvalidArgumentException;

/**
 * When an employee's leave year runs.
 *
 * Three bases, one company setting, because the answer genuinely differs and none
 * of the three is derivable from the others:
 *
 *  - `calendar`  1 Jan – 31 Dec. The default, and not a guess about what most
 *                policies say: it is what the one policy we can read says. The
 *                company running this in production resets everybody on 1 January
 *                whatever their joining date.
 *  - `fiscal`    1 Jul – 30 Jun, FBR's year, which employee_settings are already
 *                versioned by.
 *  - `anniversary`  joining date + n years, closest to the statutory entitlement,
 *                which accrues on completing twelve months of *service*.
 *
 * This class is asked for a window and never stores one. Storing is the caller's
 * job, and LeaveEntitlement does it on the row — which is what makes the setting
 * safe to change in June. Were the window derived at read time, switching the basis
 * would move every existing entitlement, restate every balance mid-year, and land
 * approved leave in a year that no longer exists.
 */
class LeaveYear
{
    public const SETTING_KEY = 'leave.year_basis';

    public const BASIS_CALENDAR = 'calendar';

    public const BASIS_FISCAL = 'fiscal';

    public const BASIS_ANNIVERSARY = 'anniversary';

    /** @var array<int, string> */
    public const BASES = [self::BASIS_CALENDAR, self::BASIS_FISCAL, self::BASIS_ANNIVERSARY];

    /**
     * The month the fiscal year opens on.
     *
     * July, matching FBR's year and the boundary FiscalYear rows already use. It is
     * computed here rather than read from a FiscalYear row deliberately: generating
     * next year's entitlements must not fail because nobody has created that fiscal
     * year yet, and a leave year that silently shifted when somebody edited a
     * FiscalYear row would restate settled entitlements — the one thing §4.7
     * forbids.
     */
    private const FISCAL_START_MONTH = 7;

    public function basis(): string
    {
        $basis = (string) setting(self::SETTING_KEY, self::BASIS_CALENDAR);

        // An unrecognised basis is a typo in .env or a hand-edited settings row,
        // and guessing would silently give somebody the wrong leave year. Falling
        // back to the documented default is the honest answer and the loud one is
        // not available here — this is read on every balance check.
        return in_array($basis, self::BASES, true) ? $basis : self::BASIS_CALENDAR;
    }

    /**
     * The leave year covering a date, as [start, end] inclusive.
     *
     * @return array{0: Carbon, 1: Carbon}
     */
    public function windowFor(Employee $employee, string|Carbon $date): array
    {
        $date = $this->normalise($date);

        return match ($this->basis()) {
            self::BASIS_FISCAL => $this->fiscalWindow($date),
            self::BASIS_ANNIVERSARY => $this->anniversaryWindow($employee, $date),
            default => [$date->copy()->startOfYear(), $date->copy()->endOfYear()],
        };
    }

    /**
     * How much of the leave year remains for somebody joining part-way through it,
     * in months, per docs/hrms-plan.md §4.1.
     *
     * The half-month boundary is the part worth pinning down, because otherwise it
     * gets implemented three different ways: **the joining month counts when the
     * joining date falls on or before the 15th, and is skipped otherwise.** So a
     * 12 September joiner on a calendar year gets 4/12 and a 20 September joiner
     * gets 3/12.
     *
     * That stays in code rather than becoming a setting. Nobody has asked for a
     * different boundary, and a company that did would be asking for a different
     * accrual method rather than different rounding — which is the three-count test
     * §4.7 puts every candidate setting through.
     */
    public function monthsRemaining(Carbon $joinedOn, Carbon $yearStart, Carbon $yearEnd): int
    {
        if ($joinedOn->lte($yearStart)) {
            return $this->monthsInWindow($yearStart, $yearEnd);
        }

        if ($joinedOn->gt($yearEnd)) {
            return 0;
        }

        // The month they joined counts only if they were there for the bulk of it.
        $firstMonth = $joinedOn->day <= 15
            ? $joinedOn->copy()->startOfMonth()
            : $joinedOn->copy()->startOfMonth()->addMonth();

        if ($firstMonth->gt($yearEnd)) {
            return 0;
        }

        return $this->monthsInWindow($firstMonth, $yearEnd);
    }

    /**
     * The window the *next* leave year occupies, which the year-end reset needs in
     * order to create the entitlement it carries days into.
     *
     * @return array{0: Carbon, 1: Carbon}
     */
    public function nextWindowAfter(Employee $employee, Carbon $yearEnd): array
    {
        return $this->windowFor($employee, $yearEnd->copy()->addDay());
    }

    /** @return array{0: Carbon, 1: Carbon} */
    private function fiscalWindow(Carbon $date): array
    {
        $start = $date->copy()->startOfMonth()->month(self::FISCAL_START_MONTH);

        // Before July, the year that covers this date opened last July.
        if ($date->month < self::FISCAL_START_MONTH) {
            $start->subYear();
        }

        return [$start->startOfDay(), $start->copy()->addYear()->subDay()->endOfDay()->startOfDay()];
    }

    /**
     * @return array{0: Carbon, 1: Carbon}
     */
    private function anniversaryWindow(Employee $employee, Carbon $date): array
    {
        $joined = $employee->date_of_joining;

        if (! $joined) {
            throw new InvalidArgumentException(
                "Employee {$employee->getKey()} has no joining date, so an anniversary leave year cannot be worked out. "
                .'Set date_of_joining, or use the calendar or fiscal basis.'
            );
        }

        $joined = $this->normalise($joined);

        // Which completed year of service the date falls in. A date before the
        // joining date belongs to no service year, and answering with one would
        // hand somebody an entitlement before they started.
        $start = $joined->copy();

        while ($start->copy()->addYear()->lte($date)) {
            $start->addYear();
        }

        return [$start->startOfDay(), $start->copy()->addYear()->subDay()->startOfDay()];
    }

    /** Whole months spanned, inclusive of both ends, capped at twelve. */
    private function monthsInWindow(Carbon $from, Carbon $to): int
    {
        $months = ($to->year - $from->year) * 12 + ($to->month - $from->month) + 1;

        return max(0, min(12, $months));
    }

    private function normalise(string|Carbon $date): Carbon
    {
        return ($date instanceof Carbon ? $date->copy() : Carbon::parse($date))->startOfDay();
    }
}
