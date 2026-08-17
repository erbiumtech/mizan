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
            'AdvanceView',
        ],
        // Records, does not approve.
        'Accountant' => [
            'AdvanceCreate',
            'AdvanceUpdate',
            'AdvanceView',
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
