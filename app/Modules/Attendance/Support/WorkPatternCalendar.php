<?php

namespace App\Modules\Attendance\Support;

use App\Modules\Attendance\Services\WorkPatternResolver;
use App\Support\Contracts\ConfiguredWeekendCalendar;
use App\Support\Contracts\WorkingDayCalendar;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;

/**
 * Attendance's answer to "does this person work that day".
 *
 * The licence check that used to sit in `LeaveDayGenerator::consumedDates()` lives
 * here now. It has not been dropped — it has moved to the module that owns the
 * concept, which is where it belonged. A company without the Attendance licence
 * gets exactly what it got before: the configured weekend.
 *
 * Note the binding is unconditional and the *check* is per call. Plugins register
 * at boot while the company is resolved per request, so a binding cannot be
 * per-company; only the answer can.
 */
class WorkPatternCalendar implements WorkingDayCalendar
{
    public function __construct(
        private readonly WorkPatternResolver $resolver,
        private readonly ConfiguredWeekendCalendar $fallback,
    ) {}

    public function isWorkingDay(?int $employeeId, CarbonInterface $date): bool
    {
        if ($employeeId === null || ! modules()->enabled('attendance')) {
            return $this->fallback->isWorkingDay($employeeId, $date);
        }

        return $this->resolver->isWorkingDay($employeeId, Carbon::instance($date));
    }
}
