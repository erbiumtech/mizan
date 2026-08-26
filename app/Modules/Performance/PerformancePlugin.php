<?php

namespace App\Modules\Performance;

use Filament\Contracts\Plugin;
use Filament\Panel;

/** Registered unconditionally; gating is per resource via BelongsToModule. */
class PerformancePlugin implements Plugin
{
    public function getId(): string
    {
        return 'performance';
    }

    public function register(Panel $panel): void
    {
        $panel->discoverResources(
            in: __DIR__.'/Filament/Resources',
            for: __NAMESPACE__.'\Filament\Resources',
        );

        // The review-cycle progress report (reports-expansion-plan.md Phase 3.12). Hidden from the sidebar
        // and reached from the Reports hub, but still registered or its URL does not exist.
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
