<?php

namespace App\Modules\Payroll\Policies;

use App\Modules\Core\Models\User;
use App\Modules\Payroll\Models\SalaryStructure;

class SalaryStructurePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermissionTo('SalaryStructureView');
    }

    public function view(User $user, SalaryStructure $structure): bool
    {
        return $user->hasPermissionTo('SalaryStructureView');
    }

    public function create(User $user): bool
    {
        return $user->hasPermissionTo('SalaryStructureUpdate');
    }

    public function update(User $user, SalaryStructure $structure): bool
    {
        return $user->hasPermissionTo('SalaryStructureUpdate');
    }

    public function delete(User $user, SalaryStructure $structure): bool
    {
        return $user->hasPermissionTo('SalaryStructureUpdate');
    }
}
