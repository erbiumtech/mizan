<?php

namespace App\Filament\Resources\AnnualTaxes\Tables;

use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class AnnualTaxesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('id')
                    ->label('ID')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('employee.employee_id')
                    ->label('Employee')
                    ->formatStateUsing(fn ($state, $record): string => ($record->employee?->employee_id ?? '').' - '.($record->employee?->user?->name ?? ''))
                    ->searchable(query: fn (Builder $query, string $search): Builder => $query
                        ->whereHas('employee', fn ($q) => $q
                            ->where('employee_id', 'like', "%{$search}%")
                            ->orWhereHas('user', fn ($u) => $u->where('name', 'like', "%{$search}%"))))
                    ->sortable(),

                TextColumn::make('fiscalYear.name')
                    ->label('Fiscal Year')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('total_net_income')
                    ->label('Total Net Income')
                    ->numeric()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('annual_income_tax')
                    ->label('Annual Taxable Income')
                    ->numeric()
                    ->sortable(),

                TextColumn::make('total_annual_tax')
                    ->label('Total Annual Tax')
                    ->numeric()
                    ->sortable(),

                TextColumn::make('paid_tax')
                    ->label('Paid Tax')
                    ->numeric()
                    ->sortable(),

                TextColumn::make('leftover_tax')
                    ->label('Leftover Tax')
                    ->numeric()
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('employee_id')
                    ->label('Employee')
                    ->relationship('employee', 'employee_id')
                    ->searchable()
                    ->preload(),

                SelectFilter::make('fiscal_year_id')
                    ->label('Fiscal Year')
                    ->relationship('fiscalYear', 'name')
                    ->searchable()
                    ->preload(),
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
