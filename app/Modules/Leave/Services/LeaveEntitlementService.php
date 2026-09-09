<?php

namespace App\Modules\Leave\Services;

use App\Modules\Employees\Models\Employee;
use App\Modules\Leave\Models\LeaveEntitlement;
use App\Modules\Leave\Models\LeaveType;
use Illuminate\Support\Carbon;

/**
 * Opening a leave year, accruing into it, and closing it.
 *
 * Three operations that all write `accrued_days` or `carried_in_days`, kept together
 * because the invariant between them is the interesting part: **each of them is
 * idempotent, and none of them ever recomputes a year that has closed.** Run the
 * year-end twice and nobody gains a day; switch leave.carry_forward on in June and
 * nothing already lapsed comes back.
 *
 * That last one is the failure worth naming. A carry job written as "recompute
 * carried_in from the previous year's unused balance" would, the moment somebody
 * enabled the setting mid-year, hand every employee back leave the company had
 * already written off — and it would look like a feature working. So carried_in is
 * written once, by the reset, at the moment the year turns, and is never derived
 * afterwards. docs/hrms-plan.md §10.4.
 */
class LeaveEntitlementService
{
    public function __construct(
        private readonly LeaveYear $year,
        private readonly LeaveBalance $balance,
    ) {}

    /**
     * Ensure an entitlement exists for one employee, one type, in the year covering
     * a date — and return it.
     *
     * Idempotent by the unique key on (employee, type, year start): called again it
     * updates the accrual and leaves everything else, so a second run of the
     * open-year command cannot double an allowance. `opening_days` and
     * `carried_in_days` are deliberately never touched here — the first is set-up
     * data and the second belongs to the reset.
     */
    public function open(Employee $employee, LeaveType $type, string|Carbon|null $date = null): ?LeaveEntitlement
    {
        // unlimited and none have no annual number, so an entitlement row would be a
        // credit of nothing that the balance then reports as "0 left" — a different
        // statement from "not rationed". LeaveBalance returns null for these types
        // and no row is what matches that.
        if (! $type->isCounted()) {
            return null;
        }

        $date = $date ? Carbon::parse($date) : now();
        [$start, $end] = $this->year->windowFor($employee, $date);

        $entitlement = LeaveEntitlement::firstOrNew([
            'employee_id' => $employee->getKey(),
            'leave_type_id' => $type->getKey(),
            'leave_year_start' => $start->toDateString(),
        ]);

        $entitlement->leave_year_end = $end->toDateString();
        $entitlement->accrued_days = $this->accrualFor($employee, $type, $start, $end, $date);
        $entitlement->save();

        return $entitlement;
    }

    /**
     * Open the year for every active employee and every active counted type.
     *
     * Leavers are skipped on `is_active`, which is the flag every existing query in
     * this application uses. `left_on` is not consulted: somebody who left in March
     * still had a leave year, and their entitlement is what a final settlement's
     * encashment reads.
     *
     * @return int entitlements created or refreshed
     */
    public function openYearFor(string|Carbon|null $date = null): int
    {
        $types = LeaveType::query()->active()->get()->filter->isCounted();
        $touched = 0;

        Employee::query()->where('is_active', true)->cursor()->each(
            function (Employee $employee) use ($types, $date, &$touched): void {
                foreach ($types as $type) {
                    if ($this->open($employee, $type, $date)) {
                        $touched++;
                    }
                }
            }
        );

        return $touched;
    }

    /**
     * Close the leave year an entitlement belongs to and open the next one, carrying
     * what the policy allows.
     *
     * `carried_in = min(unused, max_carry_forward)` with leave.carry_forward on, and
     * 0 with it off. A per-type cap of 0 carries nothing even for a company that
     * carries, which is how "annual carries five days, casual carries none" is
     * expressed without a second setting.
     *
     * Nothing is paid out here. A year-end lapse pays nobody: encashment happens on
     * separation, against `is_encashable`, and a year-end run that paid out days
     * about to lapse would be a third behaviour that quietly reverses the lapse rule
     * (docs/hrms-plan.md §4.1).
     */
    public function reset(LeaveEntitlement $entitlement): LeaveEntitlement
    {
        $employee = $entitlement->employee;
        $type = $entitlement->leaveType;

        [$start, $end] = $this->year->nextWindowAfter($employee, Carbon::parse($entitlement->leave_year_end));

        $next = LeaveEntitlement::firstOrNew([
            'employee_id' => $employee->getKey(),
            'leave_type_id' => $type->getKey(),
            'leave_year_start' => $start->toDateString(),
        ]);

        // Written only when the next year is new. A reset that ran already has
        // decided what carried; recomputing it on a second run would read a balance
        // that the new year's own leave has since moved.
        if (! $next->exists) {
            $next->leave_year_end = $end->toDateString();
            $next->carried_in_days = $this->carryFrom($entitlement, $type);
            $next->accrued_days = $this->accrualFor($employee, $type, $start, $end, $start);
            $next->save();
        }

        return $next;
    }

    /**
     * Roll every entitlement whose year has ended into the next one.
     *
     * @return int entitlements opened for the new year
     */
    public function resetEndedYears(string|Carbon|null $asOf = null): int
    {
        $asOf = $asOf ? Carbon::parse($asOf) : now();
        $opened = 0;

        LeaveEntitlement::query()
            ->whereDate('leave_year_end', '<', $asOf->toDateString())
            ->with(['employee', 'leaveType'])
            ->cursor()
            ->each(function (LeaveEntitlement $entitlement) use (&$opened): void {
                if (! $entitlement->employee || ! $entitlement->leaveType) {
                    return;
                }

                $before = $entitlement->wasRecentlyCreated;
                $next = $this->reset($entitlement);

                $opened += $next->wasRecentlyCreated && ! $before ? 1 : 0;
            });

        return $opened;
    }

    /**
     * How much of a type's annual entitlement this employee has earned as of a date.
     *
     * The first-year pro-rating is the part with a decision in it:
     * `days_per_year × months_remaining / 12`, rounded to the nearest half day so it
     * agrees with the granularity leave_days.portion already uses. The half-month
     * boundary inside months_remaining lives in LeaveYear and stays in code.
     */
    public function accrualFor(
        Employee $employee,
        LeaveType $type,
        Carbon $yearStart,
        Carbon $yearEnd,
        string|Carbon|null $asOf = null,
    ): float {
        $annual = (float) ($type->days_per_year ?? 0);

        if ($annual <= 0) {
            return 0.0;
        }

        $asOf = $asOf ? Carbon::parse($asOf) : now();
        $joined = $employee->date_of_joining ? Carbon::parse($employee->date_of_joining) : null;

        // Compensatory days are earned by working a weekly off or a public holiday,
        // which is an approved attendance_day — and attendance is phase 2. Returning
        // 0 is the honest state: the type can exist, the balance formula is ready,
        // and nothing credits it yet. An accrual invented here would be a balance
        // nobody earned.
        if ($type->accrual_method === LeaveType::ACCRUAL_COMPENSATORY) {
            return 0.0;
        }

        // Statutory shape: nothing until twelve months of service are complete.
        if ($type->accrual_method === LeaveType::ACCRUAL_ON_COMPLETION) {
            if (! $joined || $joined->copy()->addYear()->gt($asOf)) {
                return 0.0;
            }

            return $annual;
        }

        $full = $this->proratedFirstYear($annual, $joined, $yearStart, $yearEnd);

        $periods = match ($type->accrual_method) {
            LeaveType::ACCRUAL_MONTHLY => 12,
            LeaveType::ACCRUAL_SEMI_MONTHLY => 24,
            default => null,
        };

        if ($periods === null) {
            return $full;
        }

        // A twelfth per month, or a twenty-fourth per half-month, capped at the year's
        // own figure so a late run cannot over-credit. Periods are counted from the
        // year start, not the joining date, because the pro-rating above has already
        // accounted for a joiner's shorter year.
        $elapsed = $this->periodsElapsed($yearStart, $asOf, $periods);

        return $this->roundToHalfDay(min($full, $full * $elapsed / $periods));
    }

    /**
     * How many accrual periods of the year have begun by a date, the current one included.
     *
     * Monthly: the month in progress counts from its first day, which is how it has
     * always worked — a twelfth arrives on the 1st. Semi-monthly keeps the same shape at
     * twice the rate: a twenty-fourth arrives on the 1st and another on the 16th, the
     * half-month boundary LeaveYear already uses for a joiner's first month. A year that
     * starts mid-month (anniversary basis) is offset by the half it starts in, so its
     * first period is not counted twice.
     */
    private function periodsElapsed(Carbon $yearStart, Carbon $asOf, int $periodsPerYear): int
    {
        if ($asOf->lt($yearStart)) {
            return 0;
        }

        $months = ($asOf->year - $yearStart->year) * 12 + ($asOf->month - $yearStart->month);

        if ($periodsPerYear === 24) {
            $half = fn (Carbon $date): int => $date->day >= 16 ? 1 : 0;

            return min(24, $months * 2 + $half($asOf) - $half($yearStart) + 1);
        }

        return min(12, $months + 1);
    }

    /**
     * The annual figure, pro-rated when this is the employee's first leave year and
     * leave.prorate_first_year is on.
     */
    private function proratedFirstYear(float $annual, ?Carbon $joined, Carbon $yearStart, Carbon $yearEnd): float
    {
        if (! $joined || $joined->lte($yearStart) || ! setting('leave.prorate_first_year')) {
            return $this->roundToHalfDay($annual);
        }

        $months = $this->year->monthsRemaining($joined, $yearStart, $yearEnd);

        return $this->roundToHalfDay($annual * $months / 12);
    }

    private function carryFrom(LeaveEntitlement $entitlement, LeaveType $type): float
    {
        if (! setting('leave.carry_forward') || ! $type->carriesForward()) {
            return 0.0;
        }

        $breakdown = $this->balance->for(
            $entitlement->employee,
            $type,
            Carbon::parse($entitlement->leave_year_start),
        );

        $unused = max(0.0, $breakdown?->remaining() ?? 0.0);

        return $this->roundToHalfDay(min($unused, (float) $type->max_carry_forward));
    }

    /**
     * The granularity this whole module rounds to.
     *
     * Half a day, because leave_days.portion is 1.0 or 0.5 and an entitlement of
     * 12.7 days could never be spent down to zero.
     */
    private function roundToHalfDay(float $days): float
    {
        return round($days * 2) / 2;
    }
}
