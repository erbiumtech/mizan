<?php

namespace App\Modules\Attendance\Services;

use App\Modules\Attendance\Models\AttendanceDay;
use App\Modules\Attendance\Models\AttendanceRegularization;
use App\Modules\Core\Models\User;
use App\Modules\Payroll\Models\PayrollRun;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Deciding "I was here that day".
 *
 * Two refusals here are structural rather than policy, and neither is negotiable by a
 * setting:
 *
 *  1. **A day covered by approved leave cannot be regularized.** Same contradiction
 *     the importer refuses; letting a correction win would make the leave register
 *     wrong without touching it.
 *  2. **A day inside a settled payroll month cannot be regularized.** Once a run is
 *     locked, the figures behind it are what somebody was paid. Changing the
 *     attendance underneath a settled month makes the payslip unreproducible — the
 *     same principle as never recomputing leave_days after approval.
 *
 * The second is guarded on `payroll`: a company without it has no runs to lock, and
 * the check simply passes.
 */
class RegularizationService
{
    public function __construct(private readonly AttendanceRecorder $recorder) {}

    public function submit(AttendanceRegularization $request, User $submitter): AttendanceRegularization
    {
        $this->assertRegularizable($request);

        $request->forceFill([
            'status' => AttendanceRegularization::STATUS_PENDING,
            'submitted_by' => $submitter->getKey(),
        ])->save();

        return $request;
    }

    /**
     * Approve it, and write the day.
     *
     * The original request keeps its own `requested_*` values, so the correction is
     * auditable rather than a silent overwrite: the day says what it says now, and the
     * request says what was asked for and by whom.
     */
    public function approve(AttendanceRegularization $request, User $approver): AttendanceDay
    {
        if (! $request->isPending()) {
            throw new InvalidArgumentException("This correction is already {$request->status}.");
        }

        if ($request->belongsToUser($approver)) {
            throw new InvalidArgumentException(
                'A correction cannot be approved by the person it is about — the point of an approver is that it is somebody else.'
            );
        }

        // Re-checked at approval, not only at submission. A payroll month can be
        // locked, or leave approved, in between.
        $this->assertRegularizable($request);

        return DB::transaction(function () use ($request, $approver): AttendanceDay {
            $day = $this->recorder->record(
                employee: $request->employee,
                date: $request->date,
                status: $request->requested_status,
                checkIn: $request->requested_check_in_at?->format('H:i'),
                checkOut: $request->requested_check_out_at?->format('H:i'),
                // The enum anticipated exactly this: a day whose figures came from the
                // employee rather than a clerk or a device.
                source: AttendanceDay::SOURCE_SELF_SERVICE,
                note: 'Regularized: '.$request->reason,
            );

            $request->update([
                'status' => AttendanceRegularization::STATUS_APPROVED,
                'decided_by' => $approver->getKey(),
                'decided_at' => now(),
                'refusal_reason' => null,
            ]);

            return $day;
        });
    }

    public function refuse(AttendanceRegularization $request, User $approver, string $reason): AttendanceRegularization
    {
        if (! $request->isPending()) {
            throw new InvalidArgumentException("This correction is already {$request->status}.");
        }

        if (trim($reason) === '') {
            throw new InvalidArgumentException('A refusal needs a reason.');
        }

        $request->update([
            'status' => AttendanceRegularization::STATUS_REFUSED,
            'decided_by' => $approver->getKey(),
            'decided_at' => now(),
            'refusal_reason' => trim($reason),
        ]);

        return $request;
    }

    private function assertRegularizable(AttendanceRegularization $request): void
    {
        $date = $request->date;

        if ($request->requested_status === AttendanceDay::STATUS_NOT_MARKED) {
            throw new InvalidArgumentException(
                'A correction has to say what the day was. Asking for "not marked" is asking for nothing.'
            );
        }

        $existing = AttendanceDay::forDate($request->employee_id, $date);

        if ($existing?->isCoveredByLeave()) {
            throw new InvalidArgumentException(
                $date->format('d M Y').' is covered by approved leave. Withdraw the leave first if the employee actually worked.'
            );
        }

        if ($this->monthIsSettled($date->year, $date->month)) {
            throw new InvalidArgumentException(
                $date->format('F Y').' has been paid and signed off, so its attendance can no longer be changed. '
                .'Raise the correction against the current month instead.'
            );
        }
    }

    /**
     * Whether a payroll run covering this month is locked.
     *
     * Uses PayrollRun's own `locked()` scope, which keys on `status` — not on
     * `locked_at`, which stays populated on a run that was locked and later reopened.
     * Reading the timestamp would refuse corrections for a month somebody deliberately
     * reopened in order to correct.
     *
     * Guarded on `payroll`: a company without it has no runs, and every month is open.
     */
    private function monthIsSettled(int $year, int $month): bool
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
