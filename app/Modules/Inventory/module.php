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
    ],

    'resources' => [
        'App\\Filament\\Resources\\Products\\ProductResource' => \App\Modules\Inventory\Filament\Resources\Products\ProductResource::class,
        'App\\Filament\\Resources\\StockMovements\\StockMovementResource' => \App\Modules\Inventory\Filament\Resources\StockMovements\StockMovementResource::class,
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
        'Invoicing & Inventory' => 'finance',
    ],
];
