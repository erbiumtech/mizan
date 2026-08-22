<?php

/**
 * What this module is, and what it owns.
 *
 * Merged by App\Support\ModuleManifest. See docs/module-packaging-plan.md §5.
 */
return [
    'key' => 'payroll',
    'label' => 'Payroll',
    'description' => 'Payslips, salary slabs, annual tax, salary bank files and FBR tax files.',
    'requires' => [
        'employees',
    ],
    'licensed_by_default' => false,
    'plugin' => \App\Modules\Payroll\PayrollPlugin::class,

    'models' => [
        'App\\Models\\PayrollRun' => \App\Modules\Payroll\Models\PayrollRun::class,
        'App\\Models\\PayComponent' => \App\Modules\Payroll\Models\PayComponent::class,
        'App\\Models\\EmployeeSettingComponent' => \App\Modules\Payroll\Models\EmployeeSettingComponent::class,
        'App\\Models\\PayslipComponent' => \App\Modules\Payroll\Models\PayslipComponent::class,
        'App\\Models\\Payslip' => \App\Modules\Payroll\Models\Payslip::class,
        'App\\Models\\SalarySlab' => \App\Modules\Payroll\Models\SalarySlab::class,
        'App\\Models\\AnnualTax' => \App\Modules\Payroll\Models\AnnualTax::class,
    ],

    'resources' => [
        'App\\Filament\\Resources\\PayComponents\\PayComponentResource' => \App\Modules\Payroll\Filament\Resources\PayComponents\PayComponentResource::class,
        'App\\Filament\\Resources\\PayrollRuns\\PayrollRunResource' => \App\Modules\Payroll\Filament\Resources\PayrollRuns\PayrollRunResource::class,
        'App\\Filament\\Resources\\Payslips\\PayslipResource' => \App\Modules\Payroll\Filament\Resources\Payslips\PayslipResource::class,
        'App\\Filament\\Resources\\SalarySlabs\\SalarySlabResource' => \App\Modules\Payroll\Filament\Resources\SalarySlabs\SalarySlabResource::class,
        'App\\Filament\\Resources\\AnnualTaxes\\AnnualTaxResource' => \App\Modules\Payroll\Filament\Resources\AnnualTaxes\AnnualTaxResource::class,
    ],

    'pages' => [
        'App\\Filament\\Pages\\SalaryBankFile' => \App\Modules\Payroll\Filament\Pages\SalaryBankFile::class,
        'App\\Filament\\Pages\\FbrTaxFile' => \App\Modules\Payroll\Filament\Pages\FbrTaxFile::class,
        'App\\Filament\\Pages\\TaxSummary' => \App\Modules\Payroll\Filament\Pages\TaxSummary::class,
    ],

    'widgets' => [
        'App\\Filament\\Widgets\\PayrollByEmployeeChart' => \App\Modules\Payroll\Filament\Widgets\PayrollByEmployeeChart::class,
    ],

    'permission_groups' => [
        'Payslip',
        'SalarySlab',
        'AnnualTax',
    ],

    'permissions' => [
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
        ['name' => 'AnnualTaxCreate', 'group' => 'AnnualTax'],
        ['name' => 'AnnualTaxView', 'group' => 'AnnualTax'],
        ['name' => 'AnnualTaxUpdate', 'group' => 'AnnualTax'],
        ['name' => 'AnnualTaxDelete', 'group' => 'AnnualTax'],
    ],

    /**
     * Which of this module's permissions each role starts with.
     *
     * Administrator holds everything and is not listed. Manager and CEO are *additions* to the
     * role below them — RoleSeeder composes Accountant -> Manager -> CEO — so a permission
     * already granted to Accountant is not repeated here.
     */
    'role_grants' => [
        // Every member of staff.
        'Employee' => [
            'PayslipView',
        ],
        // Records, does not approve.
        'Accountant' => [
            'PayrollRunLock',
            'PayrollRunView',
            'PayslipCreate',
            'PayslipUpdate',
            'PayslipView',
        ],
    ],

    /**
     * Which domain of the two-level shell this module's screens appear in.
     *
     * Keyed on the navigation group label the resources and pages declare. Labels are shared —
     * "Employee" is claimed by ten modules — so agreement is normal and a label claimed for two
     * different domains throws in ModuleManifest rather than resolving to whichever manifest was
     * read last. The six domains themselves are App\Support\NavigationDomains.
     */
    'navigation' => [
        'Audit & Taxes' => 'finance',
        'Employee' => 'people',
        'Reports' => 'reports',
        'Settings' => 'admin',
    ],
];
