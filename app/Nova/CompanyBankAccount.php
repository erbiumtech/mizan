<?php

namespace App\Nova;

use Laravel\Nova\Fields\BelongsTo;
use Laravel\Nova\Fields\Boolean;
use Laravel\Nova\Fields\ID;
use Laravel\Nova\Fields\Text;
use Laravel\Nova\Http\Requests\NovaRequest;

class CompanyBankAccount extends Resource
{
    public static $model = \App\Models\CompanyBankAccount::class;

    public static $title = 'title';

    public static $search = ['title', 'account_no', 'iban'];

    public static $group = 'Accounting';

    public function fields(NovaRequest $request): array
    {
        return [
            ID::make()->sortable(),

            Text::make('Title', 'title')->sortable()->rules('required', 'max:255'),

            BelongsTo::make('Bank', 'bank', Bank::class)->nullable()->searchable(),

            Text::make('Account No', 'account_no')
                ->rules('required', 'max:50')
                ->displayUsing(fn ($value) => $value ? str_repeat('•', max(strlen($value) - 4, 0)).substr($value, -4) : null)
                ->onlyOnIndex(),

            Text::make('Account No', 'account_no')->rules('required', 'max:50')->hideFromIndex(),

            Text::make('IBAN', 'iban')->nullable()->rules('nullable', 'max:34')->hideFromIndex(),

            BelongsTo::make('Purpose (Transaction Type)', 'transactionType', TransactionType::class)
                ->nullable()
                ->help('What this account is earmarked for: Salary, Rent, Food…'),

            Boolean::make('Default for its type', 'is_default')
                ->help('Only one default per transaction type; saving unsets the others'),

            Boolean::make('Active', 'is_active')->sortable(),
        ];
    }
}
