<?php

namespace App\Modules\Inventory\Filament\Resources\StockMovements\Tables;

use App\Support\CompanyCurrency;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class StockMovementsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('id')
                    ->label(__('ID'))
                    ->sortable(),

                TextColumn::make('product.name')
                    ->label(__('Product'))
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
                    ->label(__('Unit Cost'))
                    ->money(CompanyCurrency::code()),

                TextColumn::make('unit_price')
                    ->label(__('Unit Price'))
                    ->money(CompanyCurrency::code()),

                TextColumn::make('total_cost')
                    ->label(__('COGS'))
                    ->money(CompanyCurrency::code()),

                TextColumn::make('remaining_quantity')
                    ->label(__('Lot Remaining'))
                    ->numeric()
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('movement_date')
                    ->label(__('Date'))
                    ->date()
                    ->sortable(),

                TextColumn::make('reference')
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('journalEntry.entry_number')
                    ->label(__('Journal Entry')),
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
