<?php

namespace App\Modules\ConstructionContracts;

use Filament\Contracts\Plugin;
use Filament\Panel;

/**
 * Registered unconditionally, whatever the company has licensed — plugin registration and resource-route
 * generation happen at boot, while the company is resolved per request. See MprPlugin for the full reasoning.
 */
class ConstructionContractsPlugin implements Plugin
{
    public function getId(): string
    {
        return 'construction-contracts';
    }

    public function register(Panel $panel): void
    {
        $panel->discoverResources(
            in: __DIR__.'/Filament/Resources',
            for: __NAMESPACE__.'\Filament\Resources',
        );
        $panel->discoverPages(
            in: __DIR__.'/Filament/Pages',
            for: __NAMESPACE__.'\Filament\Pages',
        );
    }

    public function boot(Panel $panel): void
    {
        //
    }
}
