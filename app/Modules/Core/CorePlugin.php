<?php

namespace App\Modules\Core;

use Filament\Contracts\Plugin;
use Filament\Panel;

/**
 * Registered unconditionally, whatever the company has licensed — plugin
 * registration and resource-route generation happen at boot, while the company is
 * resolved per request. See MprPlugin for the full reasoning.
 */
class CorePlugin implements Plugin
{
    public function getId(): string
    {
        return 'core';
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
