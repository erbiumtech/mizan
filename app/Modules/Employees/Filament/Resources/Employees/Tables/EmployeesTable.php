<?php

namespace App\Modules\Employees\Filament\Resources\Employees\Tables;

use App\Filament\Support\CustomFieldsSchema;
use App\Modules\Employees\Models\Employee;
use App\Support\EmployeeAccess;
use App\Support\LandlordUserColumn;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
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
                    ->visible(fn (): bool => auth()->user()?->isAdministrator() ?? false),

                TextColumn::make('employee_id')
                    ->label('Employee ID')
                    ->searchable(),

                // Name and email live on the landlord `users` table while
                // employees live in the tenant database, so these cannot use
                // Filament's relationship search/sort — see LandlordUserColumn.
                TextColumn::make('user.name')
                    ->label('Employee Name')
                    ->sortable(query: fn (Builder $query, string $direction): Builder => LandlordUserColumn::sort($query, $direction, 'name'))
                    ->searchable(query: fn (Builder $query, string $search): Builder => LandlordUserColumn::search($query, $search, ['name'])),

                TextColumn::make('user.email')
                    ->label('Company Email')
                    ->searchable(query: fn (Builder $query, string $search): Builder => LandlordUserColumn::search($query, $search, ['email'])),

                TextColumn::make('personal_email')
                    ->label('Personal Email')
                    ->placeholder('—')
                    ->searchable()
                    ->toggleable(),

                TextColumn::make('date_of_birth')
                    ->label('Date of Birth')
                    ->date('d-m-Y')
                    ->placeholder('—')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('secondary_phone')
                    ->label('Secondary Phone')
                    ->placeholder('—')
                    ->searchable()
                    ->toggleable(isToggledHiddenByDefault: true),

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
