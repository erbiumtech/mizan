<?php

namespace App\Modules\Mpr;

use Filament\Contracts\Plugin;
use Filament\Panel;

/**
 * Registers the module's Filament classes with the panel.
 *
 * Registration is UNCONDITIONAL, deliberately. It would be tempting to skip
 * registering a plugin whose module is not licensed, but plugins are registered
 * at boot and resource routes are generated then, while the company is resolved
 * per request from the {tenant} route segment — one deployment serves every
 * company, and `route:cache` would bake in whichever state existed when the
 * cache was built. Access is gated by canAccess()/canView() and by the route
 * middleware; the directory layout is organisation, not enforcement.
 */
class MprPlugin implements Plugin
{
    public function getId(): string
    {
        return 'mpr';
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
