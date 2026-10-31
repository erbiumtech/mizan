<?php

namespace App\Modules\Expenses\Services;

use App\Modules\Core\Models\User;
use App\Modules\Expenses\Models\ExpenseClaim;
use App\Support\EmployeeAccess;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * The expense claims a person may decide — one answer for the `/v1` approvals endpoints
 * (docs/desktop-mobile-plan.md A4) and the dashboard's "Waiting for you" card alike.
 *
 * The reporting line the panel's resource draws, the policy, and the two checks an Administrator's policy
 * bypass would skip: their own claim, and a step in the chain waiting on a role they lack.
 */
class ExpenseClaimApprovalQueue
{
    public function __construct(private readonly ExpenseClaimApprovalChain $chain) {}

    /** Pending claims within the person's reporting line. */
    public function decidable(User $user): Builder
    {
        $query = ExpenseClaim::query()->pending()->with(['employee']);

        return app(EmployeeAccess::class)->scopeAccessibleEmployees($query, $user, 'employee_id');
    }

    /** @return Collection<int, ExpenseClaim> */
    public function pendingFor(User $user): Collection
    {
        return $this->decidable($user)
            ->orderBy('claimed_on')
            ->get()
            ->filter(fn (ExpenseClaim $claim): bool => $this->mayDecide($user, $claim))
            ->values();
    }

    /** One pending claim the person may decide, or null. */
    public function findFor(User $user, int $id): ?ExpenseClaim
    {
        $claim = $this->decidable($user)->whereKey($id)->first();

        return $claim !== null && $this->mayDecide($user, $claim) ? $claim : null;
    }

    public function mayDecide(User $user, ExpenseClaim $claim): bool
    {
        return $user->can('decide', $claim)
            && (int) $claim->submitted_by !== (int) $user->getKey()
            && $this->chain->userMayTakeNextStep($user, $claim);
    }
}
