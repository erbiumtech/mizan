<?php

namespace App\Modules\Construction\Policies;

use App\Modules\Construction\Models\CostCode;
use App\Modules\Core\Models\User;

/**
 * Who may read and change the cost-code library.
 *
 * Company-wide reference data shared by every job (§2.2), so changing it is a separate decision from running
 * one — a renamed code changes six jobs' reports at once. Reading it is not: a material issue or a daywork
 * sheet has to name a code, so every employee holds the view permission.
 */
class CostCodePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('ConstructionCostCodeView');
    }

    public function view(User $user, CostCode $code): bool
    {
        return $user->can('ConstructionCostCodeView');
    }

    public function create(User $user): bool
    {
        return $user->can('ConstructionCostCodeCreate');
    }

    public function update(User $user, CostCode $code): bool
    {
        return $user->can('ConstructionCostCodeUpdate');
    }

    /**
     * A code with children is not deletable, and a code with cost against it will not be either once §3
     * exists.
     *
     * Deleting a heading would orphan everything under it — the cascade would take the children with it, and
     * with them whatever they were used for. Switching a code off (`is_active`) is the intended act, and §2.2
     * names it as the escape hatch for a one-off.
     */
    public function delete(User $user, CostCode $code): bool
    {
        return $user->can('ConstructionCostCodeDelete') && $code->children()->doesntExist();
    }
}
