<?php

namespace App\Modules\Timesheets;

use Filament\Contracts\Plugin;
use Filament\Panel;

/** Registered unconditionally; gating is per resource via BelongsToModule. */
class TimesheetsPlugin implements Plugin
{
    public function getId(): string
    {
        return 'timesheets';
    }

    public function register(Panel $panel): void
    {
        $panel->discoverResources(
            in: __DIR__.'/Filament/Resources',
            for: __NAMESPACE__.'\Filament\Resources',
        );

        // The billable-share and unbilled-WIP stats (Phase 5.4). Registered unconditionally; each widget's own canView() gates on the module and a permission.
        $panel->discoverWidgets(
            in: __DIR__.'/Filament/Widgets',
            for: __NAMESPACE__.'\Filament\Widgets',
        );

        // The utilisation report and the plan-versus-actual matrix (reports-expansion-plan.md Phase 1.4).
        // Hidden from the sidebar and reached from the Reports hub, but still registered or their URLs do
        // not exist.
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
