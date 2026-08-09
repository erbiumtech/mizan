<?php

namespace App\Modules\Accounting\Filament\Resources\Accounts\RelationManagers;

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
                    ->label('Journal Entry')
                    ->searchable(),

                TextColumn::make('debit_amount')
                    ->label('Debit')
                    ->money('PKR'),

                TextColumn::make('credit_amount')
                    ->label('Credit')
                    ->money('PKR'),

                TextColumn::make('description')
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('reconciled_at')
                    ->label('Reconciled')
                    ->dateTime()
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable(),
            ])
            ->defaultSort('created_at', 'desc');
    }
}
