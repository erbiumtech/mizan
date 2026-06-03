<?php

namespace App\Nova;

use Laravel\Nova\Fields\BelongsTo;
use Laravel\Nova\Fields\Currency;
use Laravel\Nova\Fields\ID;
use Laravel\Nova\Fields\Text;
use Laravel\Nova\Fields\Textarea;
use Laravel\Nova\Http\Requests\NovaRequest;

class Deduction extends Resource
{
    public static $model = \App\Models\Deduction\Deduction::class;

    public static $title = 'name';

    public static $search = [
        'id', 'name', 'hashslug',
    ];

    public function fields(NovaRequest $request): array
    {
        return [
            ID::make()->sortable(),

            Text::make('Hash Slug', 'hashslug')->exceptOnForms()->sortable(),

            BelongsTo::make('User', 'user', User::class)->rules('required'),
            BelongsTo::make('Payroll', 'payroll', Payroll::class)->rules('required'),
            BelongsTo::make('Payslip', 'payslip', Payslip::class)->rules('required'),
            BelongsTo::make('Type', 'type', DeductionType::class)
                ->rules('required'),

            Text::make('Name')->sortable()->rules('required', 'max:255'),
            Textarea::make('Description')->nullable(),

            Currency::make('Amount')->sortable()->rules('required', 'numeric', 'min:0'),
        ];
    }
}

