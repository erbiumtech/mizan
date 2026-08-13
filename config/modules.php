<?php

/**
 * The module registry: what modules exist, what they depend on, and what a
 * brand-new company gets. This file ships with the release and is never written
 * to — the per-company state (licensed / enabled) lives in the landlord
 * `company_modules` table. See App\Support\Modules.
 *
 * `licensed_by_default` applies to companies that have no row for the module:
 * a company created after this release starts with Core only, and a module
 * added in a *later* release appears for existing companies with the default
 * set here rather than being silently absent.
 *
 * Class ownership (which resource/page/widget/model belongs to which module) is
 * not here — it is in App\Support\ModuleMap, because it references classes.
 */
return [

    'core' => [
        'label' => 'Core',
        'description' => 'Users, roles, permissions, companies, custom fields, audit trail and fiscal years.',
        'requires' => [],
        'licensed_by_default' => true,

        // Core holds the Modules page itself, plus Users and Roles. Disabling it
        // would lock the company out of its own administration, so it has no
        // toggle on either surface — not a disabled one, none at all.
        'locked' => true,
        'plugin' => \App\Modules\Core\CorePlugin::class,
    ],

    'employees' => [
        'label' => 'Employees',
        'description' => 'Employee records, change requests and per-employee settings.',
        'requires' => [],
        'licensed_by_default' => false,
        'plugin' => \App\Modules\Employees\EmployeesPlugin::class,
    ],

    'advances' => [
        'label' => 'Advances',
        'description' => 'Money lent to employees, recovered from payroll in monthly instalments.',
        // Payroll is where the recovery actually happens — a recovery row points at
        // the payslip that took it — so Advances cannot be sold on its own. The
        // dependency the other way is soft: Payroll checks whether Advances is on
        // and falls back to the figure in employee settings when it is not.
        'requires' => ['employees', 'payroll'],
        'licensed_by_default' => false,
        'plugin' => \App\Modules\Advances\AdvancesPlugin::class,
    ],

    'expenses' => [
        'label' => 'Expense Claims',
        'description' => 'Employees claim what they paid for, an approver decides, and payroll reimburses it.',
        // Employees to claim, Payroll because reimbursement rides on the payslip —
        // expense_reimbursement is where the money reaches the employee.
        'requires' => ['employees', 'payroll'],
        'licensed_by_default' => false,
        'plugin' => \App\Modules\Expenses\ExpensesPlugin::class,
    ],

    'payroll' => [
        'label' => 'Payroll',
        'description' => 'Payslips, salary slabs, annual tax, salary bank files and FBR tax files.',
        'requires' => ['employees'],
        'licensed_by_default' => false,
        'plugin' => \App\Modules\Payroll\PayrollPlugin::class,
    ],

    // Requires Employees and nothing else. Payroll is deliberately NOT declared:
    // leave is worth having for the register, the balances and the approvals on
    // their own, and the company running this in production docks nothing for
    // unpaid absence today. The payroll join — pro-rating pay on lop_days — is a
    // later phase, guarded at its call site rather than declared here, so leave
    // stays sellable to a company that runs no payroll at all.
    // See docs/hrms-plan.md §2 and §5.
    'leave' => [
        'label' => 'Leave',
        'description' => 'Leave types, entitlements, requests, per-day records and computed balances.',
        'requires' => ['employees'],
        'licensed_by_default' => false,
        'plugin' => \App\Modules\Leave\LeavePlugin::class,
    ],

    // Requires Employees, and guarded on `leave` and `payroll` rather than
    // requiring them: attendance is worth having for the register alone, and the
    // company running this in production docks nothing for absence today. A factory
    // wants attendance and no timesheets; a software house wants the reverse — which
    // is the licensing fact that made this a module rather than part of one HRMS.
    'attendance' => [
        'label' => 'Attendance',
        'description' => 'Work patterns, daily attendance, overtime recorded, and corrections an employee can ask for.',
        'requires' => ['employees'],
        'licensed_by_default' => false,
        'plugin' => \App\Modules\Attendance\AttendancePlugin::class,
    ],

    'accounting' => [
        'label' => 'Accounting',
        'description' => 'Chart of accounts, journal entries, payments, banks, fixed assets, petty cash and financial reports.',
        'requires' => [],
        'licensed_by_default' => false,
        'plugin' => \App\Modules\Accounting\AccountingPlugin::class,
    ],

    'invoicing' => [
        'label' => 'Invoicing',
        'description' => 'Contacts and invoices. Posts journal entries through Accounting.',
        'requires' => ['accounting'],
        'licensed_by_default' => false,
        'plugin' => \App\Modules\Invoicing\InvoicingPlugin::class,
    ],

    'billing' => [
        'label' => 'Client Billing',
        'description' => "The month's bill to the client: every employee at full cost, the office expenses, less advance repayments.",
        // Payroll for the salary lines, Invoicing for the invoice it raises.
        // Advances is a soft dependency: a client with no advances has nothing to
        // credit back, so it is guarded rather than required.
        'requires' => ['employees', 'payroll', 'invoicing'],
        'licensed_by_default' => false,
        'plugin' => \App\Modules\Billing\BillingPlugin::class,
    ],

    'inventory' => [
        'label' => 'Inventory',
        'description' => 'Products and stock movements, valued through Accounting.',
        'requires' => ['accounting'],
        'licensed_by_default' => false,
        'plugin' => \App\Modules\Inventory\InventoryPlugin::class,
    ],

    'projects' => [
        'label' => 'Projects',
        'description' => 'Projects, environment health monitoring, certificate expiry tracking and the public status page.',
        'requires' => ['employees'],
        'licensed_by_default' => false,
        'plugin' => \App\Modules\Projects\ProjectsPlugin::class,
    ],

    // Requires Employees and Projects, genuinely: time booked against no project is
    // attendance, which is a different module. Guarded on `billing` and `invoicing`
    // for the hours-based invoice line — Billing asks for those lines through the
    // container behind a licence check, so the dependency points the way the licence
    // does and Billing stays sellable without this.
    //
    // Not for a factory: time against a project only means anything where the project
    // is the billable unit. That, and attendance not being for a software house, is
    // the licensing fact that made these separate modules rather than one HRMS.
    'timesheets' => [
        'label' => 'Timesheets',
        'description' => 'Time booked against projects, billable or not, and the hours-based invoice line.',
        'requires' => ['employees', 'projects'],
        'licensed_by_default' => false,
        'plugin' => \App\Modules\Timesheets\TimesheetsPlugin::class,
    ],

    // Requires Employees. Guarded on `payroll`, `leave` and `accounting`: the final
    // settlement reads encashable leave and an advance balance when those exist, and
    // links issued kit to the fixed-asset register when the books are kept here. Each
    // one missing makes the settlement a smaller document, not a broken one.
    'lifecycle' => [
        'label' => 'Joining & Leaving',
        'description' => 'Onboarding and exit checklists, documents that expire, issued assets, and the final settlement.',
        'requires' => ['employees'],
        'licensed_by_default' => false,
        'plugin' => \App\Modules\Lifecycle\LifecyclePlugin::class,
    ],

    // Requires NOTHING, deliberately: an applicant is not an employee, and a company
    // hiring its first person has no `employees` licence yet. The CONVERSION is the
    // guarded part — the Hire action is absent without `employees`, and the salary
    // package is skipped without `payroll`.
    'recruitment' => [
        'label' => 'Recruitment',
        'description' => 'Vacancies, applicants, applications, interviews, offers, and the hire that creates an employee.',
        'requires' => [],
        'licensed_by_default' => false,
        'plugin' => \App\Modules\Recruitment\RecruitmentPlugin::class,
    ],

    // Requires Employees. Guarded on `mpr`, which is the whole of the integration: a
    // review cycle READS the monthly progress reports in its period as evidence rather
    // than asking somebody to write the same thing twice. Guarded on `payroll` for the
    // suggested increment, which is a suggestion and writes nothing.
    'performance' => [
        'label' => 'Performance',
        'description' => 'Review cycles, goals, ratings and one-to-ones. Ratings never touch pay.',
        'requires' => ['employees'],
        'licensed_by_default' => false,
        'plugin' => \App\Modules\Performance\PerformancePlugin::class,
    ],

    // Requires NOTHING, and that is the plan's one architectural decision
    // (docs/crms-plan.md §1). A prospect is not a contact you can invoice, so CRM
    // owns its own `leads` table and must be sellable to a company that has bought
    // neither Invoicing nor Accounting — a bookkeeping practice still has clients it
    // is pitching to.
    //
    // Two soft couplings, both guarded at the call site and recorded in
    // ModuleBoundaryTest::KNOWN_COUPLINGS rather than declared here:
    //   - `invoicing` — converting a lead creates a Contact. Absent without it.
    //   - `employees` — a lead's owner is an employee, so EmployeeAccess scoping
    //     applies unchanged. Without it, ownership falls back to who created the row.
    // The precedent for guarding rather than requiring is invoicing -> projects.
    'crm' => [
        'label' => 'CRM',
        'description' => 'Leads, their sources and owners, and conversion into a customer.',
        'requires' => [],
        'licensed_by_default' => false,
        'plugin' => \App\Modules\Crm\CrmPlugin::class,
    ],

    // MPR keys on user_id rather than employee_id, so it does not actually need
    // the Employees module — the dependency in the module map is presentational,
    // not structural, and is deliberately not declared here.
    'mpr' => [
        'label' => 'MPR',
        'description' => 'Monthly progress reports and the comparison export.',
        'requires' => [],
        'licensed_by_default' => false,

        // Physically moved to app/Modules/Mpr. The plugin registers the module's
        // Filament classes with the panel; the service provider (listed in
        // bootstrap/providers.php) carries its policies and routes.
        'plugin' => \App\Modules\Mpr\MprPlugin::class,
    ],

    // Requires Accounting, and genuinely so rather than for tidiness: a personal
    // account keeps its books in the tenant's own chart of accounts and journal
    // entries, and the tax estimate is computed by reading the income posted
    // there. Without Accounting there is nothing for it to add up.
    //
    // It briefly had its own parallel ledger and therefore no dependency. That
    // was the wrong shape — see docs/personal-finance-plan.md.
    'personal_finance' => [
        'label' => 'Personal Finance',
        'description' => 'Individual Pakistani income tax estimate over a personal account\'s own books.',
        'requires' => ['accounting'],
        'licensed_by_default' => false,
        'plugin' => \App\Modules\PersonalFinance\PersonalFinancePlugin::class,
    ],

];
