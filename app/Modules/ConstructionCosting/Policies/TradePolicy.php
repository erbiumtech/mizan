<?php

namespace App\Modules\ConstructionCosting\Policies;

use App\Modules\ConstructionCosting\Models\Trade;
use App\Modules\Core\Models\User;

/**
 * Who maintains the trade list — `docs/construction-management-plan.md` §7.1.
 *
 * **There is no delete**, and the reason is the rates hanging off it: `construction_labour_rates.trade_id` cascades,
 * so deleting a trade would take the history of what that trade cost with it — and "what did steel fixing cost us
 * last year" is the question §2.2 says a contractor prices the next tender with. `is_active` switches a trade off the
 * pickers and leaves the record alone.
 */
class TradePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('ConstructionLabourView');
    }

    public function view(User $user, Trade $trade): bool
    {
        return $user->can('ConstructionLabourView');
    }

    public function create(User $user): bool
    {
        return $user->can('ConstructionLabourUpdate');
    }

    public function update(User $user, Trade $trade): bool
    {
        return $user->can('ConstructionLabourUpdate');
    }
}
