<?php

namespace App\Modules\Expenses\Services;

use App\Modules\Core\Models\User;
use App\Modules\Expenses\Models\ExpenseClaim;

/**
 * The roles that approve after the first approval, in order — docs/saas-launch-plan.md §14.3,
 * LeaveApprovalChain's pattern. Empty, the claim is approved in one step as before; the
 * manager-then-HR the plan names is `['Manager']` with HR holding the first, or `['HR']`
 * with the manager holding the first — the company says which.
 */
class ExpenseClaimApprovalChain
{
    public const SETTING_KEY = 'expenses.approval_chain';

    /** @return array<int, string> */
    public function roles(): array
    {
        return array_values(array_filter(array_map('strval', (array) setting(self::SETTING_KEY, [])), fn (string $role): bool => $role !== ''));
    }

    public function nextStep(ExpenseClaim $claim): int
    {
        return (int) $claim->approvals()->max('step') + 1;
    }

    public function roleForStep(int $step): ?string
    {
        return $step <= 1 ? null : ($this->roles()[$step - 2] ?? null);
    }

    public function isFinal(int $step): bool
    {
        return $step >= count($this->roles()) + 1;
    }

    public function awaitingRole(ExpenseClaim $claim): ?string
    {
        return $this->roleForStep($this->nextStep($claim));
    }

    public function userMayTakeNextStep(User $user, ExpenseClaim $claim): bool
    {
        $role = $this->awaitingRole($claim);

        return $role === null || $user->hasRole($role);
    }

    public function awaitingLabel(ExpenseClaim $claim): ?string
    {
        if (! $claim->isPending() || $this->roles() === []) {
            return null;
        }

        $taken = $claim->approvals()->count();
        $role = $this->awaitingRole($claim);

        if ($role === null) {
            return 'waiting for the first approval, then '.implode(', then ', $this->roles());
        }

        return "waiting for {$role} ({$taken} of ".(count($this->roles()) + 1).' approvals given)';
    }
}
