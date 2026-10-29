<?php

namespace App\Modules\Attendance\Services;

use App\Modules\Attendance\Models\AttendanceDay;
use App\Modules\Attendance\Models\OvertimeRequest;
use App\Modules\Core\Models\User;
use Illuminate\Support\Carbon;
use InvalidArgumentException;

/** Decide an overtime request — docs/saas-launch-plan.md §14.4. */
class OvertimeRequests
{
    public function approve(OvertimeRequest $request, User $by): OvertimeRequest
    {
        if (! $request->isPending()) {
            throw new InvalidArgumentException("This request is already {$request->status}.");
        }

        if ($request->employee?->user_id !== null && (int) $request->employee->user_id === (int) $by->getKey()) {
            throw new InvalidArgumentException('Overtime cannot be approved by the person who worked it.');
        }

        $day = AttendanceDay::forDate((int) $request->employee_id, Carbon::parse($request->worked_on));

        if ($day === null) {
            $day = AttendanceDay::create([
                'employee_id' => $request->employee_id,
                'date' => $request->worked_on->toDateString(),
                'status' => AttendanceDay::STATUS_PRESENT,
                'source' => AttendanceDay::SOURCE_MANUAL,
                'note' => 'Overtime approved',
            ]);
        }

        // The minutes land where payroll reads them: on the day.
        $day->forceFill(['overtime_minutes' => (int) $day->overtime_minutes + $request->minutes])->save();

        $request->update(['status' => OvertimeRequest::STATUS_APPROVED, 'decided_by' => $by->getKey(), 'decided_at' => now(), 'refusal_reason' => null]);

        return $request->refresh();
    }

    public function refuse(OvertimeRequest $request, User $by, string $reason): OvertimeRequest
    {
        if (! $request->isPending()) {
            throw new InvalidArgumentException("This request is already {$request->status}.");
        }

        if (trim($reason) === '') {
            throw new InvalidArgumentException('A refusal needs a reason.');
        }

        $request->update(['status' => OvertimeRequest::STATUS_REFUSED, 'decided_by' => $by->getKey(), 'decided_at' => now(), 'refusal_reason' => trim($reason)]);

        return $request->refresh();
    }
}
