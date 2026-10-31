<?php

namespace App\Modules\Invoicing\Filament\Resources\InvoiceLines\Tables;

use App\Support\CompanyCurrency;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class InvoiceLinesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('id')
                    ->label(__('ID'))
                    ->sortable(),

                TextColumn::make('invoice.invoice_number')
                    ->label(__('Invoice'))
                    ->sortable(),

                TextColumn::make('product.name')
                    ->label(__('Product'))
                    ->sortable(),

                TextColumn::make('description'),

                TextColumn::make('quantity')
                    ->numeric(),

                TextColumn::make('unit_price')
                    ->label(__('Unit Price'))
                    ->money(CompanyCurrency::code()),

                TextColumn::make('line_total')
                    ->label(__('Line Total'))
                    ->money(CompanyCurrency::code()),
            ])
            ->filters([
                //
            ])
            ->recordActions([
                EditAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }
}
