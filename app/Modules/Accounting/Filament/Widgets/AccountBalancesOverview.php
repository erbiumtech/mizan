<?php

namespace App\Modules\Accounting\Filament\Widgets;

use App\Filament\Concerns\WidgetBelongsToModule;
use App\Modules\Accounting\Models\Account;
use App\Support\Reporting\DashboardWidgets;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/**
 * Posted ledger balances for the key accounting buckets — mirrors the four
 * AccountBalance value cards from the Nova dashboard.
 */
class AccountBalancesOverview extends StatsOverviewWidget
{
    use WidgetBelongsToModule;

    protected static bool $isLazy = true;

    /**
     * No polling — `docs/reports-expansion-plan.md` Phase 5.7 asks for it and Filament's default is against
     * it: `CanPoll::$pollingInterval` is `'5s'`, so every widget in this panel was re-running its aggregates
     * every five seconds, per open tab, unasked. On a dashboard of twenty-three widgets that is the cost
     * Phase 5.8's cache exists to avoid, incurred twelve times a minute instead of once a page.
     */
    protected ?string $pollingInterval = null;

    /** The ledger behind the three figures above it. */
    protected static ?int $sort = DashboardWidgets::MONEY + 4;

    public static function canView(): bool
    {
        if (! static::moduleIsAvailable()) {
            return false;
        }

        return (bool) auth()->user()?->can('AccountView');
    }

    protected function getStats(): array
    {
        return [
            $this->balanceStat('Cash & Bank', ['1100', '1150']),
            $this->balanceStat('Accounts Receivable', ['1250']),
            $this->balanceStat('Accounts Payable', ['2400']),
            $this->balanceStat('Inventory Value', ['1300']),
        ];
    }

    protected function balanceStat(string $label, array $codes): Stat
    {
        $balance = round((float) Account::whereIn('code', $codes)->sum('balance'), 2);

        return Stat::make($label, 'PKR '.number_format($balance, 2));
    }
}
