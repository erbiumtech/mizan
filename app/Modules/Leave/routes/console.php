<?php

use App\Modules\Leave\Console\Commands\OpenLeaveYear;
use Illuminate\Support\Facades\Schedule;

/**
 * Leave accrual and the year-end roll.
 *
 * Daily rather than annually, and that is the point. A once-a-year schedule entry
 * has to fire on the one morning it matters, on a host that might have been down,
 * for a company whose leave year may not be January at all — leave.year_basis offers
 * fiscal and per-employee anniversary years, so there is no single date this could
 * run on. A daily idempotent sweep asks "is everybody's current year open" and
 * answers it whenever the answer changes.
 *
 * It also carries the periodic accruals: a type with accrual_method =
 * monthly_accrual earns a twelfth on the 1st of each month, and semi_monthly_accrual
 * a twenty-fourth on the 1st and the 16th. Both are recomputed rather than
 * incremented, so a missed day costs nothing.
 *
 * Early, before anybody files leave against a year that has not been opened.
 * TenantAware, and it skips companies with the module switched off.
 */
Schedule::command(OpenLeaveYear::class)
    ->dailyAt('01:30')
    ->withoutOverlapping();
