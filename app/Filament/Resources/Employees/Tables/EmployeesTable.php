<?php

namespace App\Filament\Resources\Employees\Tables;

use App\Filament\Support\CustomFieldsSchema;
use App\Models\Employee;
use App\Support\EmployeeAccess;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Support\Collection;

class EmployeesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                // Nova ID: canSee Administrators only.
                TextColumn::make('id')
                    ->label('ID')
                    ->sortable()
                    ->visible(fn (): bool => auth()->user()?->hasRole('Administrator') ?? false),

                TextColumn::make('employee_id')
                    ->label('Employee ID')
                    ->searchable(),

                TextColumn::make('user.name')
                    ->label('Employee Name')
                    ->sortable()
                    ->searchable(),

                TextColumn::make('user.email')
                    ->label('Company Email')
                    ->searchable(),

                TextColumn::make('personal_email')
                    ->label('Personal Email')
                    ->placeholder('—')
                    ->searchable()
                    ->toggleable(),

                TextColumn::make('is_active')
                    ->label('Status')
                    ->formatStateUsing(fn ($state): string => (int) $state === 1 ? 'Active' : 'Inactive'),

                TextColumn::make('bank_code')
                    ->searchable(),

                TextColumn::make('bank_short_code')
                    ->searchable(),

                ...CustomFieldsSchema::tableColumns(Employee::class),
            ])
            ->filters([
                SelectFilter::make('employee_name')
                    ->label('Employee Name')
                    ->attribute('id')
                    ->options(fn (): array => static::accessibleEmployees()
                        ->mapWithKeys(fn (Employee $e) => [$e->id => $e->user?->name ?? 'Unknown'])
                        ->toArray())
                    ->searchable(),

                SelectFilter::make('employee_email')
                    ->label('Company Email')
                    ->attribute('id')
                    ->options(fn (): array => static::accessibleEmployees()
                        ->mapWithKeys(fn (Employee $e) => [$e->id => $e->user?->email ?? 'No Email'])
                        ->toArray())
                    ->searchable(),
            ])
            ->recordActions([
                ViewAction::make(),
                EditAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }

    /** Employees the current user may filter by (own + downline; all if privileged). */
    protected static function accessibleEmployees(): Collection
    {
        $query = Employee::with('user');

        return app(EmployeeAccess::class)
            ->scopeAccessibleEmployees($query, auth()->user())
            ->get();
    }
}
