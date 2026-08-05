<?php

namespace App\Modules\Core;

use Filament\Contracts\Plugin;
use Filament\Panel;

/**
 * What the platform panel carries: the installation, not a company.
 *
 * A separate plugin from CorePlugin, registered only by PlatformPanelProvider, because
 * the two panels need different things from Core and nothing should be registered on
 * both. Core's own directories keep serving the company panel; this one serves the panel
 * that has no company.
 *
 * Not a module in config/modules.php, deliberately. A module is something a company
 * buys, and there is no company here to buy one — a "platform" licence would be a
 * category error. Everything registered below belongs to Core, which is locked and always
 * available, and is mapped as such in ModuleMap.
 *
 * The one rule for anything added here: its model must live in the landlord database.
 * There is no tenant on this panel, so there is no tenant connection, and a resource over
 * a tenant model would fail on its first query. PlatformPanelIsLandlordOnlyTest asserts
 * it rather than trusting it.
 */
class CorePlatformPlugin implements Plugin
{
    public function getId(): string
    {
        return 'core-platform';
    }

    public function register(Panel $panel): void
    {
        $panel->discoverResources(
            in: __DIR__.'/Filament/Platform/Resources',
            for: __NAMESPACE__.'\Filament\Platform\Resources',
        );
        $panel->discoverPages(
            in: __DIR__.'/Filament/Platform/Pages',
            for: __NAMESPACE__.'\Filament\Platform\Pages',
        );
    }

    public function boot(Panel $panel): void
    {
        //
    }
}
