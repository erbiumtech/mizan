<?php

namespace App\Modules\Leave\Services;

use App\Modules\Core\Models\User;
use App\Modules\Leave\Models\LeaveRequest;
use App\Support\EmployeeAccess;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * The leave requests a person may decide — one answer for every place that asks.
 *
 * The `/v1` approvals endpoints (docs/desktop-mobile-plan.md A4) and the dashboard's "Waiting for you" card
 * both list from here, so neither can offer what the other would refuse. The boundary is the reporting line
 * the panel's resource draws (EmployeeAccess), and eligibility is asked explicitly as well as through the
 * policy: an Administrator passes every policy through Gate::before and would otherwise be offered their own
 * request, or a step waiting on a role they lack, which the service then refuses.
 */
class LeaveApprovalQueue
{
    public function __construct(private readonly LeaveApprovalChain $chain) {}

    /** Pending requests within the person's reporting line. */
    public function decidable(User $user): Builder
    {
        $query = LeaveRequest::query()->pending()->with(['employee', 'leaveType']);

        return app(EmployeeAccess::class)->scopeAccessibleEmployees($query, $user, 'employee_id');
    }

    /** @return Collection<int, LeaveRequest> */
    public function pendingFor(User $user): Collection
    {
        return $this->decidable($user)
            ->orderBy('from_date')
            ->get()
            ->filter(fn (LeaveRequest $leave): bool => $this->mayDecide($user, $leave))
            ->values();
    }

    /** One pending request the person may decide, or null. */
    public function findFor(User $user, int $id): ?LeaveRequest
    {
        $leave = $this->decidable($user)->whereKey($id)->first();

        return $leave !== null && $this->mayDecide($user, $leave) ? $leave : null;
    }

    /**
     * The policy, plus the two checks the service holds that an Administrator's policy bypass would skip:
     * their own leave when the company requires somebody else, and a step waiting on a role they lack.
     */
    public function mayDecide(User $user, LeaveRequest $leave): bool
    {
        if (! $user->can('decide', $leave)) {
            return false;
        }

        if ($leave->belongsToUser($user) && ! app(LeaveApprovalRule::class)->allowsSelfApproval()) {
            return false;
        }

        return $this->chain->userMayTakeNextStep($user, $leave);
    }
}
