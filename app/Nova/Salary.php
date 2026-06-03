<?php

namespace App\Nova;

use Laravel\Nova\Fields\BelongsTo;
use Laravel\Nova\Fields\Currency;
use Laravel\Nova\Fields\ID;
use Laravel\Nova\Http\Requests\NovaRequest;

class Salary extends Resource
{
    public static $model = \App\Models\Salary::class;

    public static $title = 'id';

    public static $search = [
        'id', 'amount',
    ];

    public function fields(NovaRequest $request): array
    {
        return [
            ID::make()->sortable(),

            BelongsTo::make('Employee', 'employee', Employee::class)
                ->sortable()
                ->rules('required'),

            Currency::make('Amount')
                ->sortable()
                ->rules('required', 'numeric', 'min:0'),
        ];
    }
}

