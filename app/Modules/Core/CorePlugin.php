<?php

namespace App\Modules\Core;

use Filament\Contracts\Plugin;
use Filament\Panel;

/**
 * Registered unconditionally, whatever the company has licensed — plugin
 * registration and resource-route generation happen at boot, while the company is
 * resolved per request. See MprPlugin for the full reasoning.
 */
class CorePlugin implements Plugin
{
    public function getId(): string
    {
        return 'core';
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

        /*
         * **`OperationsOverview` was on no dashboard at all until Phase 5.7 found it.** Core discovered
         * resources and pages but never widgets, so the one widget assembled from every module's contributions
         * — the company's headline figures — was registered nowhere.
         *
         * Worth a comment rather than a silent one-line fix, because of *how* it hid. The old
         * `FilamentWidgetsSmokeTest` named that widget in a hand-written list of five and rendered it
         * directly, so it passed while the panel had never heard of the class. That is exactly the failure
         * `docs/reports-expansion-plan.md` Phase 5.9 describes — and enumerating the panel instead, which 5.9
         * asks for, could not catch it either: an enumeration only sees what is registered. What finds it is
         * comparing the widget *files* against the registered set, in `DashboardWidgetRulesTest`.
         */
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
