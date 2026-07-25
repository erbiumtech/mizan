<?php

namespace App\Filament\Resources\StockMovements\Schemas;

use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;

class StockMovementForm
{
    public static function configure(Schema $schema): Schema
    {
        // Read-only resource — records are created via InventoryService only.
        // Fields are defined for parity/detail display; disabled to prevent edits.
        return $schema
            ->components([
                Select::make('product_id')
                    ->label('Product')
                    ->relationship('product', 'name')
                    ->searchable()
                    ->preload()
                    ->disabled(),

                Select::make('type')
                    ->options([
                        'purchase' => 'purchase',
                        'sale' => 'sale',
                        'adjustment' => 'adjustment',
                    ])
                    ->disabled(),

                TextInput::make('quantity')
                    ->numeric()
                    ->disabled(),

                TextInput::make('unit_cost')
                    ->label('Unit Cost')
                    ->numeric()
                    ->disabled(),

                TextInput::make('unit_price')
                    ->label('Unit Price')
                    ->numeric()
                    ->disabled(),

                TextInput::make('total_cost')
                    ->label('COGS')
                    ->numeric()
                    ->disabled(),

                TextInput::make('remaining_quantity')
                    ->label('Lot Remaining')
                    ->numeric()
                    ->disabled(),

                DatePicker::make('movement_date')
                    ->label('Date')
                    ->disabled(),

                TextInput::make('reference')
                    ->disabled(),

                Select::make('journal_entry_id')
                    ->label('Journal Entry')
                    ->relationship('journalEntry', 'entry_number')
                    ->searchable()
                    ->preload()
                    ->nullable()
                    ->disabled(),
            ]);
    }
}
