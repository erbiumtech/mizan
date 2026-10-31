<?php

namespace App\Modules\Accounting\Filament\Resources\Beneficiaries\RelationManagers;

use App\Filament\Support\PendingFirst;
use App\Modules\Accounting\Models\Payment;
use App\Support\CompanyCurrency;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class PaymentsRelationManager extends RelationManager
{
    protected static string $relationship = 'payments';

    protected static ?string $title = 'Payments';

    public function table(Table $table): Table
    {
        // Payments are managed via the Payment resource / services — read-only here.
        return $table
            ->columns([
                TextColumn::make('amount')
                    ->money(CompanyCurrency::code())
                    ->sortable(),

                TextColumn::make('details')
                    ->searchable(),

                TextColumn::make('reference')
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('value_date')
                    ->date()
                    ->sortable(),

                TextColumn::make('payment_type'),

                TextColumn::make('status')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'draft' => 'warning',
                        'approved' => 'info',
                        'exported' => 'success',
                        'paid' => 'success',
                        default => 'gray',
                    })
                    ->sortable(),
            ])
            ->defaultSort(PendingFirst::sort(Payment::class, 'value_date'));
    }
}
