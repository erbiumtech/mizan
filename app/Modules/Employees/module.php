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
];
