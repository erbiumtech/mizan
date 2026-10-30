<?php

namespace App\Modules\Invoicing\Filament\Resources\Contacts\RelationManagers;

use App\Support\CompanyCurrency;
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
                    ->label(__('Number'))
                    ->sortable()
                    ->searchable(),

                TextColumn::make('kind')
                    ->label(__('Kind'))
                    ->badge()
                    ->sortable(),

                TextColumn::make('status')
                    ->label(__('Status'))
                    ->badge()
                    ->sortable(),

                TextColumn::make('invoice_date')
                    ->label(__('Invoice Date'))
                    ->date()
                    ->sortable(),

                TextColumn::make('total')
                    ->label(__('Total'))
                    ->money(CompanyCurrency::code())
                    ->sortable(),

                TextColumn::make('amount_paid')
                    ->label(__('Paid'))
                    ->money(CompanyCurrency::code())
                    ->sortable(),
            ])
            ->defaultSort('invoice_date', 'desc');
    }
}
