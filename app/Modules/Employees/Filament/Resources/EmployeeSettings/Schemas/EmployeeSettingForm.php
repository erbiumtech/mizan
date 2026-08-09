<?php

namespace App\Modules\Employees\Filament\Resources\EmployeeSettings\Schemas;

use App\Modules\Employees\Models\EmployeeSetting;
use App\Support\EmployeeAccess;
use App\Support\EmployeeOptions;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;

class EmployeeSettingForm
{
    /**
     * Whether the current user is editing their own settings row as an
     * employee. Their edits become a pending change request, and the fields
     * deciding which period the row governs are not theirs to propose.
     */
    protected static function isSelfService(?EmployeeSetting $record): bool
    {
        return $record?->isSelfServiceEditFor(Auth::user()) ?? false;
    }

    public static function configure(Schema $schema): Schema
    {
        // `dehydrated(false)` as well as `disabled()`: a disabled field is not
        // submitted by the browser, but keeping it out of the payload means a
        // handcrafted request cannot smuggle one past the interception either.
        $locked = fn (?EmployeeSetting $record) => static::isSelfService($record);

        return $schema
            ->components([
                Placeholder::make('approval_notice')
                    ->hiddenLabel()
                    ->visible(fn (?EmployeeSetting $record) => static::isSelfService($record))
                    ->content('Your changes are submitted for approval — the figures below stay as they are until an Administrator, Manager or CEO approves them.')
                    ->columnSpanFull(),

                Select::make('employee_id')
                    ->label('Employee')
                    ->relationship('employee', 'employee_id', fn ($query) => app(EmployeeAccess::class)
                        ->scopeAccessibleEmployees($query->with('user'), Auth::user()))
                    ->getOptionLabelFromRecordUsing(fn ($record) => $record->display_label)
                    ->searchable()
                    // The label shows the user's name, so the search must match it too;
                    // Filament would otherwise search only the employee_id title attribute.
                    ->getSearchResultsUsing(fn (string $search): array => EmployeeOptions::search(
                        $search,
                        EmployeeOptions::accessibleScope(),
                    ))
                    ->preload()
                    ->required()
                    ->disabled($locked)
                    ->dehydrated(fn (?EmployeeSetting $record) => ! static::isSelfService($record)),

                Select::make('fiscal_year_id')
                    ->label('Fiscal Year')
                    ->relationship('fiscalYear', 'name', fn (Builder $query) => $query->where('is_active', true))
                    ->searchable()
                    ->preload()
                    ->required()
                    ->disabled($locked)
                    ->dehydrated(fn (?EmployeeSetting $record) => ! static::isSelfService($record)),

                DatePicker::make('start_date')
                    ->label('Start Date')
                    ->required()
                    ->default(now()->toDateString())
                    ->disabled($locked)
                    ->dehydrated(fn (?EmployeeSetting $record) => ! static::isSelfService($record)),

                DatePicker::make('end_date')
                    ->label('End Date')
                    ->nullable()
                    ->disabled($locked)
                    ->dehydrated(fn (?EmployeeSetting $record) => ! static::isSelfService($record)),

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
