<?php

namespace App\Modules\Accounting\Filament\Resources\CompanyBankAccounts\Tables;

use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class CompanyBankAccountsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('title')
                    ->label(__('Title'))
                    ->sortable()
                    ->searchable(),

                TextColumn::make('bank.bank_name')
                    ->label(__('Bank'))
                    ->sortable()
                    ->searchable(),

                TextColumn::make('account_no')
                    ->label(__('Account No'))
                    ->formatStateUsing(fn (?string $state): ?string => $state
                        ? str_repeat('•', max(strlen($state) - 4, 0)).substr($state, -4)
                        : null)
                    ->searchable(),

                TextColumn::make('account.name')
                    ->label(__('Ledger account'))
                    ->placeholder('Cash / Bank (not set)')
                    ->toggleable(),

                IconColumn::make('is_default')
                    ->label(__('Default for its type'))
                    ->boolean(),

                IconColumn::make('is_active')
                    ->label(__('Active'))
                    ->boolean()
                    ->sortable(),

                TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('updated_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                //
            ])
            ->recordActions([
                EditAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }
}
