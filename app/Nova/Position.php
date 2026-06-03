<?php

namespace App\Nova;

use Laravel\Nova\Fields\BelongsTo;
use Laravel\Nova\Fields\ID;
use Laravel\Nova\Fields\Text;
use Laravel\Nova\Http\Requests\NovaRequest;

class Position extends Resource
{
    public static $model = \App\Models\Position::class;

    public static $title = 'name';

    public static $search = [
        'id', 'name',
    ];

    public function fields(NovaRequest $request): array
    {
        return [
            ID::make()->sortable(),

            BelongsTo::make('Employee', 'employee', Employee::class)
                ->sortable()
                ->rules('required'),

            Text::make('Name')
                ->sortable()
                ->rules('required', 'max:255'),
        ];
    }
}

