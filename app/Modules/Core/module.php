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
        'App\\Models\\Signup' => \App\Modules\Core\Models\Signup::class,
        // How one person, or the company, has arranged the dashboard — Phase 7 of the reports plan.
        'App\\Models\\DashboardLayout' => \App\Modules\Core\Models\DashboardLayout::class,
        'App\\Models\\CustomField' => \App\Modules\Core\Models\CustomField::class,
        'App\\Models\\CustomFieldValue' => \App\Modules\Core\Models\CustomFieldValue::class,
        'App\\Models\\ActivityLog' => \App\Modules\Core\Models\ActivityLog::class,
        // Every mail and WhatsApp message the application sent — docs/saas-launch-plan.md §14.7. Core
        // because every module sends something and none of them owns the question "did it go".
        'App\\Models\\MessageLog' => \App\Modules\Core\Models\MessageLog::class,
        // Outgoing webhooks — docs/saas-launch-plan.md §14.9. Core for the reason MessageLog is:
        // every module fires events and none of them owns "where does this company listen".
        'App\\Models\\WebhookEndpoint' => \App\Modules\Core\Models\WebhookEndpoint::class,
        'App\\Models\\WebhookDelivery' => \App\Modules\Core\Models\WebhookDelivery::class,
        // A person's push subscription — docs/desktop-mobile-plan.md A3. Core because it belongs to the
        // user, who belongs to no module.
        'App\\Models\\DeviceToken' => \App\Modules\Core\Models\DeviceToken::class,
        // The sync inbox's record of every command a device sent — docs/desktop-mobile-plan.md §5a. Core
        // because the engine is one for every workflow; the workflows belong to their modules.
        'App\\Models\\SyncCommand' => \App\Modules\Core\Models\SyncCommand::class,
        'App\\Models\\Comment' => \App\Modules\Core\Models\Comment::class,
        'App\\Models\\FiscalYear' => \App\Modules\Core\Models\FiscalYear::class,
        'App\\Models\\Holiday' => \App\Modules\Core\Models\Holiday::class,
        // Branches — docs/saas-launch-plan.md §7.9. Core for the reason Holiday is.
        'App\\Models\\WorkLocation' => \App\Modules\Core\Models\WorkLocation::class,
        // The IBFT bank list. Core for the same reason Holiday is — see the model.
        'App\\Models\\Bank' => \App\Modules\Core\Models\Bank::class,
        'App\\Models\\Setting' => \App\Modules\Core\Models\Setting::class,
        // One entry in one admin-managed dropdown. Core because the table serves every
        // module's lists and the screen that edits them sits with Company Settings.
        'App\\Models\\OptionValue' => \App\Modules\Core\Models\OptionValue::class,
        // The public site's content — docs/company-website-plan.md. Landlord rows, edited on the
        // platform panel: a page, a setting, a message from the contact form.
        'App\\Models\\SitePage' => \App\Modules\Core\Models\SitePage::class,
        'App\\Models\\SiteSetting' => \App\Modules\Core\Models\SiteSetting::class,
        'App\\Models\\ContactMessage' => \App\Modules\Core\Models\ContactMessage::class,
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
        'App\\Filament\\Resources\\MessageLogs\\MessageLogResource' => \App\Modules\Core\Filament\Resources\MessageLogs\MessageLogResource::class,
        'App\\Filament\\Resources\\WebhookEndpoints\\WebhookEndpointResource' => \App\Modules\Core\Filament\Resources\WebhookEndpoints\WebhookEndpointResource::class,
        'App\\Filament\\Resources\\WebhookDeliveries\\WebhookDeliveryResource' => \App\Modules\Core\Filament\Resources\WebhookDeliveries\WebhookDeliveryResource::class,
        'App\\Filament\\Resources\\Comments\\CommentResource' => \App\Modules\Core\Filament\Resources\Comments\CommentResource::class,
        'App\\Filament\\Resources\\FiscalYears\\FiscalYearResource' => \App\Modules\Core\Filament\Resources\FiscalYears\FiscalYearResource::class,
        // Scheduled reports — Phase 8, item 1 of the reports plan.
        'App\\Filament\\Resources\\ReportSchedules\\ReportScheduleResource' => \App\Modules\Core\Filament\Resources\ReportSchedules\ReportScheduleResource::class,
        'App\\Filament\\Resources\\Holidays\\HolidayResource' => \App\Modules\Core\Filament\Resources\Holidays\HolidayResource::class,
        'App\\Filament\\Resources\\Locations\\WorkLocationResource' => \App\Modules\Core\Filament\Resources\WorkLocations\WorkLocationResource::class,
        'App\\Filament\\Resources\\OptionValues\\OptionValueResource' => \App\Modules\Core\Filament\Resources\OptionValues\OptionValueResource::class,
        // The public site, on the platform panel — docs/company-website-plan.md W1 and W4.
        'App\\Filament\\Platform\\Resources\\SitePages\\SitePageResource' => \App\Modules\Core\Filament\Platform\Resources\SitePages\SitePageResource::class,
        'App\\Filament\\Platform\\Resources\\ContactMessages\\ContactMessageResource' => \App\Modules\Core\Filament\Platform\Resources\ContactMessages\ContactMessageResource::class,
    ],

    'widgets' => [
        // Belongs to no module and every module contributes to it — see App\\Support\\DashboardStats.
        'App\\Filament\\Widgets\\OperationsOverview' => \App\Modules\Core\Filament\Widgets\OperationsOverview::class,
        // Installation health, super-admin-only — the dashboard glance at /ops/health.
        'App\\Filament\\Widgets\\HealthOverview' => \App\Modules\Core\Filament\Widgets\HealthOverview::class,
        // The Material dashboard's summary row and approvals card. Each module registers its figures and
        // its approval source (App\\Support\\DashboardSummaries, DashboardApprovals); these only draw.
        'App\\Filament\\Widgets\\DashboardSummaryCards' => \App\Modules\Core\Filament\Widgets\DashboardSummaryCards::class,
        'App\\Filament\\Widgets\\WaitingForYou' => \App\Modules\Core\Filament\Widgets\WaitingForYou::class,
    ],

    'pages' => [
        // The year's holidays as grids — docs/saas-launch-plan.md §14.4.
        'App\\Filament\\Pages\\HolidayCalendarPage' => \App\Modules\Core\Filament\Pages\HolidayCalendarPage::class,
        // The dashboard. Core for the reason the Reports hub is: it belongs to no module and every module
        // contributes widgets to it, so each widget's own canView() is the only thing deciding what appears.
        'App\\Filament\\Pages\\Dashboard' => \App\Modules\Core\Filament\Pages\Dashboard::class,
        'App\\Filament\\Pages\\Reports' => \App\Modules\Core\Filament\Pages\Reports::class,
        // The delivery log — Phase 8, item 8 of the reports plan. A report about the application rather than
        // about the business, which is why it is here and not in a module.
        'App\\Filament\\Pages\\ReportDeliveries' => \App\Modules\Core\Filament\Pages\ReportDeliveries::class,
        'App\\Filament\\Pages\\UserManual' => \App\Modules\Core\Filament\Pages\UserManual::class,
        // Each person's own API keys — docs/saas-launch-plan.md §14.9.
        'App\\Filament\\Pages\\ApiKeys' => \App\Modules\Core\Filament\Pages\ApiKeys::class,
        // What the product does about each law it touches — docs/saas-launch-plan.md §14.10.
        'App\\Filament\\Pages\\StatutoryCoverage' => \App\Modules\Core\Filament\Pages\StatutoryCoverage::class,
        'App\\Filament\\Pages\\CompanySettings' => \App\Modules\Core\Filament\Pages\CompanySettings::class,
        // What the company pays for — docs/saas-launch-plan.md §6. Landlord columns on a tenant page.
        'App\\Filament\\Pages\\Subscription' => \App\Modules\Core\Filament\Pages\Subscription::class,
        'App\\Filament\\Pages\\Modules' => \App\Modules\Core\Filament\Pages\Modules::class,
        'App\\Filament\\Pages\\CsvImport' => \App\Modules\Core\Filament\Pages\CsvImport::class,
        'App\\Filament\\Pages\\Auth\\EditProfile' => \App\Modules\Core\Filament\Pages\Auth\EditProfile::class,
    ],

    'permission_groups' => [
        'User',
        'Role',
        'Permission',
        'ActivityLog',
        'MessageLog',
        'Webhook',
        'Comment',
        'FiscalYear',
        'Holiday',
        'WorkLocation',
        'TableView',
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
        // Branches — plan §7.9.
        ['name' => 'WorkLocationView', 'group' => 'WorkLocation'],
        ['name' => 'WorkLocationCreate', 'group' => 'WorkLocation'],
        ['name' => 'WorkLocationUpdate', 'group' => 'WorkLocation'],
        ['name' => 'WorkLocationDelete', 'group' => 'WorkLocation'],
        ['name' => 'ActivityLogView', 'group' => 'ActivityLog'],
        // Who the application wrote to, and whether it arrived — §14.7. Administrator only by default:
        // the log is a list of everybody's email addresses and phone numbers in one screen.
        ['name' => 'MessageLogView', 'group' => 'MessageLog'],
        // Where this company's records are sent, and what went — §14.9. Administrator only by
        // default: an endpoint is a standing instruction to post payroll events to an address.
        ['name' => 'WebhookManage', 'group' => 'Webhook'],
        // Share a saved table view with the whole company (TableViewPolicy::publish).
        // Administrator holds it with everything else; no other seeded role lists it.
        ['name' => 'TableViewPublish', 'group' => 'TableView'],
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
