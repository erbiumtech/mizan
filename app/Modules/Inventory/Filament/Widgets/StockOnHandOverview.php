<?php

namespace App\Modules\Inventory\Filament\Widgets;

use App\Filament\Concerns\WidgetBelongsToModule;
use App\Modules\Inventory\Support\InventoryReports;
use App\Support\Reporting\ReportFigures;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/**
 * What stock is worth, and what needs reordering — `docs/reports-expansion-plan.md` Phase 5.6.
 *
 * "Stock value, count below reorder level, and — once Phase 2.4 exists — the same valuation the report
 * states, from the same service."
 *
 * **Phase 2.4 does exist, so this is that valuation.** `InventoryReports::summary()` reads
 * `InventoryValuationService::valuationForAll()` — the same call behind the Stock on Hand report — and applies
 * the same two rules. Which matters for one of them in particular: a reorder level of nought means there is
 * *no* level rather than a level of nought, because the column defaults to `0` and treating it as a threshold
 * flagged every product that had merely been sold out. That was a real bug in the report, caught by its own
 * tests, and a widget re-deriving the flag would have reproduced it. The rule now lives in one place and both
 * call it.
 *
 * **Stale stock is on the widget beside the reorder count**, because they are opposite problems that look
 * alike in a total: one is stock about to run out and the other is stock nobody has touched in ninety days,
 * and a stock value that is mostly the second is a very different figure from one that is mostly the first.
 *
 * **An as-at, not a span.** Stock on hand is a balance, so the dashboard's period sets the date — reading at a
 * year end gives the valuation that stood there, which is the figure that ties to the accounts.
 */
class StockOnHandOverview extends StatsOverviewWidget
{
    use WidgetBelongsToModule;

    public ?string $periodFrom = null;

    public ?string $periodTo = null;

    /** Inventory band — see RevenueAndExpensesChart for the scheme. */
    protected static ?int $sort = 50;

    protected static bool $isLazy = true;

    public static function canView(): bool
    {
        if (! static::moduleIsAvailable()) {
            return false;
        }

        // `ProductView`, matching the Stock on Hand report's own gate: anybody who may see a product may see
        // what it is worth.
        return (bool) auth()->user()?->can('ProductView');
    }

    /**
     * @return array<int, Stat>
     */
    protected function getStats(): array
    {
        $summary = app(InventoryReports::class)->summary($this->periodTo);

        return [
            Stat::make('Stock value', ReportFigures::money($summary['value']))
                ->description($summary['products'] === 1
                    ? 'one active product'
                    : $summary['products'].' active products')
                ->color('primary'),

            Stat::make('Below reorder level', (string) $summary['below_reorder'])
                // Only products with a level somebody set. A product with none is one nobody wants to be
                // told about, which is the whole point of the extracted rule.
                ->description($summary['below_reorder'] === 0
                    ? 'nothing needs reordering'
                    : 'at or under a level somebody set')
                ->color($summary['below_reorder'] > 0 ? 'warning' : 'gray'),

            Stat::make('Not moved in 90 days', (string) $summary['stale'])
                ->description($summary['stale'] === 0
                    ? 'everything has moved recently'
                    : 'including anything that has never moved')
                ->color($summary['stale'] > 0 ? 'warning' : 'gray'),
        ];
    }
}
