<?php

/**
 * What this module is, and what it owns.
 *
 * Merged by App\Support\ModuleManifest. See docs/module-packaging-plan.md §5.
 */
return [
    'key' => 'billing',
    'label' => 'Client Billing',
    'description' => 'The month\'s bill to the client: every employee at full cost, the office expenses, less advance repayments.',
    'requires' => [
        'employees',
        'payroll',
        'invoicing',
    ],
    'licensed_by_default' => false,
    'plugin' => \App\Modules\Billing\BillingPlugin::class,

    'models' => [
        'App\\Models\\BillingRun' => \App\Modules\Billing\Models\BillingRun::class,
    ],

    'resources' => [
        'App\\Filament\\Resources\\BillingRuns\\BillingRunResource' => \App\Modules\Billing\Filament\Resources\BillingRuns\BillingRunResource::class,
    ],

    'permission_groups' => [
        'BillingRun',
    ],

    'permissions' => [
        ['name' => 'BillingRunView', 'group' => 'BillingRun'],
        ['name' => 'BillingRunCreate', 'group' => 'BillingRun'],
        ['name' => 'BillingRunUpdate', 'group' => 'BillingRun'],
        ['name' => 'BillingRunDelete', 'group' => 'BillingRun'],
    ],
];
