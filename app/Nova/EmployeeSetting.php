<?php

namespace App\Nova;

// use App\Models\EmployeeSetting;
use Laravel\Nova\Fields\BelongsTo;
use Laravel\Nova\Fields\ID;
use Laravel\Nova\Fields\Number;
use Laravel\Nova\Fields\Text;
use Laravel\Nova\Http\Requests\NovaRequest;

class EmployeeSetting extends Resource
{
    public static $model = \App\Models\EmployeeSetting::class;

    // For showing version in dropdown we used version_id.
    public static $title = 'version_id';

    public static $search = ['id', 'version_id'];

    public function fields(NovaRequest $request)
    {
        return [
            ID::make()->sortable(),

            // Read-only field for the setting version (e.g., 21, 22)
            Text::make('Setting ID', 'version_id')
                ->readonly()
                ->hideWhenCreating(),

            BelongsTo::make('Employee', 'employee', Employee::class)
                ->sortable()
                ->rules('required'),

            // --- EARNINGS (Allowances & Extras) ---
            Number::make('Basic Wage', 'basic_wage')->step(0.01)->default(0),
            Number::make('Medical Allowance', 'medical_allowance')->step(0.01)->default(0)->hideFromIndex(),
            Number::make('Device Allowance', 'device_allowance')->step(0.01)->default(0)->hideFromIndex(),
            Number::make('Petrol Allowance', 'petrol_allowance')->step(0.01)->default(0)->hideFromIndex(),
            Number::make('Bonus', 'bonus')->step(0.01)->default(0),
            Number::make('Extra Work Hours', 'extra_work_hours')
                ->step(0.01)
                ->default(0)
                ->help('Default extra hours (if any)')
                ->hideFromIndex(),

            // --- DEDUCTIONS ---
            Number::make('Advances', 'advances')
                ->step(0.01)
                ->default(0)
                ->help('Advance salary deduction'),

            Number::make('Meal Deduction', 'meal_deduction')->step(0.01)->default(0)->hideFromIndex(),
            Number::make('ESI / Health Insurance', 'esi_health_insurance')->step(0.01)->default(0)->hideFromIndex(),
        ];
    }

    public static function fill(NovaRequest $request, $model): array
    {
        $results = parent::fill($request, $model);

        if (empty($model->version_id)) {
            $count = \App\Models\EmployeeSetting::where('employee_id', $model->employee_id)->count();
            $model->version_id = 'V'.($count + 1);
        }

        return $results;
    }
}
