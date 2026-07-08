<?php

namespace App\Nova;

use Laravel\Nova\Fields\BelongsTo;
use Laravel\Nova\Fields\ID;
use Laravel\Nova\Fields\Number;
use Laravel\Nova\Http\Requests\NovaRequest;

class AnnualTax extends Resource
{
    public static $model = \App\Models\AnnualTax::class;

    public static $title = 'id';

    public static $search = ['id'];

    public function fields(NovaRequest $request)
    {
        return [
            ID::make()->sortable(),

            BelongsTo::make('Employee', 'employee', Employee::class)
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

            BelongsTo::make('Fiscal Year', 'fiscalYear', FiscalYear::class)->rules('required')->sortable(),

            Number::make('Total Net Income', 'total_net_income')->step(0.01)->readonly()->hideFromIndex(),
            Number::make('Annual Taxable Income', 'annual_income_tax')->step(0.01)->readonly(),
            Number::make('Total Annual Tax', 'total_annual_tax')->step(0.01)->readonly(),
            Number::make('Paid Tax', 'paid_tax')->step(0.01)->readonly(),
            Number::make('Leftover Tax', 'leftover_tax')->step(0.01)->readonly(),
        ];
    }
}
