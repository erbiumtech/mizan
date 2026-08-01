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

    'payroll' => [
        'label' => 'Payroll',
        'description' => 'Payslips, salary slabs, annual tax, salary bank files and FBR tax files.',
        'requires' => ['employees'],
        'licensed_by_default' => false,
        'plugin' => \App\Modules\Payroll\PayrollPlugin::class,
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

];
