<?php

namespace App\Modules\PersonalFinance;

use Filament\Contracts\Plugin;
use Filament\Panel;

/**
 * Registered unconditionally, whatever the company has licensed — see MprPlugin
 * for why licence state cannot gate panel registration.
 */
class PersonalFinancePlugin implements Plugin
{
    public function getId(): string
    {
        return 'personal_finance';
    }

    public function register(Panel $panel): void
    {
        $panel->discoverResources(
            in: __DIR__.'/Filament/Resources',
            for: __NAMESPACE__.'\Filament\Resources',
        );

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
