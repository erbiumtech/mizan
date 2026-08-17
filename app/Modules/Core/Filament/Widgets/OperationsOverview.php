<?php

namespace App\Modules\Core\Filament\Widgets;

use App\Filament\Concerns\WidgetBelongsToModule;
use App\Support\DashboardStats;
use Filament\Widgets\StatsOverviewWidget;

/**
 * The dashboard's headline figures, whatever modules this company has.
 *
 * It shows the same four stats it always did — active employees, journal entries awaiting approval,
 * unpaid invoices, products at reorder level — but it no longer knows what any of them are. Each module
 * registers its own from its service provider (see App\Support\DashboardStats), so the widget's content
 * is a function of what is installed rather than of a list maintained here.
 *
 * In Core because it belongs to no module and every module may contribute to it — the same reasoning
 * that puts `Bank`, `Holiday` and `FiscalYear` there. It lived in Accounting and imported Employees,
 * Invoicing and Inventory, which is `docs/module-packaging-plan.md` §8's Group A: "host-application code
 * filed inside a module".
 */
class OperationsOverview extends StatsOverviewWidget
{
    use WidgetBelongsToModule;

    protected static bool $isLazy = true;

    protected static ?int $sort = 1;

    /**
     * Visible when anything at all was contributed.
     *
     * Resolving the stats to decide whether to show them means the queries run twice on a dashboard that
     * does display it — which is why every contribution is a permission check first and a query second,
     * and why the ones that aggregate say so in their own comments. The alternative, a second list of
     * "which permissions might contribute", is the coupling this whole change removed.
     */
    public static function canView(): bool
    {
        if (! static::moduleIsAvailable()) {
            return false;
        }

        return DashboardStats::resolve() !== [];
    }

    protected function getStats(): array
    {
        return DashboardStats::resolve();
    }
}
