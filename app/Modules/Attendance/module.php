<?php

/**
 * What this module is, and what it owns.
 *
 * Requires Employees, and guarded on `leave` and `payroll` rather than
 * requiring them: attendance is worth having for the register alone, and the
 * company running this in production docks nothing for absence today. A factory
 * wants attendance and no timesheets; a software house wants the reverse — which
 * is the licensing fact that made this a module rather than part of one HRMS.
 *
 * Merged by App\Support\ModuleManifest. See docs/module-packaging-plan.md §5.
 */
return [
    'key' => 'attendance',
    'label' => 'Attendance',
    'description' => 'Work patterns, daily attendance, overtime recorded, and corrections an employee can ask for.',
    'requires' => [
        'employees',
    ],
    'licensed_by_default' => false,
    'plugin' => \App\Modules\Attendance\AttendancePlugin::class,

    'models' => [
        'App\\Models\\WorkPattern' => \App\Modules\Attendance\Models\WorkPattern::class,
        'App\\Models\\WorkPatternDay' => \App\Modules\Attendance\Models\WorkPatternDay::class,
        'App\\Models\\EmployeeWorkPattern' => \App\Modules\Attendance\Models\EmployeeWorkPattern::class,
        'App\\Models\\AttendanceDay' => \App\Modules\Attendance\Models\AttendanceDay::class,
        'App\\Models\\AttendanceRegularization' => \App\Modules\Attendance\Models\AttendanceRegularization::class,
    ],

    'resources' => [
        'App\\Filament\\Resources\\AttendanceDays\\AttendanceDayResource' => \App\Modules\Attendance\Filament\Resources\AttendanceDays\AttendanceDayResource::class,
        'App\\Filament\\Resources\\AttendanceRegularizations\\AttendanceRegularizationResource' => \App\Modules\Attendance\Filament\Resources\AttendanceRegularizations\AttendanceRegularizationResource::class,
        'App\\Filament\\Resources\\WorkPatterns\\WorkPatternResource' => \App\Modules\Attendance\Filament\Resources\WorkPatterns\WorkPatternResource::class,
    ],

    'permission_groups' => [
        'Attendance',
        'AttendanceRegularization',
        'WorkPattern',
    ],

    'permissions' => [
        // Attendance. Corrections are their own group because an employee holds
        // Create on them and nothing else here — asking about your own past is not
        // the same privilege as writing anybody's day.
        ['name' => 'AttendanceView', 'group' => 'Attendance'],
        ['name' => 'AttendanceCreate', 'group' => 'Attendance'],
        ['name' => 'AttendanceUpdate', 'group' => 'Attendance'],
        ['name' => 'AttendanceDelete', 'group' => 'Attendance'],
        ['name' => 'AttendanceRegularizationView', 'group' => 'AttendanceRegularization'],
        ['name' => 'AttendanceRegularizationCreate', 'group' => 'AttendanceRegularization'],
        ['name' => 'AttendanceRegularizationApprove', 'group' => 'AttendanceRegularization'],
        ['name' => 'WorkPatternView', 'group' => 'WorkPattern'],
        ['name' => 'WorkPatternCreate', 'group' => 'WorkPattern'],
        ['name' => 'WorkPatternUpdate', 'group' => 'WorkPattern'],
        ['name' => 'WorkPatternDelete', 'group' => 'WorkPattern'],
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
            'AttendanceRegularizationCreate',
            'AttendanceRegularizationView',
            'AttendanceView',
            'WorkPatternView',
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
