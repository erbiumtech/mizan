<?php

namespace App\Nova;

use App\Nova\Actions\ExportPayroll;
use App\Nova\Actions\RecalculatePayroll;
use Laravel\Nova\Fields\BelongsTo;
use Laravel\Nova\Fields\Boolean;
use Laravel\Nova\Fields\Date;
use Laravel\Nova\Fields\HasMany;
use Laravel\Nova\Fields\ID;
use Laravel\Nova\Fields\Number;
use Laravel\Nova\Fields\Text;
use Laravel\Nova\Http\Requests\NovaRequest;

class Payroll extends Resource
{
    public static $model = \App\Models\Payroll\Payroll::class;

    public static $title = 'hashslug';

    public static $search = [
        'id', 'hashslug', 'month', 'year',
    ];

    public static function label(): string
    {
        return 'Payrolls';
    }

    public function fields(NovaRequest $request): array
    {
        return [
            ID::make()->sortable(),

            Text::make('Hash Slug', 'hashslug')
                ->exceptOnForms()
                ->sortable(),

            BelongsTo::make('User', 'user', User::class)
                ->sortable()
                ->rules('required'),

            Number::make('Month')
                ->min(1)->max(12)->step(1)
                ->sortable()
                ->rules('required', 'integer', 'min:1', 'max:12'),

            Number::make('Year')
                ->min(2000)->max(2100)->step(1)
                ->sortable()
                ->rules('required', 'integer'),

            Date::make('Pay Date', 'date')
                ->sortable()
                ->rules('required'),

            Boolean::make('Locked', 'is_locked')
                ->sortable(),

            HasMany::make('Payslips', 'payslips', Payslip::class),
        ];
    }

    public function actions(NovaRequest $request): array
    {
        return [
            (new RecalculatePayroll)->showInline()->showOnDetail(),
            (new ExportPayroll)->showInline()->showOnDetail(),
        ];
    }
}

