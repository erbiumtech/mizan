<?php

namespace Database\Seeders;

use App\Modules\Core\Models\Company;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

class RoleSeeder extends Seeder
{
    /**
     * Seed the roles for the current company, or for every company when there is no
     * current one.
     *
     * Roles are per-company (spatie teams), and a null team is not a company. Run
     * outside a tenant — plain `db:seed --class=RoleSeeder`, which is what somebody
     * naturally types — this used to create a full set of roles belonging to no
     * company, holding every permission, reachable by nobody, while leaving each real
     * company's roles exactly as they were. It reported success, and the roles it was
     * meant to update were untouched.
     *
     * Seeding every company is what running it without naming one means. Callers that
     * do set a team — the provisioner, `tenants:artisan` — are unaffected.
     */
    public function run()
    {
        $registrar = app(PermissionRegistrar::class);
        $teamId = $registrar->getPermissionsTeamId();

        if ($teamId !== null) {
            $this->seedTeam($teamId);

            return;
        }

        foreach (Company::query()->orderBy('id')->pluck('id') as $companyId) {
            $registrar->setPermissionsTeamId($companyId);
            $this->seedTeam($companyId);
        }

        $registrar->setPermissionsTeamId(null);
        $registrar->forgetCachedPermissions();
    }

    private function seedTeam(int $teamId): void
    {
        $adminRole = Role::firstOrCreate(['name' => 'Administrator', 'company_id' => $teamId]);
        $employeeRole = Role::firstOrCreate(['name' => 'Employee', 'company_id' => $teamId]);

        // Admin have all the permissions
        $adminRole->syncPermissions(Permission::all());

        // Employee: own payslips, own advances, own salary settings (all
        // read-only — the resource scopes rows to own + downline), and
        // comments on them.
        // Projects are a company-wide shared reference: every employee sees all
        // of them and may add or correct environment data. Deletion and
        // on-demand health checks stay privileged.
        $employeeRole->syncPermissions([
            'PayslipView',
            'AdvanceView',
            'EmployeeSettingView',
            // Their own claims: submit one and see what happened to it. Approving is
            // deliberately absent — an approver is somebody else.
            'ExpenseClaimView',
            'ExpenseClaimCreate',
            'ExpenseClaimUpdate',
            'CommentCreate',
            'CommentView',
            'ProjectView',
            'ProjectCreate',
            'ProjectUpdate',
            // Their own books. Everybody gets these, not just finance staff —
            // tracking your own money is not a privilege of the accounts
            // department. Not PersonalFinanceViewAny: that is the cross-user
            // read, and it belongs to Administrator alone.
            'PersonalFinanceView',
            'PersonalFinanceCreate',
            'PersonalFinanceUpdate',
            'PersonalFinanceDelete',
        ]);

        // Accounting roles with segregation of duties:
        // Accountant records entries but cannot approve or post.
        $accountantRole = Role::firstOrCreate(['name' => 'Accountant', 'company_id' => $teamId]);
        $accountantRole->syncPermissions([
            'AccountView', 'AccountCreate', 'AccountUpdate',
            'ReportView',
            // The accountant draws the budget up. Deleting one is the CEO's,
            // below, for the same reason account deletion is: a plan that has
            // been reported against is evidence of what was agreed.
            'BudgetView', 'BudgetCreate', 'BudgetUpdate',
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
            // The accountant runs payroll and signs the month off.
            'PayrollRunView', 'PayrollRunLock',
            'AdvanceView', 'AdvanceCreate', 'AdvanceUpdate',
            'ExpenseClaimView', 'ExpenseClaimCreate', 'ExpenseClaimUpdate', 'ExpenseClaimApprove',
            'BillingRunView', 'BillingRunCreate', 'BillingRunUpdate',
            // No ProjectHealthCheck: firing an on-demand check makes the server
            // issue an outbound request, which isn't finance work.
            'ProjectView', 'ProjectCreate', 'ProjectUpdate',
            'ContactView', 'ContactCreate', 'ContactUpdate',
            'InvoiceView', 'InvoiceCreate', 'InvoiceUpdate', 'InvoiceIssue', 'InvoicePay',
            'JournalEntryView', 'JournalEntryCreate', 'JournalEntryUpdate', 'JournalEntrySubmit',
            'FixedAssetView', 'FixedAssetCreate', 'FixedAssetUpdate',
            'BankStatementView', 'BankStatementCreate', 'BankStatementUpdate', 'BankStatementImport', 'BankStatementMatch',
            'CommentView', 'CommentCreate', 'CommentResolve',
            'ActivityLogView',
            // Their own books, same as everybody else. Manager and CEO are built
            // from this list, so they inherit it.
            'PersonalFinanceView',
            'PersonalFinanceCreate',
            'PersonalFinanceUpdate',
            'PersonalFinanceDelete',
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
            'ProjectHealthCheck',
        ]);
        $managerRole = Role::firstOrCreate(['name' => 'Manager', 'company_id' => $teamId]);
        $managerRole->syncPermissions($managerPermissions);

        // CEO: same approval powers as Manager + account deletion.
        $ceoRole = Role::firstOrCreate(['name' => 'CEO', 'company_id' => $teamId]);
        // Deliberately no JournalEntryDelete: deleting a ledger transaction —
        // including from the account register — is Administrator-only. The CEO
        // corrects the books by reversing, which leaves both rows on the ledger.
        $ceoRole->syncPermissions(array_merge($managerPermissions, ['AccountDelete', 'BudgetDelete', 'FixedAssetDelete', 'BankStatementDelete', 'BankDelete', 'TransactionTypeDelete', 'CompanyBankAccountDelete', 'BeneficiaryDelete', 'ProductDelete', 'ContactDelete', 'ProjectDelete']));
    }
}
