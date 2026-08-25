<?php

/**
 * What this module is, and what it owns.
 *
 * Merged by App\Support\ModuleManifest. See docs/module-packaging-plan.md §5.
 */
return [
    'key' => 'inventory',
    'label' => 'Inventory',
    'description' => 'Products and stock movements, valued through Accounting.',
    'requires' => [
        'accounting',
    ],
    'licensed_by_default' => false,
    'plugin' => \App\Modules\Inventory\InventoryPlugin::class,

    'models' => [
        'App\\Models\\Product' => \App\Modules\Inventory\Models\Product::class,
        'App\\Models\\StockMovement' => \App\Modules\Inventory\Models\StockMovement::class,
        // Added in construction Phase 8a, and owned here on purpose: §6 of the construction plan and §2.1 of the
        // retail plan both needed a location for stock, and putting it in either of those modules would have made the
        // other depend on it. `construction_jobs.stock_location_id` and (later) `stores.stock_location_id` point here.
        'App\\Models\\StockLocation' => \App\Modules\Inventory\Models\StockLocation::class,
    ],

    'resources' => [
        'App\\Filament\\Resources\\Products\\ProductResource' => \App\Modules\Inventory\Filament\Resources\Products\ProductResource::class,
        'App\\Filament\\Resources\\StockMovements\\StockMovementResource' => \App\Modules\Inventory\Filament\Resources\StockMovements\StockMovementResource::class,
        'App\\Filament\\Resources\\StockLocations\\StockLocationResource' => \App\Modules\Inventory\Filament\Resources\StockLocations\StockLocationResource::class,
    ],

    /** The stocktake report (reports-expansion-plan.md Phase 2.4). A page: a report is a question. */
    'pages' => [
        'App\\Filament\\Pages\\StockOnHand' => \App\Modules\Inventory\Filament\Pages\StockOnHand::class,
    ],

    'widgets' => [
        'App\\Filament\\Widgets\\StockOnHandOverview' => \App\Modules\Inventory\Filament\Widgets\StockOnHandOverview::class,
    ],

    // What the report builder may report on — reports-expansion-plan.md Phase 6, item 1. A dataset is
    // declared by the module that owns the subject, so it arrives with a module to gate on.
    'datasets' => [
        'App\\Reporting\\StockMovementDataset' => \App\Modules\Inventory\Reporting\StockMovementDataset::class,
    ],

    'permission_groups' => [
        'Inventory',
    ],

    'permissions' => [
        ['name' => 'ProductView', 'group' => 'Inventory'],
        ['name' => 'ProductCreate', 'group' => 'Inventory'],
        ['name' => 'ProductUpdate', 'group' => 'Inventory'],
        ['name' => 'ProductDelete', 'group' => 'Inventory'],
        ['name' => 'StockMove', 'group' => 'Inventory'],
        ['name' => 'StockAdjust', 'group' => 'Inventory'],
    ],

    /**
     * Which of this module's permissions each role starts with.
     *
     * Administrator holds everything and is not listed. Manager and CEO are *additions* to the
     * role below them — RoleSeeder composes Accountant -> Manager -> CEO — so a permission
     * already granted to Accountant is not repeated here.
     */
    'role_grants' => [
        // Records, does not approve.
        'Accountant' => [
            'ProductCreate',
            'ProductUpdate',
            'ProductView',
            'StockMove',
        ],
        // On top of Accountant.
        'Manager' => [
            'StockAdjust',
        ],
        // On top of Manager.
        'CEO' => [
            'ProductDelete',
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
        // The report declares the Reports group, as every report page in this application does.
        'Reports' => 'reports',
        'Invoicing & Inventory' => 'finance',
    ],
];
