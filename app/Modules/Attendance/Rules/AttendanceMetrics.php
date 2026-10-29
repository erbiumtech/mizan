<?php

namespace App\Modules\Attendance\Rules;

use App\Modules\Attendance\Models\AttendanceDay;
use App\Modules\Employees\Models\Employee;
use App\Modules\Employees\Rules\Metric;
use App\Modules\Employees\Rules\RuleRegistry;
use App\Modules\Employees\Rules\Window;

/** attendance.* — docs/saas-launch-plan.md §8.9. Read from attendance_days as it stands. */
abstract class AttendanceMetrics implements Metric
{
    protected function days(Employee $employee, Window $window)
    {
        return AttendanceDay::query()
            ->where('employee_id', $employee->getKey())
            ->whereBetween('date', [$window->fromDate(), $window->toDate()]);
    }

    /** @return array<int, Metric> */
    public static function all(): array
    {
        return [
            new class extends AttendanceMetrics
            {
                public function key(): string
                {
                    return 'attendance.present_ratio';
                }

                public function label(): string
                {
                    return 'Attendance: present ratio';
                }

                public function type(): string
                {
                    return RuleRegistry::TYPE_RATIO;
                }

                public function value(Employee $employee, Window $window): int|float
                {
                    $counted = $this->days($employee, $window)->whereIn('status', [
                        AttendanceDay::STATUS_PRESENT, AttendanceDay::STATUS_ABSENT, AttendanceDay::STATUS_HALF_DAY,
                        AttendanceDay::STATUS_WORK_FROM_HOME, AttendanceDay::STATUS_ON_LEAVE,
                    ])->count();
                    $present = $this->days($employee, $window)->whereIn('status', [AttendanceDay::STATUS_PRESENT, AttendanceDay::STATUS_WORK_FROM_HOME])->count()
                        + $this->days($employee, $window)->where('status', AttendanceDay::STATUS_HALF_DAY)->count() * 0.5;

                    return $counted === 0 ? 0 : round($present / $counted, 4);
                }
            },
            new class extends AttendanceMetrics
            {
                public function key(): string
                {
                    return 'attendance.late_marks';
                }

                public function label(): string
                {
                    return 'Attendance: late marks';
                }

                public function type(): string
                {
                    return RuleRegistry::TYPE_NUMBER;
                }

                public function value(Employee $employee, Window $window): int|float
                {
                    return $this->days($employee, $window)->where('late_minutes', '>', 0)->count();
                }
            },
            new class extends AttendanceMetrics
            {
                public function key(): string
                {
                    return 'attendance.absent_days';
                }

                public function label(): string
                {
                    return 'Attendance: absent days';
                }

                public function type(): string
                {
                    return RuleRegistry::TYPE_NUMBER;
                }

                public function value(Employee $employee, Window $window): int|float
                {
                    return $this->days($employee, $window)->where('status', AttendanceDay::STATUS_ABSENT)->count();
                }
            },
        ];
    }
}
