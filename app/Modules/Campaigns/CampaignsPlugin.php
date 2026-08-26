<?php

namespace App\Modules\Campaigns;

use Filament\Contracts\Plugin;
use Filament\Panel;

/** Registered unconditionally; gating is per resource via BelongsToModule. */
class CampaignsPlugin implements Plugin
{
    public function getId(): string
    {
        return 'campaigns';
    }

    public function register(Panel $panel): void
    {
        $panel->discoverResources(
            in: __DIR__.'/Filament/Resources',
            for: __NAMESPACE__.'\Filament\Resources',
        );

        // The consent register (reports-expansion-plan.md Phase 3.10). Hidden from the sidebar and reached
        // from the Reports hub, but still registered or its URL does not exist.
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
