<?php

namespace App\Nova;

use Laravel\Nova\Fields\{ID, Text, Boolean};
use Laravel\Nova\Http\Requests\NovaRequest;

class FiscalYear extends Resource
{
    public static $model = \App\Models\FiscalYear::class;
    public static $title = 'name';
    public static $search = ['name'];

    public function fields(NovaRequest $request)
    {
        return [
            ID::make()->sortable(),
            Text::make('Year Name', 'name')->rules('required'),
            Boolean::make('Is Active', 'is_active')->default(true),
        ];
    }
}
