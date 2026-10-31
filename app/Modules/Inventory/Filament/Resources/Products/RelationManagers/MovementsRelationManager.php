<?php

namespace App\Modules\Inventory\Filament\Resources\Products\RelationManagers;

use App\Support\CompanyCurrency;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class MovementsRelationManager extends RelationManager
{
    protected static string $relationship = 'movements';

    protected static ?string $title = 'Movements';

    public function table(Table $table): Table
    {
        // Movements are created through InventoryService only — read-only here
        // (parity with Nova StockMovement, which blocks create/update/delete).
        return $table
            ->columns([
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
                    ->label(__('Journal Entry'))
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('movement_date', 'desc');
    }
}
