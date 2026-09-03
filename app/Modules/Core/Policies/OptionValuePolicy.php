<?php

namespace App\Modules\Core\Policies;

use App\Modules\Core\Models\OptionValue;
use App\Modules\Core\Models\User;

/**
 * Administrators only, like Company Settings — which is what this is, spread over
 * several dropdowns. No permission of its own: what these rows change is what every
 * other screen offers, so it belongs with the person who already decides that.
 */
class OptionValuePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isAdministrator();
    }

    public function view(User $user, OptionValue $option): bool
    {
        return $user->isAdministrator();
    }

    public function create(User $user): bool
    {
        return $user->isAdministrator();
    }

    public function update(User $user, OptionValue $option): bool
    {
        return $user->isAdministrator();
    }

    public function delete(User $user, OptionValue $option): bool
    {
        return $user->isAdministrator();
    }
}
