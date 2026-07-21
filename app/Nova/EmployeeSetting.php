<?php

namespace App\Nova;

use Laravel\Nova\Fields\BelongsTo;
use Laravel\Nova\Fields\ID;
use Laravel\Nova\Fields\Number;
use Laravel\Nova\Fields\Date;
use Laravel\Nova\Http\Requests\NovaRequest;
use Carbon\Carbon;
use App\Nova\Filters\EmployeeFilter;
use App\Nova\Filters\FiscalYearFilter;
use \Illuminate\Contracts\Database\Eloquent\Builder;

class EmployeeSetting extends Resource
{
    public static $model = \App\Models\EmployeeSetting::class;

    public static $title = 'id';

    // Global search configuration for EmployeeSetting
    public static function searchableColumns(): array
    {
        return [
            'id',
            new \Laravel\Nova\Query\Search\SearchableRelation('employee', 'employee_id'),
            new \Laravel\Nova\Query\Search\SearchableRelation('employee.user', 'name'),
            new \Laravel\Nova\Query\Search\SearchableRelation('fiscalYear', 'name'),
        ];
    }

    public static function indexQuery(NovaRequest $request, $query): Builder
    {
        // Handle Search across Employee ID, User Name, and Fiscal Year Name
        if ($search = $request->search) {
            $query->where(function ($q) use ($search) {
                $q->orWhere('id', 'like', "%{$search}%")
                  ->orWhereHas('employee', function ($empQuery) use ($search) {
                      $empQuery->where('employee_id', 'like', "%{$search}%")
                               ->orWhereHas('user', function ($userQuery) use ($search) {
                                   $userQuery->where('name', 'like', "%{$search}%");
                               });
                  })
                  ->orWhereHas('fiscalYear', function ($fyQuery) use ($search) {
                      $fyQuery->where('name', 'like', "%{$search}%");
                  });
            });
        }

        return $query;
    }

    public function fields(NovaRequest $request)
    {
        return [
            ID::make()->sortable(),

            BelongsTo::make('Employee', 'employee', Employee::class)
                ->searchable()
                ->sortable()
                ->rules('required'),

            BelongsTo::make('Fiscal Year', 'fiscalYear', FiscalYear::class)
                ->rules('required')
                ->searchable()
                ->relatableQueryUsing(function (NovaRequest $request, $query) {
                    return $query->where('is_active', true);
                }),

            Date::make('Start Date', 'start_date')
                ->rules('required')
                ->default(function ($request) {
                    return Carbon::today()->toDateString();
                })
                ->displayUsing(function ($value) {
                    return $value ? Carbon::parse($value)->format('m/Y') : '-';
                })
                ->sortable(),

            Date::make('End Date', 'end_date')
                ->nullable()
                ->displayUsing(function ($value) {
                    return $value ? Carbon::parse($value)->format('m/Y') : '-';
                })
                ->sortable(),

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
    public function filters(NovaRequest $request)
    {
        return [
            new EmployeeFilter,
            new FiscalYearFilter,
        ];
    }

}
