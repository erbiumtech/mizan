<?php

namespace App\Modules\Inventory\Policies;

use App\Modules\Core\Models\User;
use App\Modules\Inventory\Models\StockLocation;

/**
 * Who maintains the list of places stock can be — `docs/construction-management-plan.md` §6.
 *
 * **Rides on the product permissions rather than getting its own pair**, and that is a decision worth stating: a
 * location is part of the same answer as a product — *what* stock and *where* it is — and it is maintained by the same
 * person in the same sitting. Two more permission names would be two more rows in every role form for a decision
 * nobody makes separately, which is the Leave precedent §18.2 settles for small tables.
 *
 * `StockAdjust` is deliberately not the grant here: adjusting stock moves quantities, and creating a location does not
 * move anything.
 *
 * **There is no delete.** A location with movements against it is the location that stock is at, and removing it would
 * leave those movements at no location — "correct in total and wrong at every location", which is the failure §6 is
 * about. `is_active` takes it out of the pickers.
 */
class StockLocationPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('ProductView');
    }

    public function view(User $user, StockLocation $location): bool
    {
        return $user->can('ProductView');
    }

    public function create(User $user): bool
    {
        return $user->can('ProductUpdate');
    }

    public function update(User $user, StockLocation $location): bool
    {
        return $user->can('ProductUpdate');
    }
}
