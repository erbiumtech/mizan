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

            // CRM. Converting is its own permission: working a lead is not deciding
            // that it becomes a customer the ledger can bill.
            ['name' => 'LeadView', 'group' => 'Lead'],
            ['name' => 'LeadCreate', 'group' => 'Lead'],
            ['name' => 'LeadUpdate', 'group' => 'Lead'],
            ['name' => 'LeadDelete', 'group' => 'Lead'],
            ['name' => 'LeadConvert', 'group' => 'Lead'],

            ['name' => 'LeadSourceView', 'group' => 'LeadSource'],
            ['name' => 'LeadSourceCreate', 'group' => 'LeadSource'],
            ['name' => 'LeadSourceUpdate', 'group' => 'LeadSource'],
            ['name' => 'LeadSourceDelete', 'group' => 'LeadSource'],

            // Hiring. Applicants, applications, interviews and offers share ONE group:
            // splitting them would invite a role that can read CVs without being trusted
            // with the rest, and a CV is the most sensitive record here.
            ['name' => 'VacancyView', 'group' => 'Vacancy'],
            ['name' => 'VacancyCreate', 'group' => 'Vacancy'],
            ['name' => 'VacancyUpdate', 'group' => 'Vacancy'],
            ['name' => 'VacancyDelete', 'group' => 'Vacancy'],

            ['name' => 'ApplicantView', 'group' => 'Applicant'],
            ['name' => 'ApplicantCreate', 'group' => 'Applicant'],
            ['name' => 'ApplicantUpdate', 'group' => 'Applicant'],
            ['name' => 'ApplicantDelete', 'group' => 'Applicant'],

            // Its own permission: creating an employee, and a salary package with them,
            // is a bigger decision than moving somebody through a pipeline.
            ['name' => 'OfferHire', 'group' => 'Offer'],

            // Appraisals. ReviewPrivateNotes is separate because it is the one thing an
            // employee must never hold about themselves, whatever else they can see.
            ['name' => 'ReviewView', 'group' => 'Review'],
            ['name' => 'ReviewCreate', 'group' => 'Review'],
            ['name' => 'ReviewUpdate', 'group' => 'Review'],
            ['name' => 'ReviewDelete', 'group' => 'Review'],
            ['name' => 'ReviewPrivateNotes', 'group' => 'Review'],

            // Joining and leaving. Documents get their own group because a passport
            // scan is not the same sensitivity as an onboarding tick-box, and the
            // settlement gets one because it is money.
            ['name' => 'ChecklistView', 'group' => 'Checklist'],
            ['name' => 'ChecklistCreate', 'group' => 'Checklist'],
            ['name' => 'ChecklistUpdate', 'group' => 'Checklist'],
            ['name' => 'ChecklistDelete', 'group' => 'Checklist'],

            ['name' => 'EmployeeDocumentView', 'group' => 'EmployeeDocument'],
            ['name' => 'EmployeeDocumentCreate', 'group' => 'EmployeeDocument'],
            ['name' => 'EmployeeDocumentUpdate', 'group' => 'EmployeeDocument'],
            ['name' => 'EmployeeDocumentDelete', 'group' => 'EmployeeDocument'],

            ['name' => 'IssuedAssetView', 'group' => 'IssuedAsset'],
            ['name' => 'IssuedAssetCreate', 'group' => 'IssuedAsset'],
            ['name' => 'IssuedAssetUpdate', 'group' => 'IssuedAsset'],
            ['name' => 'IssuedAssetDelete', 'group' => 'IssuedAsset'],

            ['name' => 'SettlementView', 'group' => 'Settlement'],
            ['name' => 'SettlementCreate', 'group' => 'Settlement'],
            ['name' => 'SettlementUpdate', 'group' => 'Settlement'],
            ['name' => 'SettlementApprove', 'group' => 'Settlement'],
            ['name' => 'SettlementDelete', 'group' => 'Settlement'],

            // Timesheets. Approving is separate for the usual reason, and it matters
            // more here than most: approved time becomes an invoice line a client pays.
            ['name' => 'TimesheetView', 'group' => 'Timesheet'],
            ['name' => 'TimesheetCreate', 'group' => 'Timesheet'],
            ['name' => 'TimesheetApprove', 'group' => 'Timesheet'],

            // Attendance. Corrections are their own group because an employee holds
            // Create on them and nothing else here — asking about your own past is not
            // the same privilege as writing anybody's day.
            ['name' => 'AttendanceView', 'group' => 'Attendance'],
            ['name' => 'AttendanceCreate', 'group' => 'Attendance'],
            ['name' => 'AttendanceUpdate', 'group' => 'Attendance'],
            ['name' => 'AttendanceDelete', 'group' => 'Attendance'],

            ['name' => 'AttendanceRegularizationView', 'group' => 'AttendanceRegularization'],
            ['name' => 'AttendanceRegularizationCreate', 'group' => 'AttendanceRegularization'],
            ['name' => 'AttendanceRegularizationApprove', 'group' => 'AttendanceRegularization'],

            ['name' => 'WorkPatternView', 'group' => 'WorkPattern'],
            ['name' => 'WorkPatternCreate', 'group' => 'WorkPattern'],
            ['name' => 'WorkPatternUpdate', 'group' => 'WorkPattern'],
            ['name' => 'WorkPatternDelete', 'group' => 'WorkPattern'],

            // Leave. Approving is its own permission, exactly as it is for expense
            // claims: filing leave is not deciding it, and the Employee role gets
            // the first four and never the fifth.
            ['name' => 'LeaveRequestView', 'group' => 'LeaveRequest'],
            ['name' => 'LeaveRequestCreate', 'group' => 'LeaveRequest'],
            ['name' => 'LeaveRequestUpdate', 'group' => 'LeaveRequest'],
            ['name' => 'LeaveRequestDelete', 'group' => 'LeaveRequest'],
            ['name' => 'LeaveRequestApprove', 'group' => 'LeaveRequest'],

            // Leave types are reference data: HR edits the day counts, an employee
            // reads them so the form can say what they are asking for.
            ['name' => 'LeaveTypeView', 'group' => 'LeaveType'],
            ['name' => 'LeaveTypeCreate', 'group' => 'LeaveType'],
            ['name' => 'LeaveTypeUpdate', 'group' => 'LeaveType'],
            ['name' => 'LeaveTypeDelete', 'group' => 'LeaveType'],

            // Entitlements and their adjustment rows. LeaveEntitlementUpdate is what
            // grants an adjustment — the row that moves somebody's balance — so it is
            // deliberately not in the Employee role.
            ['name' => 'LeaveEntitlementView', 'group' => 'LeaveEntitlement'],
            ['name' => 'LeaveEntitlementCreate', 'group' => 'LeaveEntitlement'],
            ['name' => 'LeaveEntitlementUpdate', 'group' => 'LeaveEntitlement'],
            ['name' => 'LeaveEntitlementDelete', 'group' => 'LeaveEntitlement'],

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

            // The company holiday calendar. Core, like FiscalYear, because leave
            // and attendance both need "is this a working day" and neither owns
            // the answer — docs/hrms-plan.md §3.
            ['name' => 'HolidayCreate', 'group' => 'Holiday'],
            ['name' => 'HolidayView', 'group' => 'Holiday'],
            ['name' => 'HolidayUpdate', 'group' => 'Holiday'],
            ['name' => 'HolidayDelete', 'group' => 'Holiday'],

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
            // Planning, separate from ReportView: the budget says what the
            // company intends to do, which is not the same thing as being
            // allowed to read what it has already done.
            ['name' => 'BudgetView', 'group' => 'Budget'],
            ['name' => 'BudgetCreate', 'group' => 'Budget'],
            ['name' => 'BudgetUpdate', 'group' => 'Budget'],
            ['name' => 'BudgetDelete', 'group' => 'Budget'],

            // Borrowings and their repayment schedules. LoanRecord is the one
            // that writes to the ledger, so it is separated from Update the way
            // posting is separated from editing everywhere else here.
            ['name' => 'LoanView', 'group' => 'Loan'],
            ['name' => 'LoanCreate', 'group' => 'Loan'],
            ['name' => 'LoanUpdate', 'group' => 'Loan'],
            ['name' => 'LoanDelete', 'group' => 'Loan'],
            ['name' => 'LoanRecord', 'group' => 'Loan'],
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
