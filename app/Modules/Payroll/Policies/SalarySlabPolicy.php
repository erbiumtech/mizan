<?php

namespace App\Modules\Payroll\Policies;

use App\Modules\Payroll\Models\SalarySlab;
use App\Modules\Core\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;

class SalarySlabPolicy
{
    use HandlesAuthorization;

    /**
     * Determine whether the user can view any models.
     */
    public function viewAny(User $user)
    {
        return $user->hasPermissionTo('SalarySlabView');
    }

    /**
     * Determine whether the user can view the model.
     */
    public function view(User $user, SalarySlab $salarySlab)
    {
        return $user->hasPermissionTo('SalarySlabView');
    }

    /**
     * Determine whether the user can create models.
     */
    public function create(User $user)
    {
        return $user->hasPermissionTo('SalarySlabCreate');
    }

    /**
     * Determine whether the user can update the model.
     */
    public function update(User $user, SalarySlab $salarySlab)
    {
        return $user->hasPermissionTo('SalarySlabUpdate');
    }

    /**
     * Determine whether the user can delete the model.
     */
    public function delete(User $user, SalarySlab $salarySlab)
    {
        return $user->hasPermissionTo('SalarySlabDelete');
    }

    /**
     * Determine whether the user can restore the model.
     */
    public function restore(User $user, SalarySlab $salarySlab)
    {
        return $user->hasPermissionTo('SalarySlabDelete');
    }

    /**
     * Determine whether the user can permanently delete the model.
     */
    public function forceDelete(User $user, SalarySlab $salarySlab)
    {
        return $user->hasPermissionTo('SalarySlabDelete');
    }
}
