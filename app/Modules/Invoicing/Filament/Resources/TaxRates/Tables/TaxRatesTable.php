<?php

namespace App\Modules\Invoicing\Filament\Resources\TaxRates\Tables;

use App\Modules\Invoicing\Models\TaxRate;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class TaxRatesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            /*
             * Both of this table's computed columns are per-row otherwise.
             *
             * `account` is read by the "Posts to" column, which is a query per row — and a lazy-loading
             * violation with the guard on, so the page does not render at all. `withSum` replaces the
             * `lines()->sum()` in "Charged to date" with one aggregate for the page; it sums the same
             * column over the same relation, so the figure is unchanged.
             */
            ->modifyQueryUsing(fn (Builder $query): Builder => $query
                ->with('account')
                ->withSum('lines', 'tax_amount'))
            ->columns([
                TextColumn::make('name')->searchable()->sortable(),

                TextColumn::make('rate')
                    ->label('Rate')
                    ->formatStateUsing(fn ($state): string => rtrim(rtrim(number_format((float) $state, 4, '.', ''), '0'), '.').'%')
                    ->alignEnd()
                    ->sortable(),

                TextColumn::make('account')
                    ->label('Posts to')
                    ->state(fn (TaxRate $record): string => $record->account
                        ? $record->account->code.' '.$record->account->name
                        : TaxRate::DEFAULT_ACCOUNT_CODE.' (default)'),

                TextColumn::make('code')->label('Filing code')->toggleable(),

                // How much has been charged at this rate — the question a filing
                // asks, and the reason a rate is an entity rather than a number.
                TextColumn::make('charged')
                    ->label('Charged to date')
                    // From the aggregate loaded with the page. Null when a rate has never been charged,
                    // which reads as 0.00 — the same as the sum of no rows did.
                    ->state(fn (TaxRate $record): string => number_format((float) ($record->lines_sum_tax_amount ?? 0), 2))
                    ->alignEnd(),

                IconColumn::make('is_default')->label('Default')->boolean(),
                IconColumn::make('is_active')->label('Active')->boolean(),
            ])
            ->defaultSort('rate', 'desc')
            ->recordActions([EditAction::make()])
            ->toolbarActions([BulkActionGroup::make([DeleteBulkAction::make()])]);
    }
}
