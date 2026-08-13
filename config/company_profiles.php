<?php

/**
 * The company profile registry: what kinds of business this application is sold
 * to, which modules each starts with, and what reference data each is seeded.
 *
 * A profile is a *preset, not a lock*. It decides what a company is licensed at
 * provisioning and what it is recommended afterwards; a super admin may grant
 * anything to anyone regardless. The enforcement boundary is unchanged and lives
 * where it always did — `licensed && enabled && requirements`, checked in
 * Gate::before, EnsureModuleEnabled and every canAccess().
 *
 * `seeders` runs ONCE, at provisioning. Changing a company's profile afterwards
 * re-labels it and re-scopes what "recommended" means; it never re-seeds, because
 * a chart of accounts with journal entries posted against it cannot be swapped.
 * See docs/company-profiles-plan.md §9.
 *
 * Two invariants, both asserted by CompanyProfileTest because both fail silently:
 *
 *  - `modules` must be closed under `requires`. Modules::enabledFor() recurses
 *    into requirements, so licensing `billing` without `invoicing` yields a
 *    module that is licensed, shows a toggle, and can never be switched on.
 *  - `seeders` must agree with `modules`: SalarySlabSeeder iff `payroll`, and the
 *    chart must match the type — the transaction types are keyed to their own
 *    chart's account codes, so a mismatched pair produces spending categories
 *    pointing at accounts that mean something else.
 *
 * See App\Support\CompanyProfiles.
 */

use App\Modules\Core\Models\Company;
use Database\Seeders\BankSeeder;
use Database\Seeders\ChartOfAccountsSeeder;
use Database\Seeders\CurrencySeeder;
use Database\Seeders\FiscalYearSeeder;
use Database\Seeders\LeadSourceSeeder;
use Database\Seeders\LeaveTypeSeeder;
use Database\Seeders\PersonalChartOfAccountsSeeder;
use Database\Seeders\PersonalTransactionTypeSeeder;
use Database\Seeders\SalarySlabSeeder;
use Database\Seeders\StatutoryComponentSeeder;
use Database\Seeders\TaxScheduleSeeder;
use Database\Seeders\TransactionTypeSeeder;

// Order matters and is not alphabetical: the chart must exist before the
// transaction types that key to its codes, and the fiscal years before the
// salary slabs that hang off one. These mirror TenantBaselineSeeder::seeders()
// and PersonalBaselineSeeder::seeders(), which remain the answer for a company
// with no profile.
$business = [
    FiscalYearSeeder::class,
    ChartOfAccountsSeeder::class,
    CurrencySeeder::class,
    TransactionTypeSeeder::class,
    SalarySlabSeeder::class,
    // Phase 9. The statutory pay COMPONENTS, not any amount: creating them says this
    // company may deduct EOBI, and deducts nothing. After the chart, because each posts
    // to its own liability account.
    StatutoryComponentSeeder::class,
    BankSeeder::class,
    TaxScheduleSeeder::class,
];

// No payroll, so no slabs — the only profile that diverges from the business
// baseline. Derived rather than retyped so it cannot drift from the list above.
$businessWithoutPayroll = array_values(array_diff($business, [SalarySlabSeeder::class]));

// The business baseline plus the leave types, for every profile that licenses
// `leave`. Derived rather than retyped for the same reason as above.
//
// Deliberately NOT folded into $business: that list is also what the no-profile
// path seeds (TenantBaselineSeeder, asserted equal by CompanyProfileTest), and a
// company provisioned without a profile licenses no leave — so seeding leave types
// there would create reference data for a module nobody bought.
//
// The day counts are provincial defaults a company's HR must confirm; see
// LeaveTypeSeeder.
$businessWithLeave = [...$business, LeaveTypeSeeder::class, LeadSourceSeeder::class];

// Bookkeeping licenses `crm` but not `leave`, so it needs the lead sources without
// the leave types — and it has no payroll either. docs/crms-plan.md §2 argues the
// inclusion explicitly rather than deriving it: a bookkeeping-only company looks
// like it has no sales pipeline, but §1's whole case is that `crm` requires nothing
// and phases 1-4 are a usable CRM on their own. A bookkeeping practice has clients
// it is pitching to. If that turns out to be false, then §1's independence is
// theoretical and the requirement should be declared instead.
$bookkeepingSeeders = [...$businessWithoutPayroll, LeadSourceSeeder::class];

$personal = [
    FiscalYearSeeder::class,
    CurrencySeeder::class,
    PersonalChartOfAccountsSeeder::class,
    BankSeeder::class,
    PersonalTransactionTypeSeeder::class,
    TaxScheduleSeeder::class,
];

return [

    'personal' => [
        'label' => 'Personal Account',
        'description' => 'One person\'s own affairs: a household chart of accounts, spending categories and the individual tax estimate. No payroll — paying the cook is an expense, not a payslip.',
        'type' => Company::TYPE_PERSONAL,
        'modules' => ['accounting', 'employees', 'personal_finance'],
        'seeders' => $personal,
    ],

    'services' => [
        'label' => 'Services / Consultancy',
        'description' => 'Billable people on client work: projects, monthly progress reports, expense claims and salary advances.',
        'type' => Company::TYPE_BUSINESS,
        'modules' => ['accounting', 'employees', 'payroll', 'invoicing', 'projects', 'mpr', 'expenses', 'advances', 'leave', 'crm', 'timesheets', 'lifecycle', 'recruitment', 'performance'],
        'seeders' => $businessWithLeave,
    ],

    'software_house' => [
        'label' => 'Software House / Agency',
        'description' => 'Project delivery with environment health and certificate tracking. Services without the advances.',
        'type' => Company::TYPE_BUSINESS,
        'modules' => ['accounting', 'employees', 'payroll', 'invoicing', 'projects', 'mpr', 'expenses', 'leave', 'crm', 'timesheets', 'lifecycle', 'recruitment', 'performance'],
        'seeders' => $businessWithLeave,
    ],

    'staffing' => [
        'label' => 'Staffing / Outsourcing',
        'description' => 'Staff placed with clients and billed on at full cost: client billing, advances and expense claims.',
        'type' => Company::TYPE_BUSINESS,
        'modules' => ['accounting', 'employees', 'payroll', 'invoicing', 'billing', 'advances', 'expenses', 'leave', 'crm', 'attendance', 'lifecycle', 'recruitment'],
        'seeders' => $businessWithLeave,
    ],

    'trading' => [
        'label' => 'Trading / Distribution',
        'description' => 'Buying and selling goods: stock movements valued through the ledger, invoices and payroll.',
        'type' => Company::TYPE_BUSINESS,
        'modules' => ['accounting', 'invoicing', 'inventory', 'employees', 'payroll', 'leave', 'crm', 'attendance', 'lifecycle', 'recruitment'],
        'seeders' => $businessWithLeave,
    ],

    'manufacturing' => [
        'label' => 'Manufacturing',
        'description' => 'Trading plus the expense claims a production floor generates.',
        'type' => Company::TYPE_BUSINESS,
        'modules' => ['accounting', 'invoicing', 'inventory', 'employees', 'payroll', 'expenses', 'leave', 'crm', 'attendance', 'lifecycle', 'recruitment'],
        'seeders' => $businessWithLeave,
    ],

    'bookkeeping' => [
        'label' => 'Bookkeeping Only',
        'description' => 'The books and the invoices, nothing else. No employees, so no payroll and no salary slabs.',
        'type' => Company::TYPE_BUSINESS,
        'modules' => ['accounting', 'invoicing', 'crm'],
        'seeders' => $bookkeepingSeeders,
    ],

];
