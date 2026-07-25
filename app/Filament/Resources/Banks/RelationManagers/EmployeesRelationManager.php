<?php

namespace App\Filament\Resources\Banks\RelationManagers;

use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class EmployeesRelationManager extends RelationManager
{
    protected static string $relationship = 'employees';

    protected static ?string $title = 'Employees';

    public function table(Table $table): Table
    {
        // Employees are managed via the Employee resource (and its approval flow);
        // shown here read-only for parity with the Nova HasMany field.
        return $table
            ->columns([
                TextColumn::make('employee_id')
                    ->label('Employee ID')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('designation')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('department')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('phone')
                    ->searchable(),

                IconColumn::make('is_active')
                    ->label('Active')
                    ->boolean()
                    ->sortable(),
            ]);
    }
}
