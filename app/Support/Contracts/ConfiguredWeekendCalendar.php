<?php

namespace App\Support\Contracts;

use Carbon\CarbonInterface;

/**
 * The answer when Attendance is not installed: the company's configured weekend.
 *
 * This is not a stub that returns something harmless — it is the behaviour a
 * company without the Attendance module has always had, moved out of an `if` and
 * into a binding. `config/leave.php` describes `weekend_days` as a stopgap for
 * exactly this case.
 */
class ConfiguredWeekendCalendar implements WorkingDayCalendar
{
    public function isWorkingDay(?int $employeeId, CarbonInterface $date): bool
    {
        return ! in_array($date->dayOfWeekIso, $this->weekendDays(), true);
    }

    /**
     * Lifted verbatim from LeaveDayGenerator, including the default and the range
     * filter.
     *
     * A per-tenant `setting()`, not `config()` — one company on a six-day week and
     * another on five is exactly the case this exists for, and reading the config
     * file instead would give every company the same weekend while looking right.
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
