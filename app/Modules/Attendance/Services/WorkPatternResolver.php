<?php

namespace App\Modules\Attendance\Services;

use App\Modules\Attendance\Models\EmployeeWorkPattern;
use App\Modules\Attendance\Models\WorkPattern;
use App\Modules\Employees\Models\Employee;
use Illuminate\Support\Carbon;

/**
 * Which pattern an employee was on, on a given date — and therefore whether that date
 * was a working day for them.
 *
 * This is the answer HolidayCalendar deliberately refused to give. It has no
 * isWorkingDay() because a weekend is a pattern question, not a date question, and a
 * method there assuming Sat/Sun would be wrong for every company on a six-day week and
 * wrong silently.
 *
 * Memoised per request, like EmployeeAccess: the leave-day generator asks once per
 * calendar day of a request, and the month calendar asks once per employee per day of
 * a month.
 */
class WorkPatternResolver
{
    /** @var array<string, ?WorkPattern> */
    private array $cache = [];

    /**
     * The pattern in force for an employee on a date.
     *
     * Falls back to the company default when the employee has no dated assignment,
     * which is the ordinary case: most companies have one pattern and never assign it
     * explicitly. Returns null only when no pattern exists at all.
     */
    public function for(Employee|int $employee, string|Carbon $date): ?WorkPattern
    {
        $employeeId = $employee instanceof Employee ? $employee->getKey() : $employee;
        $key = $employeeId.'@'.Carbon::parse($date)->toDateString();

        if (! array_key_exists($key, $this->cache)) {
            $assigned = EmployeeWorkPattern::query()
                ->where('employee_id', $employeeId)
                ->inForceOn($date)
                // Latest wins if somebody has overlapping assignments. Overlap is not
                // prevented in the schema — "no two ranges overlap" is not a column
                // constraint — so answering deterministically matters more than
                // pretending it cannot happen.
                ->orderByDesc('from_date')
                ->with('pattern.days')
                ->first();

            $this->cache[$key] = $assigned?->pattern ?? $this->defaultPattern();
        }

        return $this->cache[$key];
    }

    /**
     * Whether a date is a working day for this employee, by the pattern alone.
     *
     * Holidays are NOT considered here — that is HolidayCalendar's question, and
     * combining the two in one method is how a caller ends up asking only half of it.
     * AttendanceCalendar is where they are combined.
     */
    public function isWorkingDay(Employee|int $employee, string|Carbon $date): bool
    {
        $pattern = $this->for($employee, $date);

        // No pattern at all: assume a working day. The alternative — treating an
        // unconfigured company as working no days — would make every month read as
        // entirely weekly-off, and phase 3's divisor would be zero.
        if (! $pattern) {
            return ! in_array(Carbon::parse($date)->dayOfWeekIso, [6, 7], true);
        }

        return $pattern->worksOn(Carbon::parse($date)->dayOfWeekIso);
    }

    /**
     * How long the working day is for this employee on this date, in hours.
     *
     * Null when it is not a working day, or when the pattern does not say. Phase 3a
     * divides by this to derive an hourly rate, so a null has to travel rather than
     * being coerced to a zero somebody then divides by.
     */
    public function expectedHours(Employee|int $employee, string|Carbon $date): ?float
    {
        return $this->for($employee, $date)?->expectedHoursOn(Carbon::parse($date)->dayOfWeekIso);
    }

    public function flush(): void
    {
        $this->cache = [];
    }

    private function defaultPattern(): ?WorkPattern
    {
        return WorkPattern::query()->with('days')->where('is_default', true)->first()
            ?? WorkPattern::query()->with('days')->orderBy('id')->first();
    }
}
