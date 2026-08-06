<?php

namespace App\Modules\PersonalFinance\Concerns;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

/**
 * Restricts every query on a personal-finance model to the signed-in user's own
 * rows. See BelongsToOwner for why this is a scope rather than a policy.
 *
 * A named class rather than a closure so BelongsToOwner::ownedByAnyone() can
 * remove it by class name, and its own file so PSR-4 can autoload it.
 *
 * With nobody signed in the scope does nothing: that is a seeder, a console
 * command or a queued job, not a person reading somebody else's books. Anything
 * running in a request has a user.
 */
class OwnerScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        $userId = auth()->id();

        if ($userId === null) {
            return;
        }

        $builder->where($model->qualifyColumn('user_id'), $userId);
    }
}
