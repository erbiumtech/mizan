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
];
