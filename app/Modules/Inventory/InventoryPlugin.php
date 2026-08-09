<?php

namespace App\Modules\Inventory;

use Filament\Contracts\Plugin;
use Filament\Panel;

/**
 * Registered unconditionally — see MprPlugin for why licence state cannot gate
 * panel registration.
 */
class InventoryPlugin implements Plugin
{
    public function getId(): string
    {
        return 'inventory';
    }

    public function register(Panel $panel): void
    {
        $panel->discoverResources(
            in: __DIR__.'/Filament/Resources',
            for: __NAMESPACE__.'\Filament\Resources',
        );
    }

    public function boot(Panel $panel): void
    {
        //
    }
}
