<?php

namespace App\Modules\Leave\Services;

use App\Modules\Attendance\Services\WorkPatternResolver;
use App\Modules\Core\Services\HolidayCalendar;
use App\Modules\Employees\Models\Employee;
use App\Modules\Leave\Models\LeaveDay;
use App\Modules\Leave\Models\LeaveRequest;
use Illuminate\Support\Carbon;
use InvalidArgumentException;

/**
 * Which calendar days a request actually consumes.
 *
 * Called once, when a request is approved, and never again. That is the whole
 * contract: a holiday added retroactively, a sandwich policy switched on, or a
 * weekend list corrected must not change leave somebody has already taken and that
 * has already reached a payslip. Every setting this class reads is read at
 * generation time and stamped or implied on the rows it writes.
 *
 * The output is rows rather than a count because payroll needs to know which days
 * fell in which month: a leave from 28 January to 3 February splits across two
 * payslips, and a `days` total cannot be split. docs/hrms-plan.md §4.1.
 */
class LeaveDayGenerator
{
    public const SANDWICH_OFF = 'off';

    /** Non-working days *between* two leave days are consumed. */
    public const SANDWICH_ENCLOSED = 'enclosed';

    public const SANDWICH_SETTING_KEY = 'leave.sandwich_rule';

    /**
     * Refused in two places — at submission and again at generation — so the message
     * is shared rather than written twice and drifting.
     */
    public const NO_WORKING_DAYS = 'This request covers no working days: every date in the range is a weekend or a holiday.';

    public function __construct(private readonly HolidayCalendar $holidays) {}

    /**
     * Write the days for a request, replacing anything already there.
     *
     * Replacing rather than appending because the one legitimate caller that runs
     * twice is a correction: an approval that failed mid-transaction leaves nothing,
     * but a re-approval after a range edit must not add a second set alongside the
     * first. The unique key on (leave_request_id, date) would catch the overlap and
     * miss the rest.
     *
     * @return int the number of leave_days rows written
     */
    public function generate(LeaveRequest $request): int
    {
        $days = $this->plan($request);

        if ($days === []) {
            throw new InvalidArgumentException(self::NO_WORKING_DAYS);
        }

        $request->days()->delete();

        $request->days()->createMany($days);

        // Both are projections of the rows just written, recomputed here rather
        // than trusted from the caller so they cannot disagree with the days.
        $request->forceFill([
            'days' => array_sum(array_column($days, 'portion')),
            'sandwich_rule_applied' => $this->sandwichRule() === self::SANDWICH_ENCLOSED,
        ])->saveQuietly();

        return count($days);
    }

    /**
     * What generate() would write, without writing it.
     *
     * Public because the form wants to tell somebody "this will use 3 days" before
     * they submit, and because a balance check at submission has to know the cost of
     * a request that has no rows yet.
     *
     * @return array<int, array{date: string, portion: float, is_paid: bool}>
     */
    public function plan(LeaveRequest $request): array
    {
        $from = Carbon::parse($request->from_date)->startOfDay();
        $to = Carbon::parse($request->to_date)->startOfDay();

        if ($to->lt($from)) {
            throw new InvalidArgumentException('A leave request cannot end before it starts.');
        }

        $type = $request->leaveType;
        $isPaid = (bool) $type?->is_paid;

        if ($request->is_half_day) {
            return $this->halfDay($request, $from, $to, $isPaid);
        }

        $consumed = $this->consumedDates($from, $to, $request->employee);

        return array_map(fn (string $date): array => [
            'date' => $date,
            'portion' => LeaveDay::PORTION_FULL,
            'is_paid' => $isPaid,
        ], $consumed);
    }

    /**
     * The dates a range consumes, weekends and holidays removed — then put back
     * when they are enclosed and the policy says so.
     *
     * @return array<int, string>
     */
    private function consumedDates(Carbon $from, Carbon $to, ?Employee $employee = null): array
    {
        $holidays = array_flip($this->holidays->datesBetween($from, $to));
        $weekend = $this->weekendDays();

        // With `attendance` licensed, the employee's own work pattern answers which
        // days are worked — which is what config/leave.php promised when it called
        // leave.weekend_days a stopgap. The setting stays for companies without the
        // module, so this is a guarded coupling rather than a requirement.
        $patterns = $employee && modules()->enabled('attendance')
            ? app(WorkPatternResolver::class)
            : null;

        $working = [];
        $all = [];

        for ($date = $from->copy(); $date->lte($to); $date->addDay()) {
            $key = $date->toDateString();
            $all[] = $key;

            $isNonWorking = $patterns
                ? ! $patterns->isWorkingDay($employee, $date)
                : in_array($date->dayOfWeekIso, $weekend, true);

            $isHoliday = isset($holidays[$key]);

            if (! $isNonWorking && ! $isHoliday) {
                $working[] = $key;
            }
        }

        if ($working === [] || $this->sandwichRule() !== self::SANDWICH_ENCLOSED) {
            return $working;
        }

        // Enclosed: everything from the first working day to the last, inclusive,
        // whatever it is. A Friday-plus-Monday request therefore consumes four days.
        //
        // The edges are deliberately excluded — a range that opens or closes on a
        // weekend does not consume it. The stricter reading some policies take makes
        // a single Friday's leave cost three days, which nobody expects and no
        // policy anybody here has read asks for.
        $first = array_search($working[0], $all, true);
        $last = array_search($working[count($working) - 1], $all, true);

        return array_slice($all, $first, $last - $first + 1);
    }

    /**
     * @return array<int, array{date: string, portion: float, is_paid: bool}>
     */
    private function halfDay(LeaveRequest $request, Carbon $from, Carbon $to, bool $isPaid): array
    {
        if (! $from->isSameDay($to)) {
            throw new InvalidArgumentException('A half day is one day: set the same date for both ends.');
        }

        if ($request->leaveType && ! $request->leaveType->allows_half_day) {
            throw new InvalidArgumentException(
                "{$request->leaveType->label} cannot be taken as a half day."
            );
        }

        // A half day on a day the company does not work is not half a day off, and
        // recording it would consume half a day of somebody's balance for nothing.
        if ($this->consumedDates($from, $to, $request->employee) === []) {
            throw new InvalidArgumentException(
                'That date is a weekend or a holiday, so there is no half day to take.'
            );
        }

        return [[
            'date' => $from->toDateString(),
            'portion' => LeaveDay::PORTION_HALF,
            'is_paid' => $isPaid,
        ]];
    }

    private function sandwichRule(): string
    {
        return (string) setting(self::SANDWICH_SETTING_KEY, self::SANDWICH_OFF) === self::SANDWICH_ENCLOSED
            ? self::SANDWICH_ENCLOSED
            : self::SANDWICH_OFF;
    }

    /**
     * The weekday numbers the company does not work, ISO-8601 (1 = Mon, 7 = Sun).
     *
     * The fallback for a company WITHOUT `attendance`. With that module licensed the
     * employee's own work pattern answers instead (see consumedDates), which is what
     * config/leave.php promised when it called this key a stopgap — it is not removed,
     * because `leave` requires only `employees` and must keep working alone.
     *
     * HolidayCalendar deliberately refuses to answer this: it has no isWorkingDay(),
     * because a method there assuming Sat/Sun would be wrong for every company on a
     * six-day week and wrong silently.
     *
     * @return array<int, int>
     */
    private function weekendDays(): array
    {
        $days = setting('leave.weekend_days', [6, 7]);

        return array_values(array_filter(
            array_map('intval', is_array($days) ? $days : []),
            fn (int $day): bool => $day >= 1 && $day <= 7,
        ));
    }
}
