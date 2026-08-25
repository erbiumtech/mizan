<?php

namespace App\Modules\Attendance;

use Filament\Contracts\Plugin;
use Filament\Panel;

/**
 * Registered unconditionally — one deployment serves every company, plugins register
 * at boot, the tenant is resolved per request. Gating is per resource, via
 * BelongsToModule.
 */
class AttendancePlugin implements Plugin
{
    public function getId(): string
    {
        return 'attendance';
    }

    public function register(Panel $panel): void
    {
        $panel->discoverResources(
            in: __DIR__.'/Filament/Resources',
            for: __NAMESPACE__.'\Filament\Resources',
        );

        // The present/late/on-leave stats (Phase 5.2). Registered unconditionally; each widget's own canView() gates on the module and a permission.
        $panel->discoverWidgets(
            in: __DIR__.'/Filament/Widgets',
            for: __NAMESPACE__.'\Filament\Widgets',
        );

        // The monthly register (reports-expansion-plan.md Phase 3.1). Hidden from the sidebar and reached
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
