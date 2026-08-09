<?php

namespace App\Modules\Expenses;

use Filament\Contracts\Plugin;
use Filament\Panel;

/**
 * Registered unconditionally, whatever the company has licensed — see MprPlugin
 * for why licence state cannot gate panel registration.
 */
class ExpensesPlugin implements Plugin
{
    public function getId(): string
    {
        return 'expenses';
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
