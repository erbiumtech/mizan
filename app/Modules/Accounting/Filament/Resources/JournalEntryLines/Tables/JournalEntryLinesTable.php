<?php

namespace App\Modules\Accounting\Filament\Resources\JournalEntryLines\Tables;

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
                    ->label('ID')
                    ->sortable(),

                TextColumn::make('journalEntry.id')
                    ->label('Journal Entry')
                    ->sortable(),

                TextColumn::make('account.name')
                    ->label('Account')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('debit_amount')
                    ->label('Debit')
                    ->money('PKR')
                    ->sortable(),

                TextColumn::make('credit_amount')
                    ->label('Credit')
                    ->money('PKR')
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
