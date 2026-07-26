<?php

namespace App\Filament\Resources\Beneficiaries\Tables;

use App\Filament\Support\CustomFieldsSchema;
use App\Models\Beneficiary;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class BeneficiariesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->sortable()
                    ->searchable(),

                TextColumn::make('bank.bank_name')
                    ->label('Bank')
                    ->searchable(),

                TextColumn::make('iban')
                    ->label('IBAN')
                    ->searchable(),

                TextColumn::make('transactionType.name')
                    ->label('Usual Transaction Type'),

                TextColumn::make('payment_type')
                    ->label('Default Payment Type'),

                IconColumn::make('is_active')
                    ->label('Active')
                    ->boolean()
                    ->sortable(),

                ...CustomFieldsSchema::tableColumns(Beneficiary::class),
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
