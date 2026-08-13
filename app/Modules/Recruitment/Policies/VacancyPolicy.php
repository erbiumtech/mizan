<?php

namespace App\Modules\Recruitment\Policies;

use App\Modules\Core\Models\User;
use App\Modules\Recruitment\Models\Vacancy;

class VacancyPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermissionTo('VacancyView');
    }

    public function view(User $user, Vacancy $vacancy): bool
    {
        return $user->hasPermissionTo('VacancyView');
    }

    public function create(User $user): bool
    {
        return $user->hasPermissionTo('VacancyCreate');
    }

    public function update(User $user, Vacancy $vacancy): bool
    {
        return $user->hasPermissionTo('VacancyUpdate');
    }

    /**
     * Never once anybody has applied.
     *
     * Deleting the vacancy cascades its applications away, and with them the record of
     * who applied for what — which is the memory this module exists to keep. Close it
     * instead.
     */
    public function delete(User $user, Vacancy $vacancy): bool
    {
        return $user->hasPermissionTo('VacancyDelete') && ! $vacancy->applications()->exists();
    }
}
