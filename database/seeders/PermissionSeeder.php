<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;

class PermissionSeeder extends Seeder
{
    public function run()
    {
        $permissions = [
            ['name' => 'MPRView', 'group' => 'MPR'],
            ['name' => 'MPRCreate', 'group' => 'MPR'],
            ['name' => 'MPRUpdate', 'group' => 'MPR'],
            ['name' => 'MPRDelete', 'group' => 'MPR'],

            ['name' => 'UserView', 'group' => 'User'],
            ['name' => 'UserCreate', 'group' => 'User'],
            ['name' => 'UserUpdate', 'group' => 'User'],
            ['name' => 'UserDelete', 'group' => 'User'],

            ['name' => 'EmployeeView', 'group' => 'Employee'],
            ['name' => 'EmployeeUpdate', 'group' => 'Employee'],
            ['name' => 'EmployeeDelete', 'group' => 'Employee'],

            ['name' => 'EmployeeSettingView', 'group' => 'EmployeeSetting'],
            ['name' => 'EmployeeSettingCreate', 'group' => 'EmployeeSetting'],
            ['name' => 'EmployeeSettingUpdate', 'group' => 'EmployeeSetting'],
            ['name' => 'EmployeeSettingDelete', 'group' => 'EmployeeSetting'],

            ['name' => 'viewAnyRole', 'group' => 'Role'],
            ['name' => 'viewRole', 'group' => 'Role'],
            ['name' => 'createRole', 'group' => 'Role'],
            ['name' => 'updateRole', 'group' => 'Role'],
            ['name' => 'deleteRole', 'group' => 'Role'],

            ['name' => 'viewAnyPermission', 'group' => 'Permission'],
            ['name' => 'viewPermission', 'group' => 'Permission'],
            ['name' => 'createPermission', 'group' => 'Permission'],
            ['name' => 'updatePermission', 'group' => 'Permission'],
            ['name' => 'deletePermission', 'group' => 'Permission'],

            ['name' => 'PayslipView', 'group' => 'Payslip'],
            ['name' => 'PayslipCreate', 'group' => 'Payslip'],
            ['name' => 'PayslipUpdate', 'group' => 'Payslip'],
            ['name' => 'PayslipDelete', 'group' => 'Payslip'],

            ['name' => 'SalarySlabCreate', 'group' => 'SalarySlab'],
            ['name' => 'SalarySlabView', 'group' => 'SalarySlab'],
            ['name' => 'SalarySlabUpdate', 'group' => 'SalarySlab'],
            ['name' => 'SalarySlabDelete', 'group' => 'SalarySlab'],
        ];

        foreach ($permissions as $permissionData) {
            Permission::firstOrCreate($permissionData, ['guard_name' => 'web']);
        }
    }
}
