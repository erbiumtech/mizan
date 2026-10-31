<?php

namespace App\Modules\Accounting\Dashboard;

use App\Modules\Accounting\Filament\Resources\CompanyBankAccounts\CompanyBankAccountResource;
use App\Modules\Accounting\Models\Account;
use App\Support\CompanyCurrency;
use App\Support\DashboardSummaries;

/**
 * The cash card on the Material dashboard: the ledger's cash and bank accounts, the same codes the
 * "Cash & Bank" figure in AccountBalancesOverview sums, so the two never disagree.
 */
final class AccountingDashboard
{
    /** Cash in hand and bank — kept beside AccountBalancesOverview's list. */
    public const CASH_CODES = ['1100', '1150'];

    public static function register(): void
    {
        DashboardSummaries::register(
            'accounting.cash',
            visible: fn (): bool => modules()->enabled('accounting') && (bool) auth()->user()?->can('AccountView'),
            value: fn (): array => [
                'label' => __('Cash in bank'),
                'value' => CompanyCurrency::code().' '.number_format(
                    round((float) Account::query()->whereIn('code', self::CASH_CODES)->sum('balance'), 2),
                    0,
                ),
                'link' => __('Bank accounts'),
                'url' => rescue(fn (): string => CompanyBankAccountResource::getUrl('index'), null, false),
                'tone' => 'tertiary',
            ],
            sort: 40,
        );
    }
}
