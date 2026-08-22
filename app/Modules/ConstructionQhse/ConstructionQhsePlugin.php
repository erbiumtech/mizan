<?php

namespace App\Modules\ConstructionQhse;

use Filament\Contracts\Plugin;
use Filament\Panel;

/**
 * Registered unconditionally, whatever the company has licensed — plugin registration and resource-route generation
 * happen at boot, while the company is resolved per request. See MprPlugin for the full reasoning.
 */
class ConstructionQhsePlugin implements Plugin
{
    public function getId(): string
    {
        return 'construction-qhse';
    }

    public function register(Panel $panel): void
    {
        $panel->discoverResources(
            in: __DIR__.'/Filament/Resources',
            for: __NAMESPACE__.'\Filament\Resources',
        );
        // §17.6's indicator report is a standalone page: it computes and stores nothing, so it has no resource to hang
        // off. See SafetyIndicatorsReport for why nothing on it is persisted.
        $panel->discoverPages(
            in: __DIR__.'/Filament/Pages',
            for: __NAMESPACE__.'\Filament\Pages',
        );
    }

    public function boot(Panel $panel): void {}
}
