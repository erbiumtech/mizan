<?php

/**
 * What this module is, and what it owns.
 *
 * Merged by App\Support\ModuleManifest. See docs/module-packaging-plan.md §5.
 */
return [
    'key' => 'employees',
    'label' => 'Employees',
    'description' => 'Employee records, change requests and per-employee settings.',
    'requires' => [],
    'licensed_by_default' => false,
    'plugin' => \App\Modules\Employees\EmployeesPlugin::class,

    'models' => [
        'App\\Models\\EmployeeJobHistory' => \App\Modules\Employees\Models\EmployeeJobHistory::class,
        'App\\Models\\Employee' => \App\Modules\Employees\Models\Employee::class,
        'App\\Models\\EmployeeChangeRequest' => \App\Modules\Employees\Models\EmployeeChangeRequest::class,
        'App\\Models\\EmployeeSetting' => \App\Modules\Employees\Models\EmployeeSetting::class,
    ],

    'resources' => [
        'App\\Filament\\Resources\\Employees\\EmployeeResource' => \App\Modules\Employees\Filament\Resources\Employees\EmployeeResource::class,
        'App\\Filament\\Resources\\EmployeeChangeRequests\\EmployeeChangeRequestResource' => \App\Modules\Employees\Filament\Resources\EmployeeChangeRequests\EmployeeChangeRequestResource::class,
        'App\\Filament\\Resources\\EmployeeSettings\\EmployeeSettingResource' => \App\Modules\Employees\Filament\Resources\EmployeeSettings\EmployeeSettingResource::class,
    ],

    'widgets' => [
        'App\\Filament\\Widgets\\HeadcountOverview' => \App\Modules\Employees\Filament\Widgets\HeadcountOverview::class,
    ],

    'permission_groups' => [
        'Employee',
        'EmployeeSetting',
    ],

    'permissions' => [
        ['name' => 'EmployeeView', 'group' => 'Employee'],
        ['name' => 'EmployeeUpdate', 'group' => 'Employee'],
        ['name' => 'EmployeeDelete', 'group' => 'Employee'],
        ['name' => 'EmployeeSettingView', 'group' => 'EmployeeSetting'],
        ['name' => 'EmployeeSettingCreate', 'group' => 'EmployeeSetting'],
        ['name' => 'EmployeeSettingUpdate', 'group' => 'EmployeeSetting'],
        ['name' => 'EmployeeSettingDelete', 'group' => 'EmployeeSetting'],
        ['name' => 'EmployeeChangeApprove', 'group' => 'Employee'],
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
            'EmployeeSettingView',
        ],
        // On top of Accountant.
        'Manager' => [
            'EmployeeChangeApprove',
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
    /** The headcount report (Phase 3.6). A page: a report is a question, not rows to edit. */
    'pages' => [
        'App\\Filament\\Pages\\HeadcountMovement' => \App\Modules\Employees\Filament\Pages\HeadcountMovement::class,
    ],

    'navigation' => [
        // The report declares the Reports group, as every report page in this application does.
        'Reports' => 'reports',
        'Employee' => 'people',
    ],
];
