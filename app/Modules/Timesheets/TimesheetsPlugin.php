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
