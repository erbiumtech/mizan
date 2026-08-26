<?php

/**
 * What this module is, and what it owns.
 *
 * Requires Employees and Projects, genuinely: time booked against no project is
 * attendance, which is a different module. Guarded on `billing` and `invoicing`
 * for the hours-based invoice line — Billing asks for those lines through the
 * container behind a licence check, so the dependency points the way the licence
 * does and Billing stays sellable without this.
 *
 * Not for a factory: time against a project only means anything where the project
 * is the billable unit. That, and attendance not being for a software house, is
 * the licensing fact that made these separate modules rather than one HRMS.
 *
 * Merged by App\Support\ModuleManifest. See docs/module-packaging-plan.md §5.
 */
return [
    'key' => 'timesheets',
    'label' => 'Timesheets',
    'description' => 'Time booked against projects, billable or not, and the hours-based invoice line.',
    'requires' => [
        'employees',
        'projects',
    ],
    'licensed_by_default' => false,
    'plugin' => \App\Modules\Timesheets\TimesheetsPlugin::class,

    'models' => [
        'App\\Models\\TimesheetEntry' => \App\Modules\Timesheets\Models\TimesheetEntry::class,
    ],

    'resources' => [
        'App\\Filament\\Resources\\TimesheetEntries\\TimesheetEntryResource' => \App\Modules\Timesheets\Filament\Resources\TimesheetEntries\TimesheetEntryResource::class,
    ],

    /** The two reports. Pages rather than a resource: a report is a question, not rows to edit. */
    'pages' => [
        'App\\Filament\\Pages\\TimesheetUtilisation' => \App\Modules\Timesheets\Filament\Pages\TimesheetUtilisation::class,
        'App\\Filament\\Pages\\PlanVersusActual' => \App\Modules\Timesheets\Filament\Pages\PlanVersusActual::class,
        'App\\Filament\\Pages\\UnbilledWip' => \App\Modules\Timesheets\Filament\Pages\UnbilledWip::class,
    ],

    'widgets' => [
        'App\\Filament\\Widgets\\BillableShareOverview' => \App\Modules\Timesheets\Filament\Widgets\BillableShareOverview::class,
        'App\\Filament\\Widgets\\UnbilledWipOverview' => \App\Modules\Timesheets\Filament\Widgets\UnbilledWipOverview::class,
    ],

    // What the report builder may report on — reports-expansion-plan.md Phase 6, item 1. A dataset is
    // declared by the module that owns the subject, so it arrives with a module to gate on.
    'datasets' => [
        'App\\Reporting\\TimesheetEntryDataset' => \App\Modules\Timesheets\Reporting\TimesheetEntryDataset::class,
    ],

    'permission_groups' => [
        'Timesheet',
    ],

    'permissions' => [
        // Timesheets. Approving is separate for the usual reason, and it matters
        // more here than most: approved time becomes an invoice line a client pays.
        ['name' => 'TimesheetView', 'group' => 'Timesheet'],
        ['name' => 'TimesheetCreate', 'group' => 'Timesheet'],
        ['name' => 'TimesheetApprove', 'group' => 'Timesheet'],
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
            'TimesheetCreate',
            'TimesheetView',
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
        // The two reports declare the Reports group, as every report page in this application does.
        'Reports' => 'reports',
    ],
];
