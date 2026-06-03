<?php

namespace App\Nova;

use App\Nova\Actions\RecalculatePayslip;
use Laravel\Nova\Fields\BelongsTo;
use Laravel\Nova\Fields\Boolean;
use Laravel\Nova\Fields\Currency;
use Laravel\Nova\Fields\HasMany;
use Laravel\Nova\Fields\ID;
use Laravel\Nova\Fields\Text;
use Laravel\Nova\Http\Requests\NovaRequest;

class Payslip extends Resource
{
    public static $model = \App\Models\Payslip\Payslip::class;

    public static $title = 'hashslug';

    public static $search = [
        'id', 'hashslug',
    ];

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

            BelongsTo::make('Payroll', 'payroll', Payroll::class)
                ->sortable()
                ->rules('required'),

            Currency::make('Total Earning', 'total_earning')
                ->sortable(),

            Currency::make('Total Deduction', 'total_deduction')
                ->sortable(),

            Currency::make('Basic Salary', 'basic_salary')
                ->rules('required'),

            Currency::make('Gross Salary', 'gross_salary')
                ->sortable(),

            Currency::make('Net Salary', 'net_salary')
                ->sortable(),

            Boolean::make('Verified', 'is_verified'),
            Boolean::make('Approved', 'is_approved'),
            Boolean::make('Locked', 'is_locked'),

            HasMany::make('Earnings', 'earnings', Earning::class),
            HasMany::make('Deductions', 'deductions', Deduction::class),
        ];
    }

    public function actions(NovaRequest $request): array
    {
        return [
            (new RecalculatePayslip)->showInline()->showOnDetail(),
        ];
    }
}

