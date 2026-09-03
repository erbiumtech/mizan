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

    // What the report builder may report on — reports-expansion-plan.md Phase 6, item 1. A dataset is
    // declared by the module that owns the subject, so it arrives with a module to gate on.
    'datasets' => [
        'App\\Reporting\\EmployeeDataset' => \App\Modules\Employees\Reporting\EmployeeDataset::class,
    ],

    /**
     * The three job facts a company writes in its own words — App\Support\OptionLists.
     *
     * They were literal arrays in EmployeeForm, so a company hiring its first draughtsman
     * needed a deploy, and Recruitment asked for the same three as free text: a vacancy
     * for a "Backend Developer" hired an employee whose designation read "backend dev",
     * and the headcount-by-department report counted them apart. One list, both screens.
     *
     * Safe to make editable, which most of this panel's dropdowns are not: all three are
     * plain `string` columns and nothing branches on their values — `employment_type`
     * records what somebody is, and probation is not a rule anything enforces yet.
     */
    'option_lists' => [
        'employees.designation' => [
            'label' => 'Designations',
            'help' => 'Job titles. Shown on the employee record, on a vacancy, and carried onto the employee a hire creates.',
            'values' => [
                'Senior Full Stack Developer',
                'Full Stack Developer',
                'Frontend Developer',
                'Backend Developer',
                'Secretary',
                'Cook',
                'Office Boy',
            ],
        ],

        'employees.department' => [
            'label' => 'Departments',
            'help' => 'Also one of the three dimensions the ledger can split a figure by, so keep the wording stable — renaming here relabels, it does not restate what is already posted.',
            'values' => [
                'IT',
                'Office Staff',
            ],
        ],

        // The third answer this form has offered since June and the column could not store
        // on MySQL — see the migration that widened it. A list rather than a fixed three
        // because no fixed three is right for every company.
        'employees.gender' => [
            'label' => 'Genders',
            'help' => 'How people are recorded. Nothing computes from this: it is printed on the employee record and reported on.',
            'values' => [
                'Male',
                'Female',
                'Other',
            ],
        ],

        'employees.employment_type' => [
            'label' => 'Employment types',
            'help' => 'What kind of engagement somebody is on. A change writes a dated job-history row, so "were they permanent in March" stays answerable.',
            // value => label: the column stores the slug these shipped with, and rows
            // already carry it.
            'values' => [
                'permanent' => 'Permanent',
                'contract' => 'Contract',
                'probation' => 'Probation',
                'intern' => 'Intern',
            ],
        ],
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
