<?php

use App\Modules\Attendance\Console\Commands\AccrueCompensatoryOff;
use App\Modules\Attendance\Models\AttendanceDay;
use Illuminate\Support\Facades\Schedule;

/**
 * Compensatory off, and the retention this table needs from its first day.
 *
 * The accrual runs after leave:open-year (01:30) so that a leave year exists to credit
 * into on the morning one turns over.
 */
Schedule::command(AccrueCompensatoryOff::class)
    ->dailyAt('02:00')
    ->withoutOverlapping();

// attendance_days is the only table in the HR schema that grows with usage rather
// than headcount — roughly 11k rows a year per tenant. Prunable from day one, with
// the window in attendance.retention_months.
//
// The model is named as a class CONSTANT, never as a string: a
// `--model=App\Modules\...` string would silently stop resolving the moment the class
// moved, and fail at 00:00 in a queue worker rather than in CI. The comment on the
// same entry in Projects records that this has already happened here once.
Schedule::command('tenants:artisan', ['model:prune --model='.AttendanceDay::class])
    ->daily();
