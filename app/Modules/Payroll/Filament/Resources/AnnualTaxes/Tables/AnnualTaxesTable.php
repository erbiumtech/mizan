<?php

namespace App\Modules\Payroll\Filament\Resources\AnnualTaxes\Tables;

use App\Support\EmployeeAccess;
use App\Support\LandlordUserColumn;
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
            ->modifyQueryUsing(fn ($query) => $query->with('employee.user'))
            ->columns([
                TextColumn::make('id')
                    ->label(__('ID'))
                    ->searchable()
                    ->sortable(),

                TextColumn::make('employee.employee_id')
                    ->label(__('Employee'))
                    ->formatStateUsing(fn ($state, $record): string => ($record->employee?->employee_id ?? '').' - '.($record->employee?->user?->name ?? ''))
                    // Resolved to employee ids first: `users` lives in the
                    // landlord database, so a whereHas through it would emit a
                    // cross-database subquery on the tenant connection.
                    ->searchable(query: fn (Builder $query, string $search): Builder => $query
                        ->whereIn('employee_id', LandlordUserColumn::employeeIdsMatching($search)))
                    ->sortable(),

                TextColumn::make('fiscalYear.name')
                    ->label(__('Fiscal Year'))
                    ->searchable()
                    ->sortable(),

                TextColumn::make('total_net_income')
                    ->label(__('Total Net Income'))
                    ->numeric()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('annual_income_tax')
                    ->label(__('Annual Taxable Income'))
                    ->numeric()
                    ->sortable(),

                TextColumn::make('total_annual_tax')
                    ->label(__('Total Annual Tax'))
                    ->numeric()
                    ->sortable(),

                TextColumn::make('paid_tax')
                    ->label(__('Paid Tax'))
                    ->numeric()
                    ->sortable(),

                TextColumn::make('leftover_tax')
                    ->label(__('Leftover Tax'))
                    ->numeric()
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('employee_id')
                    ->label(__('Employee'))
                    ->relationship('employee', 'employee_id', fn ($query) => app(EmployeeAccess::class)
                        ->scopeAccessibleEmployees($query->with('user'), auth()->user()))
                    ->getOptionLabelFromRecordUsing(fn ($record) => $record->display_label)
                    ->searchable()
                    ->preload(),

                SelectFilter::make('fiscal_year_id')
                    ->label(__('Fiscal Year'))
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
