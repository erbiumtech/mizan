<?php

namespace App\Nova;

use Laravel\Nova\Fields\Boolean;
use Laravel\Nova\Fields\HasMany;
use Laravel\Nova\Fields\ID;
use Laravel\Nova\Fields\Text;
use Laravel\Nova\Http\Requests\NovaRequest;

class Bank extends Resource
{
    public static $model = \App\Models\Bank::class;

    public static $title = 'bank_name';

    public static $search = ['bank_code', 'bank_name', 'bank_short_code'];

    public static $group = 'Accounting';

    public function fields(NovaRequest $request): array
    {
        return [
            ID::make()->sortable(),

            Text::make('Bank Code', 'bank_code')
                ->sortable()
                ->rules('required', 'max:20')
                ->creationRules('unique:banks,bank_code')
                ->updateRules('unique:banks,bank_code,{{resourceId}}')
                ->help('IMD code used in IBFT bank files'),

            Text::make('Bank Name', 'bank_name')
                ->sortable()
                ->rules('required', 'max:255'),

            Text::make('Bank Short Code', 'bank_short_code')
                ->sortable()
                ->nullable()
                ->rules('nullable', 'max:20')
                ->help('Common abbreviation, e.g. HBL, MCB'),

            Boolean::make('Active', 'is_active')->sortable(),

            HasMany::make('Employees', 'employees', Employee::class),
        ];
    }
}
