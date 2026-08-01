<?php

namespace App\Modules\Accounting\Filament\Resources\TransactionTypes\RelationManagers;

use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class PaymentsRelationManager extends RelationManager
{
    protected static string $relationship = 'payments';

    protected static ?string $title = 'Payments';

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('reference')
            ->columns([
                TextColumn::make('reference')
                    ->searchable(),

                TextColumn::make('amount')
                    ->money('PKR')
                    ->sortable(),

                TextColumn::make('status')
                    ->badge()
                    ->sortable(),

                TextColumn::make('value_date')
                    ->label('Value Date')
                    ->date()
                    ->sortable(),
            ]);
    }
}
