<?php

/**
 * What this module is, and what it owns.
 *
 * Merged by App\Support\ModuleManifest. See docs/module-packaging-plan.md §5.
 */
return [
    'key' => 'expenses',
    'label' => 'Expense Claims',
    'description' => 'Employees claim what they paid for, an approver decides, and payroll reimburses it.',
    'requires' => [
        'employees',
        'payroll',
    ],
    'licensed_by_default' => false,
    'plugin' => \App\Modules\Expenses\ExpensesPlugin::class,

    'models' => [
        'App\\Models\\ExpenseClaim' => \App\Modules\Expenses\Models\ExpenseClaim::class,
    ],

    'resources' => [
        'App\\Filament\\Resources\\ExpenseClaims\\ExpenseClaimResource' => \App\Modules\Expenses\Filament\Resources\ExpenseClaims\ExpenseClaimResource::class,
    ],

    'permission_groups' => [
        'ExpenseClaim',
    ],

    'permissions' => [
        ['name' => 'ExpenseClaimView', 'group' => 'ExpenseClaim'],
        ['name' => 'ExpenseClaimCreate', 'group' => 'ExpenseClaim'],
        ['name' => 'ExpenseClaimUpdate', 'group' => 'ExpenseClaim'],
        ['name' => 'ExpenseClaimDelete', 'group' => 'ExpenseClaim'],
        ['name' => 'ExpenseClaimApprove', 'group' => 'ExpenseClaim'],
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
            'ExpenseClaimCreate',
            'ExpenseClaimUpdate',
            'ExpenseClaimView',
        ],
        // Records, does not approve.
        'Accountant' => [
            'ExpenseClaimApprove',
            'ExpenseClaimCreate',
            'ExpenseClaimUpdate',
            'ExpenseClaimView',
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
        'Employee' => 'people',
    ],
];
