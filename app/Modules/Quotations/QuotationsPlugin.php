<?php

namespace App\Modules\Quotations;

use Filament\Contracts\Plugin;
use Filament\Panel;

/** Registered unconditionally; gating is per resource via BelongsToModule. */
class QuotationsPlugin implements Plugin
{
    public function getId(): string
    {
        return 'quotations';
    }

    public function register(Panel $panel): void
    {
        $panel->discoverResources(
            in: __DIR__.'/Filament/Resources',
            for: __NAMESPACE__.'\Filament\Resources',
        );

        // The expiring-quotations list (Phase 5.3). Registered unconditionally; each widget's own canView() gates on the module and a permission.
        $panel->discoverWidgets(
            in: __DIR__.'/Filament/Widgets',
            for: __NAMESPACE__.'\Filament\Widgets',
        );

        // The conversion report (reports-expansion-plan.md Phase 3.3). Hidden from the sidebar and reached
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
