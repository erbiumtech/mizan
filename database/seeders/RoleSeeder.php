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

        // Employee: own payslips + comments on them
        $employeeRole->syncPermissions([
            'PayslipView',
            'CommentCreate',
            'CommentView',
        ]);

        // Accounting roles with segregation of duties:
        // Accountant records entries but cannot approve or post.
        $accountantRole = Role::firstOrCreate(['name' => 'Accountant']);
        $accountantRole->syncPermissions([
            'AccountView', 'AccountCreate', 'AccountUpdate',
            'JournalEntryView', 'JournalEntryCreate', 'JournalEntryUpdate', 'JournalEntrySubmit',
            'CommentView', 'CommentCreate', 'CommentResolve',
            'ActivityLogView',
        ]);

        // Manager: everything the Accountant has + approve/reject/post/reverse.
        $managerPermissions = array_merge($accountantRole->permissions->pluck('name')->all(), [
            'JournalEntryApprove', 'JournalEntryReject', 'JournalEntryPost', 'JournalEntryReverse',
        ]);
        $managerRole = Role::firstOrCreate(['name' => 'Manager']);
        $managerRole->syncPermissions($managerPermissions);

        // CEO: same approval powers as Manager + account deletion.
        $ceoRole = Role::firstOrCreate(['name' => 'CEO']);
        $ceoRole->syncPermissions(array_merge($managerPermissions, ['AccountDelete']));
    }
}