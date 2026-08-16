<?php

/**
 * What this module is, and what it owns.
 *
 * Merged by App\Support\ModuleManifest. See docs/module-packaging-plan.md §5.
 */
return [
    'key' => 'advances',
    'label' => 'Advances',
    'description' => 'Money lent to employees, recovered from payroll in monthly instalments.',
    'requires' => [
        'employees',
        'payroll',
    ],
    'licensed_by_default' => false,
    'plugin' => \App\Modules\Advances\AdvancesPlugin::class,

    'models' => [
        'App\\Models\\Advance' => \App\Modules\Advances\Models\Advance::class,
        'App\\Models\\AdvanceRecovery' => \App\Modules\Advances\Models\AdvanceRecovery::class,
    ],

    'resources' => [
        'App\\Filament\\Resources\\Advances\\AdvanceResource' => \App\Modules\Advances\Filament\Resources\Advances\AdvanceResource::class,
    ],

    'permission_groups' => [
        'Advance',
    ],

    'permissions' => [
        ['name' => 'AdvanceView', 'group' => 'Advance'],
        ['name' => 'AdvanceCreate', 'group' => 'Advance'],
        ['name' => 'AdvanceUpdate', 'group' => 'Advance'],
        ['name' => 'AdvanceDelete', 'group' => 'Advance'],
    ],
];
