<?php

namespace App\Modules\PersonalFinance\Filament\Resources\PersonalAccounts\Schemas;

use App\Modules\PersonalFinance\Models\PersonalAccount;
use App\Modules\PersonalFinance\Models\TaxSchedule;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Schema;

class PersonalAccountForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('code')
                ->label('Code')
                ->required()
                ->maxLength(20)
                ->helperText('A short reference of your own, e.g. 1000 for cash. Only has to be unique to you.'),

            TextInput::make('name')
                ->label('Name')
                ->required()
                ->maxLength(255),

            Select::make('type')
                ->label('Type')
                ->required()
                ->live()
                ->options([
                    PersonalAccount::TYPE_ASSET => 'Asset — something you own (cash, bank, property)',
                    PersonalAccount::TYPE_LIABILITY => 'Liability — something you owe (a loan, a card)',
                    PersonalAccount::TYPE_INCOME => 'Income — money coming in',
                    PersonalAccount::TYPE_EXPENSE => 'Expense — money going out',
                    PersonalAccount::TYPE_EQUITY => 'Equity — your own capital',
                ])
                ->helperText('Assets and liabilities appear on your balance sheet; income and expenses on your yearly summary.'),

            // Only meaningful on income, which is why it appears only there
            // rather than sitting greyed out on every account.
            Select::make('tax_regime')
                ->label('Taxed as')
                ->options(TaxSchedule::REGIMES)
                ->visible(fn ($get) => $get('type') === PersonalAccount::TYPE_INCOME)
                ->helperText('How this income is taxed. Set it once here and every entry against this account is classified. Left blank, the income is listed as unclassified on the estimate rather than guessed at.'),

            TextInput::make('opening_balance')
                ->label('Opening balance')
                ->numeric()
                ->default(0)
                ->prefix('PKR')
                ->helperText('What was already here when you started tracking. Leave at zero if you are starting fresh.'),

            Toggle::make('is_active')
                ->label('Active')
                ->default(true)
                ->helperText('Closing an account keeps its history but stops it appearing when you record something new.'),
        ]);
    }
}
