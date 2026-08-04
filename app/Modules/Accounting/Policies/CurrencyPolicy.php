<?php

namespace App\Modules\Accounting\Policies;

use App\Modules\Accounting\Models\Currency;
use App\Modules\Core\Models\User;

/**
 * Which currencies a company deals in, and at what rates, is a chart-of-accounts kind
 * of decision — so it takes the account permissions.
 */
class CurrencyPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermissionTo('AccountView');
    }

    public function view(User $user, Currency $currency): bool
    {
        return $user->hasPermissionTo('AccountView');
    }

    public function create(User $user): bool
    {
        return $user->hasPermissionTo('AccountCreate');
    }

    public function update(User $user, Currency $currency): bool
    {
        return $user->hasPermissionTo('AccountUpdate');
    }

    /**
     * Never the base currency, and never one that has been used. A rate is what a
     * posted line was converted at, and the currency it names has to stay nameable.
     */
    public function delete(User $user, Currency $currency): bool
    {
        return $user->hasPermissionTo('AccountUpdate')
            && ! $currency->isBase()
            && ! $currency->rates()->exists();
    }
}
