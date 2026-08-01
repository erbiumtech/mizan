<?php

namespace App\Filament\Concerns;

use App\Support\ModuleMap;
use RuntimeException;

/**
 * Gates a Filament resource or page on its module being available to the current
 * company.
 *
 * `canAccess()` is the one hook worth having: Filament's navigation
 * (HasNavigation, Pages\Page), global search (canGloballySearch) and the ⌘K
 * palette (ResourceProvider/PageProvider) all consult it, so one method closes
 * four surfaces.
 *
 * IMPORTANT — a class that defines its own canAccess() silently shadows the one
 * below, and most pages here do. Those must call moduleIsAvailable() themselves:
 *
 *     public static function canAccess(): bool
 *     {
 *         return static::moduleIsAvailable() && auth()->user()?->can('ReportView');
 *     }
 *
 * ModuleGatingTest asserts the *behaviour* for every resource, page and widget
 * rather than the presence of this trait, precisely because using the trait is
 * not sufficient.
 *
 * This is not the security boundary on its own — a direct URL bypasses
 * canAccess(). Route middleware and the Gate::before deny (phase 2) are.
 */
trait BelongsToModule
{
    /**
     * The module owning this class.
     *
     * Resolved from ModuleMap: by namespace once the class lives in
     * app/Modules/<Module>/, and from the explicit map until then.
     */
    public static function module(): string
    {
        $module = ModuleMap::moduleFor(static::class);

        if ($module === null) {
            // Better a hard failure than a class that quietly answers "available"
            // for every company. ModuleCoverageTest catches this in CI first.
            throw new RuntimeException(
                'No module owns '.static::class.'. Add it to App\Support\ModuleMap.'
            );
        }

        return $module;
    }

    public static function moduleIsAvailable(): bool
    {
        return modules()->enabled(static::module());
    }

    public static function canAccess(): bool
    {
        return static::moduleIsAvailable() && parent::canAccess();
    }
}
