<?php

namespace App\Modules\ConstructionCosting\Policies;

use App\Modules\ConstructionCosting\Models\PlantItem;
use App\Modules\Core\Models\User;

/**
 * Who maintains the fleet — `docs/construction-management-plan.md` §7.3.
 *
 * **The internal hire rate rides on `ConstructionPlantUpdate` rather than getting a name of its own**, which is the one
 * place this diverges from labour. `ConstructionLabourRateSet` is separate because a labour rate is a ladder — five
 * tiers, dated, varying by job, trade and person — and revising the company default reaches every job at once. A plant
 * rate is one number on one machine, set when it joins the fleet by the same person who registers it, in the same
 * screen. A separate permission there would be a fifth row in every role form for a decision nobody makes separately.
 *
 * **There is no delete.** A machine with cost against it is a row somebody will ask about, and `construction_plant_logs`
 * restricts the foreign key so a forced removal fails loudly rather than taking a month of plant cost with it.
 */
class PlantItemPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('ConstructionPlantView');
    }

    public function view(User $user, PlantItem $item): bool
    {
        return $user->can('ConstructionPlantView');
    }

    public function create(User $user): bool
    {
        return $user->can('ConstructionPlantUpdate');
    }

    public function update(User $user, PlantItem $item): bool
    {
        return $user->can('ConstructionPlantUpdate');
    }
}
