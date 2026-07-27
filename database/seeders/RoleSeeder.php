<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

class RoleSeeder extends Seeder
{
    public function run()
    {
        // Roles are per-company (spatie teams). Scope creation to the current
        // team so each company gets its own set instead of reusing another's.
        $teamId = app(PermissionRegistrar::class)->getPermissionsTeamId();

        $adminRole = Role::firstOrCreate(['name' => 'Administrator', 'company_id' => $teamId]);
        $employeeRole = Role::firstOrCreate(['name' => 'Employee', 'company_id' => $teamId]);

        // Admin have all the permissions
        $adminRole->syncPermissions(Permission::all());

        // Employee: own payslips, own salary settings (read-only — the resource
        // scopes rows to own + downline), and comments on them.
        $employeeRole->syncPermissions([
            'PayslipView',
            'EmployeeSettingView',
            'CommentCreate',
            'CommentView',
        ]);

        // Accounting roles with segregation of duties:
        // Accountant records entries but cannot approve or post.
        $accountantRole = Role::firstOrCreate(['name' => 'Accountant', 'company_id' => $teamId]);
        $accountantRole->syncPermissions([
            'AccountView', 'AccountCreate', 'AccountUpdate',
            'ReportView',
            'BankView', 'BankCreate', 'BankUpdate',
            'TransactionTypeView', 'TransactionTypeCreate', 'TransactionTypeUpdate',
            'CompanyBankAccountView', 'CompanyBankAccountCreate', 'CompanyBankAccountUpdate',
            'BeneficiaryView', 'BeneficiaryCreate', 'BeneficiaryUpdate',
            'PaymentView', 'PaymentCreate', 'PaymentUpdate', 'PaymentDelete',
            'RegisterPost',
            'GnuCashImport',
            'PettyCashView', 'PettyCashCreate',
            'ProductView', 'ProductCreate', 'ProductUpdate', 'StockMove',
            'PayslipView', 'PayslipCreate', 'PayslipUpdate',
            'ContactView', 'ContactCreate', 'ContactUpdate',
            'InvoiceView', 'InvoiceCreate', 'InvoiceUpdate', 'InvoiceIssue', 'InvoicePay',
            'JournalEntryView', 'JournalEntryCreate', 'JournalEntryUpdate', 'JournalEntrySubmit',
            'FixedAssetView', 'FixedAssetCreate', 'FixedAssetUpdate',
            'BankStatementView', 'BankStatementCreate', 'BankStatementUpdate', 'BankStatementImport', 'BankStatementMatch',
            'CommentView', 'CommentCreate', 'CommentResolve',
            'ActivityLogView',
        ]);

        // Manager: everything the Accountant has + approve/reject/post/reverse.
        $managerPermissions = array_merge($accountantRole->permissions->pluck('name')->all(), [
            'JournalEntryApprove', 'JournalEntryReject', 'JournalEntryPost', 'JournalEntryReverse',
            'PettyCashReplenish',
            'StockAdjust',
            'InvoiceVoid',
            'EmployeeChangeApprove',
            'FixedAssetDepreciate', 'FixedAssetDispose',
            'BankStatementComplete',
        ]);
        $managerRole = Role::firstOrCreate(['name' => 'Manager', 'company_id' => $teamId]);
        $managerRole->syncPermissions($managerPermissions);

        // CEO: same approval powers as Manager + account deletion.
        $ceoRole = Role::firstOrCreate(['name' => 'CEO', 'company_id' => $teamId]);
        $ceoRole->syncPermissions(array_merge($managerPermissions, ['AccountDelete', 'FixedAssetDelete', 'BankStatementDelete', 'BankDelete', 'TransactionTypeDelete', 'CompanyBankAccountDelete', 'BeneficiaryDelete', 'ProductDelete', 'ContactDelete']));
    }
}
