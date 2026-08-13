<?php

namespace App\Modules\Attendance\Services;

use App\Modules\Attendance\Models\AttendanceDay;
use App\Modules\Leave\Models\LeaveEntitlement;
use App\Modules\Leave\Models\LeaveType;
use App\Modules\Leave\Services\LeaveYear;
use Illuminate\Support\Carbon;

/**
 * Phase 2a: working a day off earns a day back.
 *
 * Not a new mechanism, and deliberately so — it is a `leave_type` with
 * `accrual_method = compensatory`, credited by an approved `attendance_day` whose
 * status is `weekly_off` or `holiday` and whose worked minutes are non-zero. Every
 * input already existed; this class only joins them.
 *
 * Two constraints make it safe rather than a second balance nobody can explain:
 *
 *  1. **It accrues only from recorded attendance**, so it cannot be self-granted by
 *     typing a leave adjustment. The attendance day is the evidence.
 *  2. **It expires.** This is the one place this plan family admits a lapse date it
 *     refused for carry-forward, and the reason is specific: a comp-off earned in
 *     March and taken three years later is not time off *in lieu* of anything.
 *
 * Runs from the daily command, and is idempotent: the credit is *recomputed* from the
 * qualifying days inside the window rather than incremented, so a second run in one
 * day cannot double it and a missed run costs nothing.
 */
class CompensatoryOffAccrual
{
    public function __construct(private readonly LeaveYear $year) {}

    /**
     * Recompute every employee's compensatory credit.
     *
     * @return int employees whose credit changed
     */
    public function accrue(?string $asOf = null): int
    {
        // Guarded on `leave`: comp-off is a leave balance, and a company that has
        // attendance without leave has nowhere to put it. Not an error — the days are
        // still recorded, they simply do not credit anything.
        if (! modules()->enabled('leave')) {
            return 0;
        }

        $type = LeaveType::query()
            ->where('accrual_method', LeaveType::ACCRUAL_COMPENSATORY)
            ->where('is_active', true)
            ->first();

        // No compensatory type configured is the ordinary case, not a failure: a
        // company that does not offer time in lieu simply has no such row.
        if (! $type) {
            return 0;
        }

        $asOf = $asOf ? Carbon::parse($asOf) : now();
        $expiryDays = (int) setting('attendance.comp_off_expiry_days', 90);
        $earliest = $asOf->copy()->subDays($expiryDays)->toDateString();

        // Only days inside the expiry window count. A credit is therefore not a
        // running total that has to be decremented when something lapses — it is
        // simply what the window currently holds, which is why this is idempotent.
        $earned = AttendanceDay::query()
            ->whereIn('status', AttendanceDay::NON_WORKING_STATUSES)
            ->where('worked_minutes', '>', 0)
            ->whereBetween('date', [$earliest, $asOf->toDateString()])
            ->selectRaw('employee_id, count(*) as days')
            ->groupBy('employee_id')
            ->pluck('days', 'employee_id');

        $changed = 0;

        foreach ($earned as $employeeId => $days) {
            $entitlement = $this->entitlementFor((int) $employeeId, $type, $asOf);

            if (! $entitlement) {
                continue;
            }

            if ((float) $entitlement->accrued_days !== (float) $days) {
                $entitlement->update(['accrued_days' => $days]);
                $changed++;
            }
        }

        // Employees whose window has emptied: their credit has to fall back to zero, or
        // an expired comp-off stays spendable for ever. Handled separately because they
        // are absent from the aggregate above by definition.
        $changed += $this->expireCreditsOutsideWindow($type, $earned->keys()->all(), $asOf);

        return $changed;
    }

    /**
     * Zero the credit for anybody who has one but no qualifying day left in the window.
     *
     * @param  array<int, int|string>  $stillEarning
     */
    private function expireCreditsOutsideWindow(LeaveType $type, array $stillEarning, Carbon $asOf): int
    {
        return LeaveEntitlement::query()
            ->where('leave_type_id', $type->getKey())
            ->where('accrued_days', '>', 0)
            ->whereNotIn('employee_id', $stillEarning ?: [0])
            ->covering($asOf)
            ->update(['accrued_days' => 0]);
    }

    private function entitlementFor(int $employeeId, LeaveType $type, Carbon $asOf): ?LeaveEntitlement
    {
        $existing = LeaveEntitlement::query()
            ->where('employee_id', $employeeId)
            ->where('leave_type_id', $type->getKey())
            ->covering($asOf)
            ->first();

        if ($existing) {
            return $existing;
        }

        $employee = \App\Modules\Employees\Models\Employee::find($employeeId);

        if (! $employee) {
            return null;
        }

        [$start, $end] = $this->year->windowFor($employee, $asOf);

        return LeaveEntitlement::create([
            'employee_id' => $employeeId,
            'leave_type_id' => $type->getKey(),
            'leave_year_start' => $start->toDateString(),
            'leave_year_end' => $end->toDateString(),
        ]);
    }
}
