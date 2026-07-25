<?php

namespace App\Filament\Resources\Employees\Tables;

use App\Models\Employee;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

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
                    ->label('Email')
                    ->searchable(),

                TextColumn::make('is_active')
                    ->label('Status')
                    ->formatStateUsing(fn ($state): string => (int) $state === 1 ? 'Active' : 'Inactive'),

                TextColumn::make('bank_code')
                    ->searchable(),

                TextColumn::make('bank_short_code')
                    ->searchable(),

                ...\App\Filament\Support\CustomFieldsSchema::tableColumns(\App\Models\Employee::class),
            ])
            ->filters([
                SelectFilter::make('employee_name')
                    ->label('Employee Name')
                    ->attribute('id')
                    ->options(fn (): array => Employee::with('user')->get()
                        ->mapWithKeys(fn (Employee $e) => [$e->id => $e->user?->name ?? 'Unknown'])
                        ->toArray())
                    ->searchable(),

                SelectFilter::make('employee_email')
                    ->label('Employee Email')
                    ->attribute('id')
                    ->options(fn (): array => Employee::with('user')->get()
                        ->mapWithKeys(fn (Employee $e) => [$e->id => $e->user?->email ?? 'No Email'])
                        ->toArray())
                    ->searchable(),
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