<?php

namespace App\Modules\Core\Filament\Widgets;

use App\Filament\Concerns\WidgetBelongsToModule;
use App\Support\DashboardSummaries;
use App\Support\Reporting\DashboardWidgets;
use Filament\Widgets\Widget;

/**
 * The row of large figures at the top of the Material dashboard — the "M3 · Dashboard (home)" design.
 *
 * Each figure is a module's: Work registers open work items, Leave who is off today, Invoicing the overdue
 * invoices, Accounting the cash in bank (App\Support\DashboardSummaries). This widget only draws them, which
 * is why it lives in Core and names no module.
 *
 * **Lazy, and asked whether to exist without a query.** The dashboard decides which widgets to mount on the
 * request a person waits for, and PanelPerformanceTest holds that request to its budget; `canView()` is
 * therefore a licence-and-permission check only, and the four aggregates run on the widget's own follow-up
 * request once the page is on screen.
 */
class DashboardSummaryCards extends Widget
{
    use WidgetBelongsToModule;

    protected string $view = 'filament.widgets.dashboard-summary-cards';

    protected int|string|array $columnSpan = 'full';

    /** First on the page: above the headline stats (+3), in the headline band like everything that leads. */
    protected static ?int $sort = DashboardWidgets::HEADLINE;

    protected static bool $isLazy = true;

    protected ?string $pollingInterval = null;

    public ?string $periodFrom = null;

    public ?string $periodTo = null;

    public static function canView(): bool
    {
        return static::moduleIsAvailable() && DashboardSummaries::anyVisible();
    }

    /** @return array<int, array{key: string, label: string, value: string, link: ?string, url: ?string, tone: string}> */
    public function cards(): array
    {
        return DashboardSummaries::resolve();
    }
}
