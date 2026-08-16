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
];
