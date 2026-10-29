<?php

namespace App\Modules\Payroll\Policies;

use App\Modules\Core\Models\User;
use App\Modules\Payroll\Models\SalaryStructureLine;

/** A line is edited with its structure. */
class SalaryStructureLinePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermissionTo('SalaryStructureView');
    }

    public function view(User $user, SalaryStructureLine $line): bool
    {
        return $user->hasPermissionTo('SalaryStructureView');
    }

    public function create(User $user): bool
    {
        return $user->hasPermissionTo('SalaryStructureUpdate');
    }

    public function update(User $user, SalaryStructureLine $line): bool
    {
        return $user->hasPermissionTo('SalaryStructureUpdate');
    }

    public function delete(User $user, SalaryStructureLine $line): bool
    {
        return $user->hasPermissionTo('SalaryStructureUpdate');
    }
}
