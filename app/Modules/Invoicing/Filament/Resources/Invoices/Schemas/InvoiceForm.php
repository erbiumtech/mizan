<?php

namespace App\Modules\Invoicing\Filament\Resources\Invoices\Schemas;

use App\Filament\Support\CustomFieldsSchema;
use App\Modules\Accounting\Models\Currency;
use App\Modules\Invoicing\Models\Invoice;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
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

                // The amounts below are in this currency: it is what the client is
                // billed. The ledger is posted in the company's own currency, at the
                // rate on the invoice date unless one is agreed here.
                Select::make('currency_code')
                    ->label('Currency')
                    ->options(fn (): array => Currency::active()
                        ->orderBy('code')
                        ->pluck('name', 'code')
                        ->map(fn (string $name, string $code): string => "{$code} — {$name}")
                        ->all())
                    ->default(fn (): string => Currency::baseCode())
                    ->selectablePlaceholder(false)
                    ->native(false)
                    ->live()
                    ->disabled(fn (?Invoice $record): bool => $record !== null && ! $record->isDraft())
                    ->helperText('What the client is billed in. Fixed once the invoice is issued.'),

                TextInput::make('exchange_rate')
                    ->label('Agreed rate')
                    ->numeric()
                    ->minValue(0)
                    ->visible(fn (callable $get): bool => $get('currency_code')
                        && $get('currency_code') !== Currency::baseCode())
                    ->disabled(fn (?Invoice $record): bool => $record !== null && ! $record->isDraft())
                    ->helperText(fn (callable $get): string => 'Leave blank to use the rate in force on the invoice date. '
                        .Currency::baseCode().' per 1 '.($get('currency_code') ?: '')),

                DatePicker::make('due_date')
                    ->label('Due Date')
                    ->nullable(),

                TextInput::make('subtotal')
                    ->numeric()
                    ->required()
                    ->minValue(0),

                Toggle::make('tax_inclusive')
                    ->label('Line amounts include tax')
                    ->helperText('The same rate is quoted inclusive by one client and exclusive by another, so it is the invoice that says which.'),

                TextInput::make('tax_amount')
                    ->label('Tax')
                    ->numeric()
                    ->required()
                    ->minValue(0)
                    ->helperText('Computed from the lines\' rates when any line has one, on issue. Typed only for an invoice that carries no rates.'),

                TextInput::make('total')
                    ->label('Total')
                    ->numeric()
                    ->required()
                    ->minValue(0),

                Textarea::make('memo')
                    ->nullable(),

                ...CustomFieldsSchema::form(Invoice::class),
            ]);
    }
}
