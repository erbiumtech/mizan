<?php

namespace App\Modules\Payroll\Support;

use App\Modules\Payroll\Models\PayrollRun;
use App\Support\Contracts\PeriodLock;
use Illuminate\Support\Carbon;

/**
 * Payroll's answer to "has this month been settled".
 *
 * Moved out of `RegularizationService::monthIsSettled()`, which had to import
 * PayrollRun to ask — the import that made `attendance -> payroll` a cycle, since
 * Payroll requires Employees and Attendance does too.
 *
 * The licence guard comes along with the query, for the same reason as
 * WorkPatternCalendar: a company without Payroll has no runs, so every month is
 * open, which is what the original guard returned.
 */
class PayrollRunPeriodLock implements PeriodLock
{
    public function isLocked(int $year, int $month): bool
    {
        if (! modules()->enabled('payroll')) {
            return false;
        }

        $firstOfMonth = Carbon::create($year, $month, 1)->toDateString();

        return PayrollRun::query()
            ->locked()
            ->where('month', Carbon::create($year, $month, 1)->format('F'))
            // The month name repeats every fiscal year, so the year has to come from
            // the run's fiscal year or a lock in July 2025 would freeze July 2026.
            ->whereHas('fiscalYear', fn ($fiscalYear) => $fiscalYear
                ->whereDate('start_date', '<=', $firstOfMonth)
                ->whereDate('end_date', '>=', $firstOfMonth))
            ->exists();
    }
}
