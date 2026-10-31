<?php

namespace App\Modules\Accounting\Filament\Resources\Accounts\RelationManagers;

use App\Support\CompanyCurrency;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class LinesRelationManager extends RelationManager
{
    protected static string $relationship = 'lines';

    protected static ?string $title = 'Ledger Lines';

    public function table(Table $table): Table
    {
        // Nova HasMany "Ledger Lines" → JournalEntryLine. Journal entry lines are
        // created only through posting services, so this is read-only.
        return $table
            ->columns([
                TextColumn::make('journalEntry.entry_number')
                    ->label(__('Journal Entry'))
                    ->searchable(),

                TextColumn::make('debit_amount')
                    ->label(__('Debit'))
                    ->money(CompanyCurrency::code()),

                TextColumn::make('credit_amount')
                    ->label(__('Credit'))
                    ->money(CompanyCurrency::code()),

                TextColumn::make('description')
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('reconciled_at')
                    ->label(__('Reconciled'))
                    ->dateTime()
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable(),
            ])
            ->defaultSort('created_at', 'desc');
    }
}
