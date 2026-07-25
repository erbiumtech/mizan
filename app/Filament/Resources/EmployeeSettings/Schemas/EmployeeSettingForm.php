<?php

namespace App\Filament\Resources\EmployeeSettings\Schemas;

use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Builder;

class EmployeeSettingForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Select::make('employee_id')
                    ->label('Employee')
                    ->relationship('employee', 'employee_id')
                    ->searchable()
                    ->preload()
                    ->required(),

                Select::make('fiscal_year_id')
                    ->label('Fiscal Year')
                    ->relationship('fiscalYear', 'name', fn (Builder $query) => $query->where('is_active', true))
                    ->searchable()
                    ->preload()
                    ->required(),

                DatePicker::make('start_date')
                    ->label('Start Date')
                    ->required()
                    ->default(now()->toDateString()),

                DatePicker::make('end_date')
                    ->label('End Date')
                    ->nullable(),

                // --- EARNINGS (Allowances & Extras) ---
                TextInput::make('basic_wage')
                    ->label('Basic Wage')
                    ->numeric()
                    ->step(0.01)
                    ->default(0),

                TextInput::make('medical_allowance')
                    ->label('Medical Allowance')
                    ->numeric()
                    ->step(0.01)
                    ->default(0),

                TextInput::make('device_allowance')
                    ->label('Device Allowance')
                    ->numeric()
                    ->step(0.01)
                    ->default(0),

                TextInput::make('petrol_allowance')
                    ->label('Petrol Allowance')
                    ->numeric()
                    ->step(0.01)
                    ->default(0),

                TextInput::make('bonus')
                    ->label('Bonus')
                    ->numeric()
                    ->step(0.01)
                    ->default(0),

                TextInput::make('extra_work_hours')
                    ->label('Extra Work Hours')
                    ->numeric()
                    ->step(0.01)
                    ->default(0)
                    ->helperText('Default extra hours (if any)'),

                // --- DEDUCTIONS ---
                TextInput::make('advances')
                    ->label('Advances')
                    ->numeric()
                    ->step(0.01)
                    ->default(0)
                    ->helperText('Advance salary deduction'),

                TextInput::make('meal_deduction')
                    ->label('Meal Deduction')
                    ->numeric()
                    ->step(0.01)
                    ->default(0),

                TextInput::make('esi_health_insurance')
                    ->label('ESI / Health Insurance')
                    ->numeric()
                    ->step(0.01)
                    ->default(0),
            ]);
    }
}
