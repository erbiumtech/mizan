<?php

namespace App\Filament\Resources\InvoiceLines\Schemas;

use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;

class InvoiceLineForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Select::make('invoice_id')
                    ->label('Invoice')
                    ->relationship('invoice', 'invoice_number')
                    ->searchable()
                    ->preload()
                    ->required(),

                Select::make('product_id')
                    ->label('Product')
                    ->relationship('product', 'name')
                    ->searchable()
                    ->preload()
                    ->nullable()
                    ->helperText('Leave empty for service / non-product lines'),

                TextInput::make('description')
                    ->required()
                    ->maxLength(255),

                TextInput::make('quantity')
                    ->numeric()
                    ->step(0.01)
                    ->required()
                    ->minValue(0.01),

                TextInput::make('unit_price')
                    ->label('Unit Price')
                    ->numeric()
                    ->step(0.01)
                    ->required()
                    ->minValue(0),

                TextInput::make('line_total')
                    ->label('Line Total')
                    ->numeric()
                    ->step(0.01)
                    ->required()
                    ->minValue(0),

                Select::make('account_id')
                    ->label('Account Override')
                    ->relationship('account', 'name')
                    ->searchable()
                    ->preload()
                    ->nullable()
                    ->helperText('Posting account for non-product lines'),
            ]);
    }
}
