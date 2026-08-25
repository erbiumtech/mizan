<?php

namespace App\Filament\Concerns;

use App\Support\ModuleMap;
use RuntimeException;

/**
 * The widget counterpart of BelongsToModule: widgets gate on canView(), not
 * canAccess(), so they need their own hook or a disabled module's charts keep
 * rendering on the dashboard.
 *
 * Every widget in this app already defines canView(), which shadows the one
 * below — so each calls moduleIsAvailable() itself and ModuleGatingTest checks
 * the behaviour rather than the trait.
 */
trait WidgetBelongsToModule
{
    /**
     * A widget's width on the dashboard — reports-expansion-plan.md Phase 7, item 5.
     *
     * Composed here rather than added to each widget because every widget already uses this trait and
     * DashboardWidgetRulesTest requires it of every new one, so a widget cannot be added without the
     * ability to be widened. See HasDashboardSpan for why the width has to be a public property.
     */
    use HasDashboardSpan;

    public static function module(): string
    {
        $module = ModuleMap::moduleFor(static::class);

        if ($module === null) {
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

    public static function canView(): bool
    {
        return static::moduleIsAvailable() && parent::canView();
    }
}
