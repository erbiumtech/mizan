<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Role;
use Spatie\Permission\Models\Permission;

class RoleSeeder extends Seeder
{
    public function run()
    {
        $adminRole = Role::firstOrCreate(['name' => 'Administrator']);
        $employeeRole = Role::firstOrCreate(['name' => 'Employee']);

        // Admin have all the permissions
        $adminRole->syncPermissions(Permission::all());
    }
}