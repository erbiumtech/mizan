<?php

namespace App\Modules\Accounting\Filament\Resources\Currencies\Tables;

use App\Modules\Accounting\Models\Currency;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class CurrenciesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('code')->searchable()->sortable(),
                TextColumn::make('name')->searchable(),
                TextColumn::make('symbol')->placeholder('—'),

                IconColumn::make('is_base')
                    ->label('Books kept in')
                    ->boolean()
                    ->tooltip('Set in Company Settings — it is what every amount in the ledger means.'),

                // The rate in force today, which is the number anybody opening this
                // screen is looking for.
                TextColumn::make('rate_today')
                    ->label('Rate today')
                    ->state(fn (Currency $record): string => $record->isBase()
                        ? '—'
                        : ($record->rateOn() === null
                            ? 'none recorded'
                            : number_format($record->rateOn(), 4)))
                    ->color(fn (Currency $record): ?string => (! $record->isBase() && $record->rateOn() === null) ? 'danger' : null)
                    ->description(fn (Currency $record): ?string => $record->isBase()
                        ? null
                        : Currency::baseCode().' per 1 '.$record->code),

                TextColumn::make('rates_count')->label('Rates')->counts('rates')->alignEnd(),
                IconColumn::make('is_active')->label('In use')->boolean(),
            ])
            ->defaultSort('is_base', 'desc')
            ->recordActions([EditAction::make()]);
    }
}
