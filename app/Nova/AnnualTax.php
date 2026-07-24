<?php

namespace App\Nova;

use Laravel\Nova\Fields\BelongsTo;
use Laravel\Nova\Fields\ID;
use Laravel\Nova\Fields\Number;
use Laravel\Nova\Http\Requests\NovaRequest;
use \Illuminate\Contracts\Database\Eloquent\Builder;
use App\Nova\Filters\EmployeeFilter;
use App\Nova\Filters\FiscalYearFilter;

class AnnualTax extends Resource
{
    public static $model = \App\Models\AnnualTax::class;

    public static $group = 'Taxes';

    public static $title = 'id';

    // Global search configuration for AnnualTax
    public static function searchableColumns(): array
    {
        return [
            'id',
            new \Laravel\Nova\Query\Search\SearchableRelation('employee', 'employee_id'),
            new \Laravel\Nova\Query\Search\SearchableRelation('employee.user', 'name'),
            new \Laravel\Nova\Query\Search\SearchableRelation('fiscalYear', 'name'),
        ];
    }

    public static function indexQuery(NovaRequest $request,  $query): Builder
    {
        // Search By Employee ID, User Name, and Fiscal Year Name
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
                ->rules('required', function ($attribute, $value, $fail) use ($request) {
                    $exists = \App\Models\AnnualTax::where('employee_id', $value)
                        ->where('fiscal_year_id', $request->fiscalYear)
                        ->where('id', '!=', $request->resourceId)
                        ->exists();

                    if ($exists) {
                        $fail('Annual Tax record of this employee for this fiscal year is already existed');
                    }
                })
                ->sortable(),

            BelongsTo::make('Fiscal Year', 'fiscalYear', FiscalYear::class)
                ->rules('required')
                ->searchable()
                ->sortable(),

            Number::make('Total Net Income', 'total_net_income')->step(0.01)->readonly()->hideFromIndex(),
            Number::make('Annual Taxable Income', 'annual_income_tax')->step(0.01)->readonly(),
            Number::make('Total Annual Tax', 'total_annual_tax')->step(0.01)->readonly(),
            Number::make('Paid Tax', 'paid_tax')->step(0.01)->readonly(),
            Number::make('Leftover Tax', 'leftover_tax')->step(0.01)->readonly(),
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
