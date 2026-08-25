<?php

namespace App\Filament\Concerns;

use App\Support\Reporting\DashboardArrangement;

/**
 * A widget wide enough for what it draws — `docs/reports-expansion-plan.md` Phase 7, item 5.
 *
 * "**Widths, not resizing:** one of half / two-thirds / full per widget, mapped to `$columnSpan`."
 *
 * **Why a public property rather than a setter.** Filament hands a widget its configuration as Livewire mount
 * properties (`Page::getWidgetsSchemaComponents()` spreads `WidgetConfiguration::getProperties()` into them),
 * which is the same road Phase 5.1's period travels. So the width has to arrive as a property, and Livewire
 * only assigns public ones. `$columnSpan` itself is protected in Filament's `Widget`, so it cannot be the one.
 *
 * **Composed into `WidgetBelongsToModule` rather than added to twenty-three widgets.** Every widget in this
 * application already uses that trait and `DashboardWidgetRulesTest` requires it of every new one, so
 * composing here means a widget cannot be added without the ability to be widened — where a second trait would
 * be a second thing to remember, with no second decision behind it. The two concerns are related more closely
 * than the names suggest: both are about a widget's place on a dashboard it does not own.
 *
 * **The fallback is the widget's own `$columnSpan`, and it is load-bearing.** A widget rendered anywhere but
 * the arranged dashboard — a header widget on a resource page, or one mounted directly in a test — gets no
 * width and must keep the span it declares, which is written for that page's two-column grid rather than the
 * dashboard's six. Returning a dashboard width there would make every such widget a sixth of a page wide.
 */
trait HasDashboardSpan
{
    /**
     * How wide the dashboard is showing this widget: one of `DashboardArrangement::WIDTHS`, or null.
     *
     * Null means "nobody said", which is not the same as any of the three widths — see the fallback below.
     */
    public ?string $dashboardSpan = null;

    /**
     * The span, resolved.
     *
     * A trait method beats an inherited one, so this wins over `Filament\Widgets\Widget::getColumnSpan()`
     * without any widget having to override anything — and `parent::` still reaches the method it replaced,
     * which is what makes the fallback above possible.
     *
     * @return int|string|array<string, int|null>
     */
    public function getColumnSpan(): int|string|array
    {
        if (! DashboardArrangement::isWidth($this->dashboardSpan)) {
            return parent::getColumnSpan();
        }

        return DashboardArrangement::span($this->dashboardSpan);
    }
}
