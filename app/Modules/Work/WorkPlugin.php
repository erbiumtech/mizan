<?php

namespace App\Modules\Work;

use Filament\Contracts\Plugin;
use Filament\Panel;

/**
 * Registered unconditionally; gating is per resource via BelongsToModule.
 *
 * Nothing is discovered yet — Phase 0 is the module, the rail and the permissions. The directories
 * below arrive with the work item in Phase 1, and `discoverResources` on a directory that does not
 * exist is a fatal rather than an empty list, so they are added when they have something in them.
 */
class WorkPlugin implements Plugin
{
    public function getId(): string
    {
        return 'work';
    }

    public function register(Panel $panel): void
    {
        //
    }

    public function boot(Panel $panel): void
    {
        //
    }
}
