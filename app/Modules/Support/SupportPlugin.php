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
    }

    public function boot(Panel $panel): void
    {
        //
    }
}
