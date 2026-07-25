<?php

namespace App\Filament\Resources\BankStatements\RelationManagers;

use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class LinesRelationManager extends RelationManager
{
    protected static string $relationship = 'lines';

    protected static ?string $title = 'Lines';

    public function table(Table $table): Table
    {
        // Lines are created/matched through BankReconciliationService only — read-only here.
        return $table
            ->columns([
                TextColumn::make('transaction_date')
                    ->label('Date')
                    ->date()
                    ->sortable(),

                TextColumn::make('description')
                    ->searchable()
                    ->wrap(),

                TextColumn::make('reference')
                    ->searchable()
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('amount')
                    ->money('PKR')
                    ->sortable(),

                TextColumn::make('match_status')
                    ->label('Match Status')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'unmatched' => 'danger',
                        'auto_matched' => 'success',
                        'manually_matched' => 'info',
                        'excluded' => 'gray',
                        default => 'gray',
                    })
                    ->sortable(),
            ])
            ->defaultSort('transaction_date', 'desc');
    }
}
