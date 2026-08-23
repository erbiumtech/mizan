<?php

/**
 * What this module is, and what it owns.
 *
 * Requires Employees. Guarded on `payroll`, `leave` and `accounting`: the final
 * settlement reads encashable leave and an advance balance when those exist, and
 * links issued kit to the fixed-asset register when the books are kept here. Each
 * one missing makes the settlement a smaller document, not a broken one.
 *
 * Merged by App\Support\ModuleManifest. See docs/module-packaging-plan.md §5.
 */
return [
    'key' => 'lifecycle',
    'label' => 'Joining & Leaving',
    'description' => 'Onboarding and exit checklists, documents that expire, issued assets, and the final settlement.',
    'requires' => [
        'employees',
    ],
    'licensed_by_default' => false,
    'plugin' => \App\Modules\Lifecycle\LifecyclePlugin::class,

    'models' => [
        'App\\Models\\ChecklistTemplate' => \App\Modules\Lifecycle\Models\ChecklistTemplate::class,
        'App\\Models\\ChecklistItem' => \App\Modules\Lifecycle\Models\ChecklistItem::class,
        'App\\Models\\EmployeeChecklist' => \App\Modules\Lifecycle\Models\EmployeeChecklist::class,
        'App\\Models\\EmployeeChecklistItem' => \App\Modules\Lifecycle\Models\EmployeeChecklistItem::class,
        'App\\Models\\EmployeeDocument' => \App\Modules\Lifecycle\Models\EmployeeDocument::class,
        'App\\Models\\IssuedAsset' => \App\Modules\Lifecycle\Models\IssuedAsset::class,
        'App\\Models\\FinalSettlement' => \App\Modules\Lifecycle\Models\FinalSettlement::class,
    ],

    'resources' => [
        'App\\Filament\\Resources\\ChecklistTemplates\\ChecklistTemplateResource' => \App\Modules\Lifecycle\Filament\Resources\ChecklistTemplates\ChecklistTemplateResource::class,
        'App\\Filament\\Resources\\EmployeeDocuments\\EmployeeDocumentResource' => \App\Modules\Lifecycle\Filament\Resources\EmployeeDocuments\EmployeeDocumentResource::class,
        'App\\Filament\\Resources\\IssuedAssets\\IssuedAssetResource' => \App\Modules\Lifecycle\Filament\Resources\IssuedAssets\IssuedAssetResource::class,
        'App\\Filament\\Resources\\FinalSettlements\\FinalSettlementResource' => \App\Modules\Lifecycle\Filament\Resources\FinalSettlements\FinalSettlementResource::class,
    ],

    /** The two reports. Pages, not resources: a report is a question, not rows to edit. */
    'pages' => [
        'App\\Filament\\Pages\\DocumentsExpiring' => \App\Modules\Lifecycle\Filament\Pages\DocumentsExpiring::class,
        'App\\Filament\\Pages\\LeaveLiability' => \App\Modules\Lifecycle\Filament\Pages\LeaveLiability::class,
    ],

    'permission_groups' => [
        'Checklist',
        'EmployeeDocument',
        'IssuedAsset',
        'Settlement',
    ],

    'permissions' => [
        // Joining and leaving. Documents get their own group because a passport
        // scan is not the same sensitivity as an onboarding tick-box, and the
        // settlement gets one because it is money.
        ['name' => 'ChecklistView', 'group' => 'Checklist'],
        ['name' => 'ChecklistCreate', 'group' => 'Checklist'],
        ['name' => 'ChecklistUpdate', 'group' => 'Checklist'],
        ['name' => 'ChecklistDelete', 'group' => 'Checklist'],
        ['name' => 'EmployeeDocumentView', 'group' => 'EmployeeDocument'],
        ['name' => 'EmployeeDocumentCreate', 'group' => 'EmployeeDocument'],
        ['name' => 'EmployeeDocumentUpdate', 'group' => 'EmployeeDocument'],
        ['name' => 'EmployeeDocumentDelete', 'group' => 'EmployeeDocument'],
        ['name' => 'IssuedAssetView', 'group' => 'IssuedAsset'],
        ['name' => 'IssuedAssetCreate', 'group' => 'IssuedAsset'],
        ['name' => 'IssuedAssetUpdate', 'group' => 'IssuedAsset'],
        ['name' => 'IssuedAssetDelete', 'group' => 'IssuedAsset'],
        ['name' => 'SettlementView', 'group' => 'Settlement'],
        ['name' => 'SettlementCreate', 'group' => 'Settlement'],
        ['name' => 'SettlementUpdate', 'group' => 'Settlement'],
        ['name' => 'SettlementApprove', 'group' => 'Settlement'],
        ['name' => 'SettlementDelete', 'group' => 'Settlement'],
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
            'IssuedAssetView',
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
        // The report declares the Reports group, as every report page in this application does.
        'Reports' => 'reports',
    ],
];
