<?php

/**
 * What this module is, and what it owns.
 *
 * Merged by App\Support\ModuleManifest. See docs/module-packaging-plan.md §5.
 */
return [
    'key' => 'core',
    'label' => 'Core',
    'description' => 'Users, roles, permissions, companies, custom fields, audit trail and fiscal years.',
    'requires' => [],
    'licensed_by_default' => true,
    'locked' => true,
    'plugin' => \App\Modules\Core\CorePlugin::class,

    'models' => [
        'App\\Models\\EmailTemplate' => \App\Modules\Core\Models\EmailTemplate::class,
        'App\\Models\\User' => \App\Modules\Core\Models\User::class,
        'App\\Models\\Company' => \App\Modules\Core\Models\Company::class,
        'App\\Models\\TableView' => \App\Modules\Core\Models\TableView::class,
        // A person's saved filters for one report — Core for the same reason the Reports hub is: the hub
        // belongs to no module and every module puts reports in it.
        'App\\Models\\SavedReportView' => \App\Modules\Core\Models\SavedReportView::class,
        // A report somebody assembled in the builder — Phase 6, item 3 of the reports plan.
        'App\\Models\\ReportDefinition' => \App\Modules\Core\Models\ReportDefinition::class,
        // A report on a timetable, and one send of one — Phase 8, items 1 and 4 of the reports plan. Core
        // because the schedule may name any module's report, and the hub they are named from is here.
        'App\\Models\\ReportSchedule' => \App\Modules\Core\Models\ReportSchedule::class,
        'App\\Models\\ReportDelivery' => \App\Modules\Core\Models\ReportDelivery::class,
        // How one person, or the company, has arranged the dashboard — Phase 7 of the reports plan.
        'App\\Models\\DashboardLayout' => \App\Modules\Core\Models\DashboardLayout::class,
        'App\\Models\\CustomField' => \App\Modules\Core\Models\CustomField::class,
        'App\\Models\\CustomFieldValue' => \App\Modules\Core\Models\CustomFieldValue::class,
        'App\\Models\\ActivityLog' => \App\Modules\Core\Models\ActivityLog::class,
        'App\\Models\\Comment' => \App\Modules\Core\Models\Comment::class,
        'App\\Models\\FiscalYear' => \App\Modules\Core\Models\FiscalYear::class,
        'App\\Models\\Holiday' => \App\Modules\Core\Models\Holiday::class,
        // The IBFT bank list. Core for the same reason Holiday is — see the model.
        'App\\Models\\Bank' => \App\Modules\Core\Models\Bank::class,
        'App\\Models\\Setting' => \App\Modules\Core\Models\Setting::class,
        // One entry in one admin-managed dropdown. Core because the table serves every
        // module's lists and the screen that edits them sits with Company Settings.
        'App\\Models\\OptionValue' => \App\Modules\Core\Models\OptionValue::class,
    ],

    'resources' => [
        'App\\Filament\\Resources\\EmailTemplates\\EmailTemplateResource' => \App\Modules\Core\Filament\Resources\EmailTemplates\EmailTemplateResource::class,
        'App\\Filament\\Resources\\Users\\UserResource' => \App\Modules\Core\Filament\Resources\Users\UserResource::class,
        'App\\Filament\\Resources\\Roles\\RoleResource' => \App\Modules\Core\Filament\Resources\Roles\RoleResource::class,
        'App\\Filament\\Resources\\Permissions\\PermissionResource' => \App\Modules\Core\Filament\Platform\Resources\Permissions\PermissionResource::class,
        'App\\Filament\\Platform\\Resources\\Roles\\PlatformRoleResource' => \App\Modules\Core\Filament\Platform\Resources\Roles\PlatformRoleResource::class,
        'App\\Filament\\Platform\\Resources\\Users\\PlatformUserResource' => \App\Modules\Core\Filament\Platform\Resources\Users\PlatformUserResource::class,
        'App\\Filament\\Platform\\Resources\\ActivityLogs\\PlatformActivityLogResource' => \App\Modules\Core\Filament\Platform\Resources\ActivityLogs\PlatformActivityLogResource::class,
        'App\\Filament\\Resources\\Companies\\CompanyResource' => \App\Modules\Core\Filament\Platform\Resources\Companies\CompanyResource::class,
        'App\\Filament\\Resources\\CustomFields\\CustomFieldResource' => \App\Modules\Core\Filament\Resources\CustomFields\CustomFieldResource::class,
        'App\\Filament\\Resources\\ActivityLogs\\ActivityLogResource' => \App\Modules\Core\Filament\Resources\ActivityLogs\ActivityLogResource::class,
        'App\\Filament\\Resources\\Comments\\CommentResource' => \App\Modules\Core\Filament\Resources\Comments\CommentResource::class,
        'App\\Filament\\Resources\\FiscalYears\\FiscalYearResource' => \App\Modules\Core\Filament\Resources\FiscalYears\FiscalYearResource::class,
        // Scheduled reports — Phase 8, item 1 of the reports plan.
        'App\\Filament\\Resources\\ReportSchedules\\ReportScheduleResource' => \App\Modules\Core\Filament\Resources\ReportSchedules\ReportScheduleResource::class,
        'App\\Filament\\Resources\\Holidays\\HolidayResource' => \App\Modules\Core\Filament\Resources\Holidays\HolidayResource::class,
        'App\\Filament\\Resources\\OptionValues\\OptionValueResource' => \App\Modules\Core\Filament\Resources\OptionValues\OptionValueResource::class,
    ],

    'widgets' => [
        // Belongs to no module and every module contributes to it — see App\\Support\\DashboardStats.
        'App\\Filament\\Widgets\\OperationsOverview' => \App\Modules\Core\Filament\Widgets\OperationsOverview::class,
    ],

    'pages' => [
        // The dashboard. Core for the reason the Reports hub is: it belongs to no module and every module
        // contributes widgets to it, so each widget's own canView() is the only thing deciding what appears.
        'App\\Filament\\Pages\\Dashboard' => \App\Modules\Core\Filament\Pages\Dashboard::class,
        'App\\Filament\\Pages\\Reports' => \App\Modules\Core\Filament\Pages\Reports::class,
        // The delivery log — Phase 8, item 8 of the reports plan. A report about the application rather than
        // about the business, which is why it is here and not in a module.
        'App\\Filament\\Pages\\ReportDeliveries' => \App\Modules\Core\Filament\Pages\ReportDeliveries::class,
        'App\\Filament\\Pages\\UserManual' => \App\Modules\Core\Filament\Pages\UserManual::class,
        'App\\Filament\\Pages\\CompanySettings' => \App\Modules\Core\Filament\Pages\CompanySettings::class,
        'App\\Filament\\Pages\\Modules' => \App\Modules\Core\Filament\Pages\Modules::class,
        'App\\Filament\\Pages\\CsvImport' => \App\Modules\Core\Filament\Pages\CsvImport::class,
        'App\\Filament\\Pages\\Auth\\EditProfile' => \App\Modules\Core\Filament\Pages\Auth\EditProfile::class,
    ],

    'permission_groups' => [
        'User',
        'Role',
        'Permission',
        'ActivityLog',
        'Comment',
        'FiscalYear',
        'Holiday',
    ],

    'permissions' => [
        ['name' => 'UserView', 'group' => 'User'],
        ['name' => 'UserCreate', 'group' => 'User'],
        ['name' => 'UserUpdate', 'group' => 'User'],
        ['name' => 'UserDelete', 'group' => 'User'],
        // Sign in as another user. Administrator gets it with everything else;
        // no other seeded role lists it.
        ['name' => 'UserImpersonate', 'group' => 'User'],
        ['name' => 'viewAnyRole', 'group' => 'Role'],
        ['name' => 'viewRole', 'group' => 'Role'],
        ['name' => 'createRole', 'group' => 'Role'],
        ['name' => 'updateRole', 'group' => 'Role'],
        ['name' => 'deleteRole', 'group' => 'Role'],
        ['name' => 'viewAnyPermission', 'group' => 'Permission'],
        ['name' => 'viewPermission', 'group' => 'Permission'],
        ['name' => 'createPermission', 'group' => 'Permission'],
        ['name' => 'updatePermission', 'group' => 'Permission'],
        ['name' => 'deletePermission', 'group' => 'Permission'],
        ['name' => 'FiscalYearCreate', 'group' => 'FiscalYear'],
        ['name' => 'FiscalYearView', 'group' => 'FiscalYear'],
        ['name' => 'FiscalYearUpdate', 'group' => 'FiscalYear'],
        ['name' => 'FiscalYearDelete', 'group' => 'FiscalYear'],
        // The company holiday calendar. Core, like FiscalYear, because leave
        // and attendance both need "is this a working day" and neither owns
        // the answer — docs/hrms-plan.md §3.
        ['name' => 'HolidayCreate', 'group' => 'Holiday'],
        ['name' => 'HolidayView', 'group' => 'Holiday'],
        ['name' => 'HolidayUpdate', 'group' => 'Holiday'],
        ['name' => 'HolidayDelete', 'group' => 'Holiday'],
        ['name' => 'ActivityLogView', 'group' => 'ActivityLog'],
        ['name' => 'CommentCreate', 'group' => 'Comment'],
        ['name' => 'CommentView', 'group' => 'Comment'],
        ['name' => 'CommentResolve', 'group' => 'Comment'],
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
            'CommentCreate',
            'CommentView',
        ],
        // Records, does not approve.
        'Accountant' => [
            'ActivityLogView',
            'CommentCreate',
            'CommentResolve',
            'CommentView',
            'HolidayView',
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
        'Access Control' => 'admin',
        'Audit & Taxes' => 'finance',
        'Settings' => 'admin',
    ],

    /**
     * Pages that register no navigation group, claimed per class.
     *
     * Filament collects every ungrouped page into one unlabelled group, so these cannot be placed
     * by label: the Dashboard and the manual belong to Home while the Reports hub is its own domain.
     */
    'navigation_items' => [
        // Ours since reports-expansion-plan.md Phase 5.1, which replaced Filament's in the panel. Keyed on
        // the class, so the mapping has to follow the swap or the dashboard falls out of the Home domain and
        // into Filament's unlabelled group.
        \App\Modules\Core\Filament\Pages\Dashboard::class => 'home',
        \App\Modules\Core\Filament\Pages\UserManual::class => 'home',
        \App\Modules\Core\Filament\Pages\Reports::class => 'reports',
    ],
];
