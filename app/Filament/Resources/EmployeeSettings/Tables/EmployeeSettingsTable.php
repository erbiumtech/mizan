<?php

namespace App\Filament\Resources\EmployeeSettings\Tables;

use App\Models\Employee;
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
            ->columns([
                TextColumn::make('id')
                    ->label('ID')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('employee.employee_id')
                    ->label('Employee')
                    ->searchable(query: fn (Builder $query, string $search): Builder => $query
                        ->whereHas('employee', fn ($q) => $q
                            ->where('employee_id', 'like', "%{$search}%")
                            ->orWhereHas('user', fn ($u) => $u->where('name', 'like', "%{$search}%"))))
                    ->sortable(),

                TextColumn::make('fiscalYear.name')
                    ->label('Fiscal Year')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('start_date')
                    ->label('Start Date')
                    ->date('m/Y')
                    ->placeholder('-')
                    ->sortable(),

                TextColumn::make('end_date')
                    ->label('End Date')
                    ->date('m/Y')
                    ->placeholder('-')
                    ->sortable(),

                TextColumn::make('basic_wage')
                    ->label('Basic Wage')
                    ->numeric(),

                TextColumn::make('medical_allowance')
                    ->label('Medical Allowance')
                    ->numeric(),

                TextColumn::make('device_allowance')
                    ->label('Device Allowance')
                    ->numeric()
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('petrol_allowance')
                    ->label('Petrol Allowance')
                    ->numeric()
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('bonus')
                    ->label('Bonus')
                    ->numeric()
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('extra_work_hours')
                    ->label('Extra Work Hours')
                    ->numeric()
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('advances')
                    ->label('Advances')
                    ->numeric()
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('meal_deduction')
                    ->label('Meal Deduction')
                    ->numeric()
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('esi_health_insurance')
                    ->label('ESI / Health Insurance')
                    ->numeric()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('employee_id')
                    ->label('Employee')
                    ->options(fn (): array => Employee::with('user')
                        ->get()
                        ->mapWithKeys(fn ($employee) => [
                            $employee->id => $employee->employee_id . ' - ' . ($employee->user?->name ?? ''),
                        ])
                        ->toArray()),

                SelectFilter::make('fiscal_year_id')
                    ->label('Fiscal Year')
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
