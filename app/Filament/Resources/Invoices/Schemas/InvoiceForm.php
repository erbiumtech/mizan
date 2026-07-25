<?php

namespace App\Filament\Resources\Invoices\Schemas;

use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Textarea;
use Filament\Schemas\Schema;

class InvoiceForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                // Nova: Select 'kind' — onlyOnForms, hideWhenUpdating (disabled on edit).
                Select::make('kind')
                    ->label('Kind')
                    ->options([
                        'sale' => 'Sale (customer invoice)',
                        'purchase' => 'Purchase (supplier bill)',
                    ])
                    ->required()
                    ->disabled(fn (?string $operation): bool => $operation === 'edit')
                    ->dehydrated(),

                Select::make('contact_id')
                    ->label('Contact')
                    ->relationship('contact', 'name')
                    ->searchable()
                    ->preload()
                    ->required(),

                DatePicker::make('invoice_date')
                    ->label('Invoice Date')
                    ->required(),

                DatePicker::make('due_date')
                    ->label('Due Date')
                    ->nullable(),

                TextInput::make('subtotal')
                    ->numeric()
                    ->required()
                    ->minValue(0),

                TextInput::make('tax_amount')
                    ->label('Tax')
                    ->numeric()
                    ->required()
                    ->minValue(0),

                TextInput::make('total')
                    ->label('Total')
                    ->numeric()
                    ->required()
                    ->minValue(0),

                Textarea::make('memo')
                    ->nullable(),
            ]);
    }
}
