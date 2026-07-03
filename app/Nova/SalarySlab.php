<?php

namespace App\Nova;

use Laravel\Nova\Fields\BelongsTo;
use Laravel\Nova\Fields\ID;
use Laravel\Nova\Fields\Number;
use Laravel\Nova\Fields\Text;
use Laravel\Nova\Http\Requests\NovaRequest;

class SalarySlab extends Resource
{
    public static $model = \App\Models\SalarySlab::class;

    public static $title = 'id';

    public static $search = [
        'id', 'min_amount', 'max_amount',
    ];

    public function fields(NovaRequest $request)
    {
        return [
            ID::make()->sortable(),

            BelongsTo::make('Fiscal Year', 'fiscalYear', 'App\Nova\FiscalYear')
                ->rules('required')
                ->relatableQueryUsing(function (NovaRequest $request, $query) {
                    return $query->where('is_active', true);
                }),

            Number::make('Minimum Amount (Annual)', 'min_amount')
                ->rules('required', 'numeric', 'min:0')
                ->help('Annual income starting range, e.g., 600001')
                ->sortable(),

            Number::make('Maximum Amount (Annual)', 'max_amount')
                ->nullable()
                ->help('Annual income ending range.'),

            Number::make('Fixed Tax Amount', 'fixed_tax')
                ->rules('required', 'numeric', 'min:0')
                ->help('Slab fixed tax amount, e.g., 6000 or 116000')
                ->default(0),

            Number::make('Tax Percentage (%)', 'percentage')
                ->rules('required', 'numeric', 'min:0', 'max:100')
                ->help('Percentage on Exceeding Amount, e.g., 20')
                ->default(0)
                ->step(0.01),

            Text::make('Slab Preview', function () {
                $max = $this->max_amount ? number_format($this->max_amount) : 'Above';
                $min = number_format($this->min_amount);
                $tax = number_format($this->fixed_tax);

                return "PKR {$min} to {$max} ➔ Fixed: PKR {$tax} + {$this->percentage}%";
            })->hideFromIndex(),
        ];
    }

    public function cards(NovaRequest $request)
    {
        return [];
    }

    public function filters(NovaRequest $request)
    {
        return [];
    }

    public function lenses(NovaRequest $request)
    {
        return [];
    }

    public function actions(NovaRequest $request)
    {
        return [];
    }
}
