<?php

namespace App\Modules\Leave;

use Filament\Contracts\Plugin;
use Filament\Panel;

/**
 * Registered unconditionally, whatever the company has licensed — see MprPlugin for
 * why licence state cannot gate panel registration: one deployment serves every
 * company, plugins register at boot, and the tenant is resolved per request.
 *
 * Gating happens per resource, through BelongsToModule.
 */
class LeavePlugin implements Plugin
{
    public function getId(): string
    {
        return 'leave';
    }

    public function register(Panel $panel): void
    {
        $panel->discoverResources(
            in: __DIR__.'/Filament/Resources',
            for: __NAMESPACE__.'\Filament\Resources',
        );

        // The leave-awaiting-decision stats (Phase 5.2). Registered unconditionally; each widget's own canView() gates on the module and a permission.
        $panel->discoverWidgets(
            in: __DIR__.'/Filament/Widgets',
            for: __NAMESPACE__.'\Filament\Widgets',
        );
    }

    public function boot(Panel $panel): void
    {
        //
    }
}
