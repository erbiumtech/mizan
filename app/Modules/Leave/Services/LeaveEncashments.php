<?php

namespace App\Modules\Leave\Services;

use App\Modules\Core\Models\User;
use App\Modules\Leave\Models\LeaveAdjustment;
use App\Modules\Leave\Models\LeaveEncashment;
use App\Modules\Leave\Models\LeaveEntitlement;
use App\Support\Contracts\PayrollInputs;
use App\Support\TenantTransaction;
use InvalidArgumentException;

/** Approve or refuse an encashment — docs/saas-launch-plan.md §14.4. */
class LeaveEncashments
{
    public function __construct(private readonly LeaveBalance $balance, private readonly PayrollInputs $payroll) {}

    public function approve(LeaveEncashment $encashment, User $by): LeaveEncashment
    {
        if (! $encashment->isPending()) {
            throw new InvalidArgumentException("This encashment is already {$encashment->status}.");
        }

        if (! $encashment->leaveType->is_encashable) {
            throw new InvalidArgumentException("{$encashment->leaveType->label} is not encashable.");
        }

        $remaining = $this->balance->remaining($encashment->employee, $encashment->leaveType);

        if ($remaining === null || $remaining < (float) $encashment->days) {
            throw new InvalidArgumentException(sprintf('Only %s day(s) of %s are left to encash.', number_format((float) ($remaining ?? 0), 1), $encashment->leaveType->label));
        }

        $entitlement = LeaveEntitlement::query()
            ->where('employee_id', $encashment->employee_id)
            ->where('leave_type_id', $encashment->leave_type_id)
            ->whereDate('leave_year_start', '<=', now()->toDateString())
            ->whereDate('leave_year_end', '>=', now()->toDateString())
            ->first();

        if ($entitlement === null) {
            throw new InvalidArgumentException('No leave year is open for this type; open the year first.');
        }

        TenantTransaction::run(function () use ($encashment, $by, $entitlement): void {
            // The debit: an adjustment row, so the balance says why it fell.
            $adjustment = LeaveAdjustment::create([
                'leave_entitlement_id' => $entitlement->getKey(),
                'days' => -abs((float) $encashment->days),
                'reason' => 'Encashed'.($encashment->reason ? ': '.$encashment->reason : ''),
                'made_by' => $by->getKey(),
                'made_at' => now(),
            ]);

            $encashment->update([
                'status' => LeaveEncashment::STATUS_APPROVED,
                'decided_by' => $by->getKey(),
                'decided_at' => now(),
                'leave_adjustment_id' => $adjustment->getKey(),
            ]);

            // The money: a payslip input for the month named, through the contract Payroll binds.
            if ($encashment->month && $encashment->fiscal_year_id && (float) $encashment->amount > 0) {
                $this->payroll->add(
                    (int) $encashment->employee_id,
                    $encashment->month,
                    (int) $encashment->fiscal_year_id,
                    'bonus',
                    (float) $encashment->amount,
                    "Leave encashment: {$encashment->days} day(s) of {$encashment->leaveType->label}",
                );
            }
        });

        return $encashment->refresh();
    }

    public function refuse(LeaveEncashment $encashment, User $by, string $reason): LeaveEncashment
    {
        if (! $encashment->isPending()) {
            throw new InvalidArgumentException("This encashment is already {$encashment->status}.");
        }

        if (trim($reason) === '') {
            throw new InvalidArgumentException('A refusal needs a reason.');
        }

        $encashment->update(['status' => LeaveEncashment::STATUS_REFUSED, 'decided_by' => $by->getKey(), 'decided_at' => now(), 'refusal_reason' => trim($reason)]);

        return $encashment->refresh();
    }
}
