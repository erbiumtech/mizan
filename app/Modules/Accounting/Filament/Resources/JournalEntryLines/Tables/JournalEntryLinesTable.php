<?php

namespace App\Modules\Accounting\Filament\Resources\JournalEntryLines\Tables;

use App\Support\CompanyCurrency;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class JournalEntryLinesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('id')
                    ->label(__('ID'))
                    ->sortable(),

                TextColumn::make('journalEntry.id')
                    ->label(__('Journal Entry'))
                    ->sortable(),

                TextColumn::make('account.name')
                    ->label(__('Account'))
                    ->searchable()
                    ->sortable(),

                TextColumn::make('debit_amount')
                    ->label(__('Debit'))
                    ->money(CompanyCurrency::code())
                    ->sortable(),

                TextColumn::make('credit_amount')
                    ->label(__('Credit'))
                    ->money(CompanyCurrency::code())
                    ->sortable(),

                TextColumn::make('description')
                    ->searchable(),
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
