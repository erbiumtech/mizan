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

            ['name' => 'FiscalYearCreate', 'group' => 'FiscalYear'],
            ['name' => 'FiscalYearView', 'group' => 'FiscalYear'],
            ['name' => 'FiscalYearUpdate', 'group' => 'FiscalYear'],
            ['name' => 'FiscalYearDelete', 'group' => 'FiscalYear'],

            ['name' => 'AnnualTaxCreate', 'group' => 'AnnualTax'],
            ['name' => 'AnnualTaxView', 'group' => 'AnnualTax'],
            ['name' => 'AnnualTaxUpdate', 'group' => 'AnnualTax'],
            ['name' => 'AnnualTaxDelete', 'group' => 'AnnualTax'],

            ['name' => 'ActivityLogView', 'group' => 'ActivityLog'],

            ['name' => 'AccountView', 'group' => 'Account'],
            ['name' => 'AccountCreate', 'group' => 'Account'],
            ['name' => 'AccountUpdate', 'group' => 'Account'],
            ['name' => 'AccountDelete', 'group' => 'Account'],
            ['name' => 'ReportView', 'group' => 'Report'],

            ['name' => 'JournalEntryView', 'group' => 'JournalEntry'],
            ['name' => 'JournalEntryCreate', 'group' => 'JournalEntry'],
            ['name' => 'JournalEntryUpdate', 'group' => 'JournalEntry'],
            ['name' => 'JournalEntryDelete', 'group' => 'JournalEntry'],
            ['name' => 'JournalEntrySubmit', 'group' => 'JournalEntry'],
            ['name' => 'JournalEntryApprove', 'group' => 'JournalEntry'],
            ['name' => 'JournalEntryReject', 'group' => 'JournalEntry'],
            ['name' => 'JournalEntryPost', 'group' => 'JournalEntry'],
            ['name' => 'JournalEntryReverse', 'group' => 'JournalEntry'],

            ['name' => 'FixedAssetView', 'group' => 'FixedAsset'],
            ['name' => 'FixedAssetCreate', 'group' => 'FixedAsset'],
            ['name' => 'FixedAssetUpdate', 'group' => 'FixedAsset'],
            ['name' => 'FixedAssetDelete', 'group' => 'FixedAsset'],
            ['name' => 'FixedAssetDepreciate', 'group' => 'FixedAsset'],
            ['name' => 'FixedAssetDispose', 'group' => 'FixedAsset'],

            ['name' => 'BankStatementView', 'group' => 'BankStatement'],
            ['name' => 'BankStatementCreate', 'group' => 'BankStatement'],
            ['name' => 'BankStatementUpdate', 'group' => 'BankStatement'],
            ['name' => 'BankStatementDelete', 'group' => 'BankStatement'],
            ['name' => 'BankStatementImport', 'group' => 'BankStatement'],
            ['name' => 'BankStatementMatch', 'group' => 'BankStatement'],
            ['name' => 'BankStatementComplete', 'group' => 'BankStatement'],

            ['name' => 'CommentCreate', 'group' => 'Comment'],
            ['name' => 'CommentView', 'group' => 'Comment'],
            ['name' => 'CommentResolve', 'group' => 'Comment'],
        ];

        foreach ($permissions as $permissionData) {
            Permission::firstOrCreate($permissionData, ['guard_name' => 'web']);
        }
    }
}
