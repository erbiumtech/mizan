<?php

namespace App\Modules\PersonalFinance\Filament\Resources\PersonalAccounts\Tables;

use App\Modules\PersonalFinance\Models\PersonalAccount;
use App\Modules\PersonalFinance\Models\TaxSchedule;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class PersonalAccountsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('code')
            ->columns([
                TextColumn::make('code')->label('Code')->sortable()->searchable(),
                TextColumn::make('name')->label('Name')->sortable()->searchable(),

                TextColumn::make('type')
                    ->label('Type')
                    ->badge()
                    ->formatStateUsing(fn (string $state) => ucfirst($state))
                    ->color(fn (string $state) => match ($state) {
                        PersonalAccount::TYPE_ASSET => 'success',
                        PersonalAccount::TYPE_LIABILITY => 'danger',
                        PersonalAccount::TYPE_INCOME => 'info',
                        PersonalAccount::TYPE_EXPENSE => 'warning',
                        default => 'gray',
                    })
                    ->sortable(),

                TextColumn::make('tax_regime')
                    ->label('Taxed as')
                    ->formatStateUsing(fn (?string $state) => $state === null ? '—' : (TaxSchedule::REGIMES[$state] ?? $state))
                    ->toggleable(),

                // Summed from the lines rather than read off the row: there is
                // no cached balance column here, on purpose. See PersonalAccount.
                TextColumn::make('balance')
                    ->label('Balance')
                    ->money('PKR')
                    ->state(fn (PersonalAccount $record) => $record->balance())
                    ->alignEnd()
                    ->weight('semibold'),

                IconColumn::make('is_active')->label('Active')->boolean()->sortable(),
            ])
            ->filters([
                SelectFilter::make('type')->options([
                    PersonalAccount::TYPE_ASSET => 'Asset',
                    PersonalAccount::TYPE_LIABILITY => 'Liability',
                    PersonalAccount::TYPE_INCOME => 'Income',
                    PersonalAccount::TYPE_EXPENSE => 'Expense',
                    PersonalAccount::TYPE_EQUITY => 'Equity',
                ]),
            ])
            ->recordActions([EditAction::make()])
            ->toolbarActions([
                BulkActionGroup::make([DeleteBulkAction::make()]),
            ])
            ->emptyStateHeading('No accounts yet')
            ->emptyStateDescription('Add a starter set to get going: cash, a bank account, and categories for what you earn and spend.');
    }
}
