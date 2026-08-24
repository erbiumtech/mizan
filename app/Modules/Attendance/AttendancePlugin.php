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
