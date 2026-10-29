<?php

namespace App\Modules\Performance\Rules;

use App\Modules\Employees\Models\Employee;
use App\Modules\Employees\Rules\Metric;
use App\Modules\Employees\Rules\RuleRegistry;
use App\Modules\Employees\Rules\Window;
use App\Modules\Performance\Models\DailyReport;
use App\Modules\Performance\Models\TaskOccurrence;
use App\Modules\Performance\Services\MeritLedger;
use App\Support\Contracts\WorkingDayCalendar;
use Illuminate\Support\Carbon;

/** reports.*, tasks.*, merit.balance — docs/saas-launch-plan.md §8.9. */
abstract class PerformanceMetrics implements Metric
{
    /** Working days in the window, from the calendar the company has — weekdays when it has none. */
    protected function workingDays(Employee $employee, Window $window): int
    {
        $calendar = app()->bound(WorkingDayCalendar::class) ? app(WorkingDayCalendar::class) : null;
        $days = 0;

        for ($day = $window->from->copy(); $day->lte($window->to); $day->addDay()) {
            $days += $calendar ? (int) $calendar->isWorkingDay($employee->getKey(), $day) : (int) $day->isWeekday();
        }

        return $days;
    }

    /** @return array<int, Metric> */
    public static function all(): array
    {
        return [
            new class extends PerformanceMetrics
            {
                public function key(): string
                {
                    return 'reports.filed_ratio';
                }

                public function label(): string
                {
                    return 'Daily reports: filed ratio';
                }

                public function type(): string
                {
                    return RuleRegistry::TYPE_RATIO;
                }

                public function value(Employee $employee, Window $window): int|float
                {
                    $working = $this->workingDays($employee, $window);
                    $filed = DailyReport::query()->where('employee_id', $employee->getKey())
                        ->whereBetween('report_date', [$window->fromDate(), $window->toDate()])->count();

                    return $working === 0 ? 0 : round(min(1, $filed / $working), 4);
                }
            },
            new class extends PerformanceMetrics
            {
                public function key(): string
                {
                    return 'reports.on_time_streak_days';
                }

                public function label(): string
                {
                    return 'Daily reports: on-time streak (days)';
                }

                public function type(): string
                {
                    return RuleRegistry::TYPE_NUMBER;
                }

                /** Consecutive working days up to the window's end with a report filed the same day. */
                public function value(Employee $employee, Window $window): int|float
                {
                    $filed = DailyReport::query()->where('employee_id', $employee->getKey())
                        ->whereBetween('report_date', [$window->fromDate(), $window->toDate()])
                        ->whereNotNull('submitted_at')
                        ->whereRaw('date(submitted_at) <= date(report_date)')
                        ->pluck('report_date')
                        ->map(fn ($d): string => Carbon::parse($d)->toDateString())
                        ->flip();

                    $calendar = app()->bound(WorkingDayCalendar::class) ? app(WorkingDayCalendar::class) : null;
                    $streak = 0;

                    for ($day = $window->to->copy(); $day->gte($window->from); $day->subDay()) {
                        $working = $calendar ? $calendar->isWorkingDay($employee->getKey(), $day) : $day->isWeekday();

                        if (! $working) {
                            continue;
                        }

                        if (! isset($filed[$day->toDateString()])) {
                            break;
                        }

                        $streak++;
                    }

                    return $streak;
                }
            },
            new class extends PerformanceMetrics
            {
                public function key(): string
                {
                    return 'tasks.missed';
                }

                public function label(): string
                {
                    return 'Tasks: missed';
                }

                public function type(): string
                {
                    return RuleRegistry::TYPE_NUMBER;
                }

                public function value(Employee $employee, Window $window): int|float
                {
                    // Missed and not excused — the status the occurrence derives, as a query.
                    return TaskOccurrence::query()->where('employee_id', $employee->getKey())
                        ->whereDate('due_on', '>=', $window->fromDate())->whereDate('due_on', '<=', $window->toDate())
                        ->missedUnexcused()->whereNull('completed_at')->count();
                }
            },
            new class extends PerformanceMetrics
            {
                public function key(): string
                {
                    return 'tasks.completed_ratio';
                }

                public function label(): string
                {
                    return 'Tasks: completed ratio';
                }

                public function type(): string
                {
                    return RuleRegistry::TYPE_RATIO;
                }

                public function value(Employee $employee, Window $window): int|float
                {
                    // Completed over completed-plus-missed; an excused one counts for neither.
                    $base = TaskOccurrence::query()->where('employee_id', $employee->getKey())
                        ->whereDate('due_on', '>=', $window->fromDate())->whereDate('due_on', '<=', $window->toDate())
                        ->whereNull('excused_at')
                        ->where(fn ($q) => $q->whereNotNull('completed_at')->orWhereNotNull('missed_at'));
                    $total = (clone $base)->count();

                    return $total === 0 ? 0 : round((clone $base)->whereNotNull('completed_at')->count() / $total, 4);
                }
            },
            new class extends PerformanceMetrics
            {
                public function key(): string
                {
                    return 'merit.balance';
                }

                public function label(): string
                {
                    return 'Merit: points balance';
                }

                public function type(): string
                {
                    return RuleRegistry::TYPE_NUMBER;
                }

                public function value(Employee $employee, Window $window): int|float
                {
                    return app(MeritLedger::class)->balance($employee, $window->fromDate(), $window->toDate());
                }
            },
        ];
    }
}
