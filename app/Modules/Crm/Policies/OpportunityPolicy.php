<?php

namespace App\Modules\Crm\Policies;

use App\Modules\Core\Models\User;
use App\Modules\Crm\Models\Opportunity;

class OpportunityPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermissionTo('OpportunityView');
    }

    public function view(User $user, Opportunity $opportunity): bool
    {
        return $user->hasPermissionTo('OpportunityView');
    }

    public function create(User $user): bool
    {
        return $user->hasPermissionTo('OpportunityCreate');
    }

    public function update(User $user, Opportunity $opportunity): bool
    {
        return $user->hasPermissionTo('OpportunityUpdate');
    }

    /**
     * Not once closed.
     *
     * A won or lost deal is what win/loss counts, and deleting it changes a rate that has
     * already been reported. Reopening is the way back.
     */
    public function delete(User $user, Opportunity $opportunity): bool
    {
        return $user->hasPermissionTo('OpportunityDelete') && $opportunity->isOpen();
    }

    /** Moving a deal is the ordinary act of working it, so it rides on Update. */
    public function move(User $user, Opportunity $opportunity): bool
    {
        return $user->hasPermissionTo('OpportunityUpdate') && $opportunity->isOpen();
    }

    /**
     * Closing is its own permission.
     *
     * Winning a deal is what a target is measured on and what a commission is calculated
     * from, so it is a bigger act than dragging a card — even though neither posts anything.
     */
    public function close(User $user, Opportunity $opportunity): bool
    {
        return $user->hasPermissionTo('OpportunityClose') && $opportunity->isOpen();
    }
}
