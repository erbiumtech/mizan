<?php

namespace App\Nova;

use Laravel\Nova\Fields\BelongsTo;
use Laravel\Nova\Fields\Boolean;
use Laravel\Nova\Fields\HasMany;
use Laravel\Nova\Fields\ID;
use Laravel\Nova\Fields\Select;
use Laravel\Nova\Fields\Text;
use Laravel\Nova\Http\Requests\NovaRequest;

class Beneficiary extends Resource
{
    public static $model = \App\Models\Beneficiary::class;

    public static $title = 'name';

    public static $search = ['name', 'account_no', 'iban', 'id_number'];

    public static $group = 'Accounting';

    public function fields(NovaRequest $request): array
    {
        return [
            ID::make()->sortable(),

            Text::make('Name', 'name')->sortable()->rules('required', 'max:255')
                ->help('Non-employee payee: landlord, caterer, vendor…'),

            BelongsTo::make('Bank', 'bank', Bank::class)->nullable()->searchable(),

            Text::make('Account No', 'account_no')->nullable()->hideFromIndex(),

            Text::make('IBAN', 'iban')->nullable()->rules('nullable', 'max:34'),

            Select::make('ID Type', 'id_type')
                ->options(['CNIC' => 'CNIC', 'NTN' => 'NTN'])
                ->nullable()
                ->displayUsingLabels()
                ->hideFromIndex(),

            Text::make('ID Number', 'id_number')->nullable()->hideFromIndex(),

            Text::make('Address Line 1', 'address_line_1')->nullable()->hideFromIndex(),
            Text::make('Address Line 2', 'address_line_2')->nullable()->hideFromIndex(),
            Text::make('Email', 'email')->nullable()->rules('nullable', 'email')->hideFromIndex(),
            Text::make('Phone', 'phone')->nullable()->hideFromIndex(),

            BelongsTo::make('Usual Transaction Type', 'transactionType', TransactionType::class)
                ->nullable()
                ->help('What we usually pay this beneficiary for'),

            Select::make('Default Payment Type', 'payment_type')
                ->options(array_combine(['IBFT', 'BT', 'ACH', 'RTGS', 'LBC'], ['IBFT', 'BT', 'ACH', 'RTGS', 'LBC']))
                ->default('IBFT')
                ->rules('required'),

            Boolean::make('Active', 'is_active')->sortable(),

            HasMany::make('Payments', 'payments', Payment::class),
        ];
    }
}
