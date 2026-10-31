<?php

namespace App\Modules\Employees\Filament\Resources\EmployeeSettings\Tables;

use App\Modules\Employees\Models\Employee;
use App\Support\EmployeeAccess;
use App\Support\LandlordUserColumn;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class EmployeeSettingsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with('employee.user'))
            /*
             * **Six columns shown of fourteen** — the same decision as PayslipsTable, for the same
             * reason. This is the structure a salary is built from, and it is read to answer which
             * structure applies to somebody and from when; the eight components it is built out of
             * are what you open the row to see. Five of them were already toggleable and shown.
             */
            ->columns([
                TextColumn::make('id')
                    ->label(__('ID'))
                    ->searchable()
                    ->sortable(),

                TextColumn::make('employee.employee_id')
                    ->label(__('Employee'))
                    ->formatStateUsing(fn ($state, $record) => $record->employee?->display_label ?? $state)
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

                TextColumn::make('start_date')
                    ->label(__('Start Date'))
                    ->date('m/Y')
                    ->placeholder('-')
                    ->sortable(),

                TextColumn::make('end_date')
                    ->label(__('End Date'))
                    ->date('m/Y')
                    ->placeholder('-')
                    ->sortable(),

                TextColumn::make('basic_wage')
                    ->label(__('Basic Wage'))
                    ->numeric(),

                TextColumn::make('medical_allowance')
                    ->label(__('Medical Allowance'))
                    ->numeric()
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('device_allowance')
                    ->label(__('Device Allowance'))
                    ->numeric()
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('petrol_allowance')
                    ->label(__('Petrol Allowance'))
                    ->numeric()
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('bonus')
                    ->label(__('Bonus'))
                    ->numeric()
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('extra_work_hours')
                    ->label(__('Extra Work Hours'))
                    ->numeric()
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('meal_deduction')
                    ->label(__('Meal Deduction'))
                    ->numeric()
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('advances')
                    ->label(__('Advances'))
                    ->numeric()
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('esi_health_insurance')
                    ->label(__('ESI / Health Insurance'))
                    ->numeric()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('employee_id')
                    ->label(__('Employee'))
                    ->options(fn (): array => app(EmployeeAccess::class)
                        ->scopeAccessibleEmployees(Employee::with('user'), auth()->user())
                        ->get()
                        ->mapWithKeys(fn ($employee) => [
                            $employee->id => $employee->employee_id.' - '.($employee->user?->name ?? ''),
                        ])
                        ->toArray()),

                SelectFilter::make('fiscal_year_id')
                    ->label(__('Fiscal Year'))
                    ->relationship('fiscalYear', 'name'),
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
