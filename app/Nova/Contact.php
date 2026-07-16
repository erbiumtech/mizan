<?php

namespace App\Nova;

use Laravel\Nova\Fields\Badge;
use Laravel\Nova\Fields\BelongsTo;
use Laravel\Nova\Fields\Boolean;
use Laravel\Nova\Fields\HasMany;
use Laravel\Nova\Fields\ID;
use Laravel\Nova\Fields\Select;
use Laravel\Nova\Fields\Text;
use Laravel\Nova\Http\Requests\NovaRequest;

class Contact extends Resource
{
    public static $model = \App\Models\Contact::class;

    public static $title = 'name';

    public static $search = ['name', 'email', 'ntn'];

    public static $group = 'Invoicing';

    public function fields(NovaRequest $request): array
    {
        return [
            ID::make()->sortable(),

            Text::make('Name', 'name')->sortable()->rules('required', 'max:255'),

            Badge::make('Kind', 'kind')->map([
                'customer' => 'info',
                'supplier' => 'warning',
                'both' => 'success',
            ])->sortable(),

            Select::make('Kind', 'kind')
                ->options(['customer' => 'Customer', 'supplier' => 'Supplier', 'both' => 'Both'])
                ->rules('required')
                ->onlyOnForms(),

            Text::make('Email', 'email')->nullable()->rules('nullable', 'email')->hideFromIndex(),
            Text::make('Phone', 'phone')->nullable()->hideFromIndex(),
            Text::make('Address Line 1', 'address_line_1')->nullable()->hideFromIndex(),
            Text::make('Address Line 2', 'address_line_2')->nullable()->hideFromIndex(),
            Text::make('NTN', 'ntn')->nullable()->hideFromIndex(),
            Text::make('CNIC', 'cnic')->nullable()->hideFromIndex(),

            BelongsTo::make('Bank', 'bank', Bank::class)
                ->nullable()
                ->hideFromIndex()
                ->help('For paying suppliers through the bank payment flow'),

            Boolean::make('Active', 'is_active')->sortable(),

            HasMany::make('Invoices', 'invoices', Invoice::class),
        ];
    }
}
