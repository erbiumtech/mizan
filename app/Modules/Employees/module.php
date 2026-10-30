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
        'App\\Models\\Department' => \App\Modules\Employees\Models\Department::class,
        'App\\Models\\Designation' => \App\Modules\Employees\Models\Designation::class,
        'App\\Models\\Employee' => \App\Modules\Employees\Models\Employee::class,
        'App\\Models\\EmployeeChangeRequest' => \App\Modules\Employees\Models\EmployeeChangeRequest::class,
        'App\\Models\\EmployeeSetting' => \App\Modules\Employees\Models\EmployeeSetting::class,
        // Announcements — docs/saas-launch-plan.md §7.6.
        'App\\Models\\Announcement' => \App\Modules\Employees\Models\Announcement::class,
        // Letters the company writes in its own words, and the queue employees ask through —
        // docs/saas-launch-plan.md §14.7.
        'App\\Models\\LetterTemplate' => \App\Modules\Employees\Models\LetterTemplate::class,
        'App\\Models\\LetterRequest' => \App\Modules\Employees\Models\LetterRequest::class,
        // What has been proved about an employee record — §14.8.
        'App\\Models\\EmployeeVerification' => \App\Modules\Employees\Models\EmployeeVerification::class,
    ],

    'resources' => [
        'App\\Filament\\Resources\\Employees\\EmployeeResource' => \App\Modules\Employees\Filament\Resources\Employees\EmployeeResource::class,
        'App\\Filament\\Resources\\Departments\\DepartmentResource' => \App\Modules\Employees\Filament\Resources\Departments\DepartmentResource::class,
        'App\\Filament\\Resources\\Designations\\DesignationResource' => \App\Modules\Employees\Filament\Resources\Designations\DesignationResource::class,
        'App\\Filament\\Resources\\EmployeeChangeRequests\\EmployeeChangeRequestResource' => \App\Modules\Employees\Filament\Resources\EmployeeChangeRequests\EmployeeChangeRequestResource::class,
        'App\\Filament\\Resources\\EmployeeSettings\\EmployeeSettingResource' => \App\Modules\Employees\Filament\Resources\EmployeeSettings\EmployeeSettingResource::class,
        'App\\Filament\\Resources\\Announcements\\AnnouncementResource' => \App\Modules\Employees\Filament\Resources\Announcements\AnnouncementResource::class,
        'App\\Filament\\Resources\\LetterTemplates\\LetterTemplateResource' => \App\Modules\Employees\Filament\Resources\LetterTemplates\LetterTemplateResource::class,
        'App\\Filament\\Resources\\LetterRequests\\LetterRequestResource' => \App\Modules\Employees\Filament\Resources\LetterRequests\LetterRequestResource::class,
    ],

    'widgets' => [
        'App\\Filament\\Widgets\\HeadcountOverview' => \App\Modules\Employees\Filament\Widgets\HeadcountOverview::class,
        'App\\Filament\\Widgets\\AnnouncementsOverview' => \App\Modules\Employees\Filament\Widgets\AnnouncementsOverview::class,
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
        // How an experience letter describes somebody's conduct. A list because the wording is the
        // company's own and the letter is read by a next employer: "satisfactory" and "exemplary" are
        // different references, and HR should not have to type either into a free-text box.
        // What kind of notice an announcement is — docs/saas-launch-plan.md §14.10. A
        // company's own vocabulary, so a list rather than an enum: nothing branches on
        // the value and two companies would write different ones.
        'employees.announcement_category' => [
            'label' => 'Announcement categories',
            'help' => 'Shown on the announcement and filterable. "Policy", "Office", "Holiday" — whatever this company files them under.',
            'values' => [
                'Policy',
                'Office',
                'Holiday',
                'People',
                'System',
            ],
        ],

        'employees.conduct' => [
            'label' => 'Conduct wording (experience letters)',
            'help' => 'Printed as "conduct and performance were found to be …" on an experience letter.',
            'values' => [
                'satisfactory',
                'good',
                'very good',
                'excellent',
                'exemplary',
            ],
        ],

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
        'Announcement',
        'Letter',
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
        /*
         * Subject access and erasure — docs/saas-launch-plan.md §14.8. One permission for both,
         * and Administrator-only by default: disclosing everything the company holds about a person
         * and emptying it are two halves of the same request, and the second cannot be undone.
         */
        ['name' => 'EmployeePrivacy', 'group' => 'Employee'],
        // Managing announcements. Reading them needs nothing: the dashboard shows
        // each employee what was aimed at them.
        ['name' => 'AnnouncementView', 'group' => 'Announcement'],
        ['name' => 'AnnouncementCreate', 'group' => 'Announcement'],
        ['name' => 'AnnouncementUpdate', 'group' => 'Announcement'],
        ['name' => 'AnnouncementDelete', 'group' => 'Announcement'],
        /*
         * Letters — §14.7. Three permissions rather than the usual four.
         *
         * Asking for one is not a permission: every employee may, the way every employee may file a
         * change request, and a company that wanted to stop one person asking would be doing something
         * else. `LetterIssue` is the decision — it writes a document on company letterhead — and
         * `LetterTemplateUpdate` is the wording itself, which is a different and larger thing: whoever
         * holds it writes what every future letter says.
         */
        ['name' => 'LetterView', 'group' => 'Letter'],
        ['name' => 'LetterIssue', 'group' => 'Letter'],
        ['name' => 'LetterTemplateUpdate', 'group' => 'Letter'],
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
            'AnnouncementView',
            'AnnouncementCreate',
            'AnnouncementUpdate',
            'LetterView',
            'LetterIssue',
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
        'App\\Filament\\Pages\\Probations' => \App\Modules\Employees\Filament\Pages\Probations::class,
        'App\\Filament\\Pages\\Transfers' => \App\Modules\Employees\Filament\Pages\Transfers::class,
        'App\\Filament\\Pages\\Promotions' => \App\Modules\Employees\Filament\Pages\Promotions::class,
        // The two the walkthrough had and this lacked — docs/saas-launch-plan.md §14.10.
        'App\\Filament\\Pages\\Anniversaries' => \App\Modules\Employees\Filament\Pages\Anniversaries::class,
        'App\\Filament\\Pages\\Demographics' => \App\Modules\Employees\Filament\Pages\Demographics::class,
        // Where somebody confirms their own phone and address — docs/saas-launch-plan.md §14.8.
        'App\\Filament\\Pages\\ConfirmDetails' => \App\Modules\Employees\Filament\Pages\ConfirmDetails::class,
    ],

    'navigation' => [
        // The report declares the Reports group, as every report page in this application does.
        'Reports' => 'reports',
        'Employee' => 'people',
        // Departments and designations as rows — docs/saas-launch-plan.md §14.2.
        'Settings' => 'admin',
    ],
];
