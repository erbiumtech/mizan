<?php

namespace App\Modules\Inventory\Filament\Resources\StockMovements\Tables;

use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class StockMovementsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('id')
                    ->label('ID')
                    ->sortable(),

                TextColumn::make('product.name')
                    ->label('Product')
                    ->sortable(),

                TextColumn::make('type')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'purchase' => 'success',
                        'sale' => 'info',
                        'adjustment' => 'warning',
                        default => 'gray',
                    })
                    ->sortable(),

                TextColumn::make('quantity')
                    ->numeric()
                    ->sortable(),

                TextColumn::make('unit_cost')
                    ->label('Unit Cost')
                    ->money('PKR'),

                TextColumn::make('unit_price')
                    ->label('Unit Price')
                    ->money('PKR'),

                TextColumn::make('total_cost')
                    ->label('COGS')
                    ->money('PKR'),

                TextColumn::make('remaining_quantity')
                    ->label('Lot Remaining')
                    ->numeric()
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('movement_date')
                    ->label('Date')
                    ->date()
                    ->sortable(),

                TextColumn::make('reference')
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('journalEntry.entry_number')
                    ->label('Journal Entry'),
            ])
            ->filters([
                //
            ])
            ->recordActions([
                // Read-only: no view/edit/delete actions (parity with Nova).
            ])
            ->toolbarActions([
                // Read-only: no bulk actions.
            ]);
    }
}
