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
    /**
     * Every dated assignment an employee has, loaded once.
     *
     * **Keyed on the employee, not on the employee and the date.** It used to be the latter, which meant a
     * distinct query for every day asked about: `AttendanceCalendar::summarise()` walks a month a day at a
     * time, so one employee's month cost 31 queries and a company-wide attendance register cost 31 per head.
     * An employee's assignments do not change between two days of one month — the *answer* does, but the
     * rows do not — so the rows are fetched once and the date is resolved against them in memory.
     *
     * Found while building the monthly attendance register (`docs/reports-expansion-plan.md` Phase 3.1),
     * which was not otherwise possible: forty employees over a month was upwards of twelve hundred queries
     * before this. Payroll benefits too — payslip generation calls `summarise()` per employee.
     *
     * @var array<int, \Illuminate\Support\Collection<int, EmployeeWorkPattern>>
     */
    private array $assignments = [];

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
        $on = Carbon::parse($date)->toDateString();
        $key = $employeeId.'@'.$on;

        if (! array_key_exists($key, $this->cache)) {
            $assigned = $this->assignmentsFor($employeeId)
                ->first(fn (EmployeeWorkPattern $row): bool => $row->from_date->toDateString() <= $on
                    && ($row->to_date === null || $row->to_date->toDateString() >= $on));

            $this->cache[$key] = $assigned?->pattern ?? $this->defaultPattern();
        }

        return $this->cache[$key];
    }

    /**
     * One query per employee, whatever the range asked about.
     *
     * Ordered by `from_date` descending so that `first()` reproduces the old query's rule exactly: **latest
     * wins if somebody has overlapping assignments.** Overlap is not prevented in the schema — "no two
     * ranges overlap" is not a column constraint — so answering deterministically matters more than
     * pretending it cannot happen.
     *
     * @return \Illuminate\Support\Collection<int, EmployeeWorkPattern>
     */
    private function assignmentsFor(int|string $employeeId): \Illuminate\Support\Collection
    {
        return $this->assignments[$employeeId] ??= EmployeeWorkPattern::query()
            ->where('employee_id', $employeeId)
            ->orderByDesc('from_date')
            ->with('pattern.days')
            ->get();
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
        $this->assignments = [];
        $this->defaultPattern = false;
    }

    /**
     * The company default, resolved once.
     *
     * `false` rather than `null` for "not yet asked", because null is a real answer — a company with no
     * pattern at all — and the two have to be distinguishable or the miss is re-queried every time.
     *
     * Memoised for the same reason the assignments are: `for()` is called once per day and most companies
     * have no dated assignments, so this was the fallback taken thirty-one times a month per employee at two
     * queries each. Caching the assignments alone left 57 queries for one employee's month, which the
     * attendance register's own query-count test is what caught.
     */
    private WorkPattern|null|false $defaultPattern = false;

    private function defaultPattern(): ?WorkPattern
    {
        if ($this->defaultPattern !== false) {
            return $this->defaultPattern;
        }

        return $this->defaultPattern = WorkPattern::query()->with('days')->where('is_default', true)->first()
            ?? WorkPattern::query()->with('days')->orderBy('id')->first();
    }
}
