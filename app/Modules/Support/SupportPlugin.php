<?php

namespace App\Modules\Support;

use Filament\Contracts\Plugin;
use Filament\Panel;

/** Registered unconditionally; gating is per resource via BelongsToModule. */
class SupportPlugin implements Plugin
{
    public function getId(): string
    {
        return 'support';
    }

    public function register(Panel $panel): void
    {
        $panel->discoverResources(
            in: __DIR__.'/Filament/Resources',
            for: __NAMESPACE__.'\Filament\Resources',
        );

        // The SLA compliance stats (Phase 5.4). Registered unconditionally; each widget's own canView() gates on the module and a permission.
        $panel->discoverWidgets(
            in: __DIR__.'/Filament/Widgets',
            for: __NAMESPACE__.'\Filament\Widgets',
        );

        // The two SLA reports (reports-expansion-plan.md Phase 1.3). Hidden from the sidebar and reached
        // from the Reports hub, but they still need registering or their URLs do not exist.
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
