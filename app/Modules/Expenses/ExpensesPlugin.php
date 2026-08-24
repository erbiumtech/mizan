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

        // The claims report (reports-expansion-plan.md Phase 2.8). Hidden from the sidebar and reached from
        // the Reports hub, but still registered or its URL does not exist.
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
