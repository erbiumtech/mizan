<?php

namespace App\Nova;

use App\Nova\FiscalYear;
use Laravel\Nova\Fields\BelongsTo;
use Laravel\Nova\Fields\ID;
use Laravel\Nova\Fields\Number;
use Laravel\Nova\Fields\Select;
use Laravel\Nova\Http\Requests\NovaRequest;

class EmployeeSetting extends Resource
{
    public static $model = \App\Models\EmployeeSetting::class;

    public static $title = 'id';

    public static $search = ['id'];

    public function fields(NovaRequest $request)
    {
        return [
            ID::make()->sortable(),

            BelongsTo::make('Employee', 'employee', Employee::class)
                ->sortable()
                ->rules('required'),

            Select::make('Month', 'month')->options([
                'January' => 'January', 'February' => 'February', 'March' => 'March',
                'April' => 'April', 'May' => 'May', 'June' => 'June',
                'July' => 'July', 'August' => 'August', 'September' => 'September',
                'October' => 'October', 'November' => 'November', 'December' => 'December',
            ])->rules('required'),

            BelongsTo::make('Fiscal Year', 'fiscalYear', FiscalYear::class)
                ->rules('required')
                ->relatableQueryUsing(function (NovaRequest $request, $query) {
                    return $query->where('is_active', true);
                }),

            // --- EARNINGS (Allowances & Extras) ---
            Number::make('Basic Wage', 'basic_wage')->step(0.01)->default(0),
            Number::make('Medical Allowance', 'medical_allowance')->step(0.01)->default(0),
            Number::make('Device Allowance', 'device_allowance')->step(0.01)->default(0)->hideFromIndex(),
            Number::make('Petrol Allowance', 'petrol_allowance')->step(0.01)->default(0)->hideFromIndex(),
            Number::make('Bonus', 'bonus')->step(0.01)->default(0)->hideFromIndex(),
            Number::make('Extra Work Hours', 'extra_work_hours')
                ->step(0.01)
                ->default(0)
                ->help('Default extra hours (if any)')
                ->hideFromIndex(),

            // --- DEDUCTIONS ---
            Number::make('Advances', 'advances')
                ->step(0.01)
                ->default(0)
                ->help('Advance salary deduction')
                ->hideFromIndex(),

            Number::make('Meal Deduction', 'meal_deduction')->step(0.01)->default(0)->hideFromIndex(),
            Number::make('ESI / Health Insurance', 'esi_health_insurance')->step(0.01)->default(0)->hideFromIndex(),
        ];
    }
}
