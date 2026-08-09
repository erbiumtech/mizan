<?php

namespace App\Modules\Accounting\Filament\Widgets;

use App\Filament\Concerns\WidgetBelongsToModule;
use App\Modules\Accounting\Models\Account;
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

    protected static ?int $sort = 2;

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
