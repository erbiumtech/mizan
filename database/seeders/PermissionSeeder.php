<?php

namespace Database\Seeders;

use App\Support\PermissionCache;
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

            // A person's own books. These grant access to your *own* records
            // only — which rows you can reach is decided by the owner scope on
            // the models, not by holding a permission. PersonalFinanceViewAny is
            // the exception: it is the read-only cross-user view, and no seeded
            // role but Administrator holds it.
            ['name' => 'PersonalFinanceView', 'group' => 'PersonalFinance'],
            ['name' => 'PersonalFinanceCreate', 'group' => 'PersonalFinance'],
            ['name' => 'PersonalFinanceUpdate', 'group' => 'PersonalFinance'],
            ['name' => 'PersonalFinanceDelete', 'group' => 'PersonalFinance'],
            ['name' => 'PersonalFinanceViewAny', 'group' => 'PersonalFinance'],

            ['name' => 'UserView', 'group' => 'User'],
            ['name' => 'UserCreate', 'group' => 'User'],
            ['name' => 'UserUpdate', 'group' => 'User'],
            ['name' => 'UserDelete', 'group' => 'User'],
            // Sign in as another user. Administrator gets it with everything else;
            // no other seeded role lists it.
            ['name' => 'UserImpersonate', 'group' => 'User'],

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

            ['name' => 'BillingRunView', 'group' => 'BillingRun'],
            ['name' => 'BillingRunCreate', 'group' => 'BillingRun'],
            ['name' => 'BillingRunUpdate', 'group' => 'BillingRun'],
            ['name' => 'BillingRunDelete', 'group' => 'BillingRun'],

            ['name' => 'ExpenseClaimView', 'group' => 'ExpenseClaim'],
            ['name' => 'ExpenseClaimCreate', 'group' => 'ExpenseClaim'],
            ['name' => 'ExpenseClaimUpdate', 'group' => 'ExpenseClaim'],
            ['name' => 'ExpenseClaimDelete', 'group' => 'ExpenseClaim'],
            ['name' => 'ExpenseClaimApprove', 'group' => 'ExpenseClaim'],

            ['name' => 'AdvanceView', 'group' => 'Advance'],
            ['name' => 'AdvanceCreate', 'group' => 'Advance'],
            ['name' => 'AdvanceUpdate', 'group' => 'Advance'],
            ['name' => 'AdvanceDelete', 'group' => 'Advance'],

            ['name' => 'PayrollRunView', 'group' => 'Payslip'],
            ['name' => 'PayrollRunLock', 'group' => 'Payslip'],

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
            ['name' => 'EmployeeChangeApprove', 'group' => 'Employee'],

            ['name' => 'AccountView', 'group' => 'Account'],
            ['name' => 'AccountCreate', 'group' => 'Account'],
            ['name' => 'AccountUpdate', 'group' => 'Account'],
            ['name' => 'AccountDelete', 'group' => 'Account'],
            ['name' => 'ReportView', 'group' => 'Report'],
            ['name' => 'BankView', 'group' => 'Bank'],
            ['name' => 'BankCreate', 'group' => 'Bank'],
            ['name' => 'BankUpdate', 'group' => 'Bank'],
            ['name' => 'BankDelete', 'group' => 'Bank'],
            ['name' => 'TransactionTypeView', 'group' => 'TransactionType'],
            ['name' => 'TransactionTypeCreate', 'group' => 'TransactionType'],
            ['name' => 'TransactionTypeUpdate', 'group' => 'TransactionType'],
            ['name' => 'TransactionTypeDelete', 'group' => 'TransactionType'],
            ['name' => 'CompanyBankAccountView', 'group' => 'CompanyBankAccount'],
            ['name' => 'CompanyBankAccountCreate', 'group' => 'CompanyBankAccount'],
            ['name' => 'CompanyBankAccountUpdate', 'group' => 'CompanyBankAccount'],
            ['name' => 'CompanyBankAccountDelete', 'group' => 'CompanyBankAccount'],
            ['name' => 'BeneficiaryView', 'group' => 'Beneficiary'],
            ['name' => 'BeneficiaryCreate', 'group' => 'Beneficiary'],
            ['name' => 'BeneficiaryUpdate', 'group' => 'Beneficiary'],
            ['name' => 'BeneficiaryDelete', 'group' => 'Beneficiary'],
            ['name' => 'PaymentView', 'group' => 'Payment'],
            ['name' => 'PaymentCreate', 'group' => 'Payment'],
            ['name' => 'PaymentUpdate', 'group' => 'Payment'],
            ['name' => 'PaymentDelete', 'group' => 'Payment'],
            ['name' => 'RegisterPost', 'group' => 'Register'],
            ['name' => 'GnuCashImport', 'group' => 'Import'],
            ['name' => 'PettyCashView', 'group' => 'PettyCash'],
            ['name' => 'PettyCashCreate', 'group' => 'PettyCash'],
            ['name' => 'PettyCashReplenish', 'group' => 'PettyCash'],
            ['name' => 'ProductView', 'group' => 'Inventory'],
            ['name' => 'ProductCreate', 'group' => 'Inventory'],
            ['name' => 'ProductUpdate', 'group' => 'Inventory'],
            ['name' => 'ProductDelete', 'group' => 'Inventory'],
            ['name' => 'StockMove', 'group' => 'Inventory'],
            ['name' => 'StockAdjust', 'group' => 'Inventory'],
            ['name' => 'ProjectView', 'group' => 'Project'],
            ['name' => 'ProjectCreate', 'group' => 'Project'],
            ['name' => 'ProjectUpdate', 'group' => 'Project'],
            ['name' => 'ProjectDelete', 'group' => 'Project'],
            ['name' => 'ProjectHealthCheck', 'group' => 'Project'],

            ['name' => 'ContactView', 'group' => 'Invoicing'],
            ['name' => 'ContactCreate', 'group' => 'Invoicing'],
            ['name' => 'ContactUpdate', 'group' => 'Invoicing'],
            ['name' => 'ContactDelete', 'group' => 'Invoicing'],
            ['name' => 'InvoiceView', 'group' => 'Invoicing'],
            ['name' => 'InvoiceCreate', 'group' => 'Invoicing'],
            ['name' => 'InvoiceUpdate', 'group' => 'Invoicing'],
            ['name' => 'InvoiceIssue', 'group' => 'Invoicing'],
            ['name' => 'InvoicePay', 'group' => 'Invoicing'],
            ['name' => 'InvoiceVoid', 'group' => 'Invoicing'],

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

        // Once, at the end, and across every company. Spatie invalidates its own cache on
        // write, but only the copy belonging to the context doing the writing — and a seeder
        // has no company, so each company kept serving the list it had cached before this ran.
        // A permission added here and not visible there is not a stale menu: policies call
        // hasPermissionTo(), which throws for a name it cannot find, and the panel 500s.
        PermissionCache::flushEverywhere();
    }
}
