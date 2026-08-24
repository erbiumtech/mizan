<?php

namespace App\Modules\Projects;

use Filament\Contracts\Plugin;
use Filament\Panel;

/**
 * Registered unconditionally, whatever the company has licensed — plugin
 * registration and resource-route generation happen at boot, while the company is
 * resolved per request. See MprPlugin for the full reasoning.
 */
class ProjectsPlugin implements Plugin
{
    public function getId(): string
    {
        return 'projects';
    }

    public function register(Panel $panel): void
    {
        $panel->discoverResources(
            in: __DIR__.'/Filament/Resources',
            for: __NAMESPACE__.'\Filament\Resources',
        );
        $panel->discoverWidgets(
            in: __DIR__.'/Filament/Widgets',
            for: __NAMESPACE__.'\Filament\Widgets',
        );

        // The environment health report (reports-expansion-plan.md Phase 3.13). Hidden from the sidebar and
        // reached from the Reports hub, but still registered or its URL does not exist.
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
