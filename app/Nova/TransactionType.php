<?php

namespace App\Nova;

use Laravel\Nova\Fields\BelongsTo;
use Laravel\Nova\Fields\Boolean;
use Laravel\Nova\Fields\HasMany;
use Laravel\Nova\Fields\ID;
use Laravel\Nova\Fields\Text;
use Laravel\Nova\Http\Requests\NovaRequest;

class TransactionType extends Resource
{
    public static $model = \App\Models\TransactionType::class;

    public static $title = 'name';

    public static $search = ['name', 'code'];

    public static $group = 'Accounting';

    public function fields(NovaRequest $request): array
    {
        return [
            ID::make()->sortable(),

            Text::make('Name', 'name')
                ->sortable()
                ->rules('required', 'max:100')
                ->creationRules('unique:transaction_types,name')
                ->updateRules('unique:transaction_types,name,{{resourceId}}'),

            Text::make('Code', 'code')
                ->sortable()
                ->rules('required', 'max:50')
                ->creationRules('unique:transaction_types,code')
                ->updateRules('unique:transaction_types,code,{{resourceId}}')
                ->help('Stable slug, e.g. salary, rent, food'),

            BelongsTo::make('Default Account', 'account', Account::class)
                ->nullable()
                ->searchable()
                ->help('Expense/liability account debited when a payment of this type is approved'),

            Text::make('Description', 'description')->nullable()->hideFromIndex(),

            Boolean::make('Active', 'is_active')->sortable(),

            HasMany::make('Payments', 'payments', Payment::class),
            HasMany::make('Company Bank Accounts', 'companyBankAccounts', CompanyBankAccount::class),
        ];
    }
}
