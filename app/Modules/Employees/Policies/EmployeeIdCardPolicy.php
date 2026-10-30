<?php

namespace App\Modules\Employees\Policies;

use App\Modules\Core\Models\User;
use App\Modules\Employees\Models\EmployeeIdCard;

/**
 * A card is the company's statement that somebody works here —
 * docs/saas-launch-plan.md §14.10. Issuing and cancelling one are the same decision
 * and take the same right.
 */
class EmployeeIdCardPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('EmployeeView');
    }

    public function view(User $user, EmployeeIdCard $card): bool
    {
        return $user->can('EmployeeView') || $card->employee?->user_id === $user->getKey();
    }

    public function create(User $user): bool
    {
        return $user->can('EmployeeUpdate');
    }

    /** Cancelling goes through update: it is the only edit a card ever gets. */
    public function update(User $user, EmployeeIdCard $card): bool
    {
        return $user->can('EmployeeUpdate');
    }

    /**
     * Never deleted.
     *
     * A cancelled card is exactly the card somebody might present at a gate, and the
     * verification page has to be able to say what happened to it. Deleting the row
     * would make a cancelled card verify as "no such card", which reads as a forgery
     * rather than as a cancellation.
     */
    public function delete(User $user, EmployeeIdCard $card): bool
    {
        return false;
    }
}
