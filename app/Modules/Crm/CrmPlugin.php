<?php

namespace App\Modules\Crm;

use Filament\Contracts\Plugin;
use Filament\Panel;

/**
 * Registered unconditionally, whatever the company has licensed — see MprPlugin for
 * why licence state cannot gate panel registration. Gating is per resource, via
 * BelongsToModule.
 */
class CrmPlugin implements Plugin
{
    public function getId(): string
    {
        return 'crm';
    }

    public function register(Panel $panel): void
    {
        $panel->discoverResources(
            in: __DIR__.'/Filament/Resources',
            for: __NAMESPACE__.'\Filament\Resources',
        );

        // The five pipeline reports (reports-expansion-plan.md Phase 1.2). Hidden from the sidebar and
        // reached from the Reports hub, but they still have to be registered with the panel or their URLs
        // do not exist and the hub links nowhere.
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
