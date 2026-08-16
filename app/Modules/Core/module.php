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
        'App\\Models\\CustomField' => \App\Modules\Core\Models\CustomField::class,
        'App\\Models\\CustomFieldValue' => \App\Modules\Core\Models\CustomFieldValue::class,
        'App\\Models\\ActivityLog' => \App\Modules\Core\Models\ActivityLog::class,
        'App\\Models\\Comment' => \App\Modules\Core\Models\Comment::class,
        'App\\Models\\FiscalYear' => \App\Modules\Core\Models\FiscalYear::class,
        'App\\Models\\Holiday' => \App\Modules\Core\Models\Holiday::class,
        // The IBFT bank list. Core for the same reason Holiday is — see the model.
        'App\\Models\\Bank' => \App\Modules\Core\Models\Bank::class,
        'App\\Models\\Setting' => \App\Modules\Core\Models\Setting::class,
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
        'App\\Filament\\Resources\\Holidays\\HolidayResource' => \App\Modules\Core\Filament\Resources\Holidays\HolidayResource::class,
    ],

    'pages' => [
        'App\\Filament\\Pages\\Reports' => \App\Modules\Core\Filament\Pages\Reports::class,
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
];
