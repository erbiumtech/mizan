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
];
