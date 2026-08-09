<?php

namespace App\Modules\Invoicing\Filament\Resources\Contacts\RelationManagers;

use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class InvoicesRelationManager extends RelationManager
{
    protected static string $relationship = 'invoices';

    protected static ?string $title = 'Invoices';

    public function table(Table $table): Table
    {
        // Invoices are managed through their own resource / invoicing flow —
        // read-only here (parity with Nova HasMany, which links to the Invoice resource).
        return $table
            ->columns([
                TextColumn::make('invoice_number')
                    ->label('Number')
                    ->sortable()
                    ->searchable(),

                TextColumn::make('kind')
                    ->label('Kind')
                    ->badge()
                    ->sortable(),

                TextColumn::make('status')
                    ->label('Status')
                    ->badge()
                    ->sortable(),

                TextColumn::make('invoice_date')
                    ->label('Invoice Date')
                    ->date()
                    ->sortable(),

                TextColumn::make('total')
                    ->label('Total')
                    ->money('PKR')
                    ->sortable(),

                TextColumn::make('amount_paid')
                    ->label('Paid')
                    ->money('PKR')
                    ->sortable(),
            ])
            ->defaultSort('invoice_date', 'desc');
    }
}
