<?php

namespace App\Nova;

use Laravel\Nova\Fields\Badge;
use Laravel\Nova\Fields\BelongsTo;
use Laravel\Nova\Fields\Boolean;
use Laravel\Nova\Fields\Currency;
use Laravel\Nova\Fields\HasMany;
use Laravel\Nova\Fields\ID;
use Laravel\Nova\Fields\Select;
use Laravel\Nova\Fields\Text;
use Laravel\Nova\Fields\Textarea;
use Laravel\Nova\Http\Requests\NovaRequest;

class Account extends Resource
{
    public static $model = \App\Models\Account::class;

    public static $title = 'name';

    public static $search = ['code', 'name'];

    public static $group = 'Accounting';

    public static function label()
    {
        return 'Chart of Accounts';
    }

    public function title()
    {
        return "{$this->code} — {$this->name}";
    }

    public function fields(NovaRequest $request)
    {
        return [
            ID::make()->sortable(),

            Text::make('Code', 'code')
                ->rules('required', 'max:20')
                ->creationRules('unique:accounts,code')
                ->updateRules('unique:accounts,code,{{resourceId}}')
                ->sortable(),

            Text::make('Name', 'name')->rules('required', 'max:255')->sortable(),

            Select::make('Type', 'type')->options([
                'asset' => 'Asset',
                'liability' => 'Liability',
                'equity' => 'Equity',
                'income' => 'Income',
                'expense' => 'Expense',
            ])->displayUsingLabels()->rules('required')->sortable()->filterable(),

            Badge::make('Normal Balance', 'normal_balance')->map([
                'debit' => 'info',
                'credit' => 'warning',
            ])->exceptOnForms(),

            BelongsTo::make('Parent Account', 'parent', Account::class)->nullable()->searchable(),

            Boolean::make('Active', 'is_active')->sortable()->filterable(),

            Boolean::make('Allow Manual Entry', 'allow_manual_entry')->hideFromIndex(),

            Textarea::make('Description', 'description')->nullable()->hideFromIndex(),

            Currency::make('Balance', 'balance')->exceptOnForms()->sortable(),

            HasMany::make('Sub Accounts', 'children', Account::class),

            HasMany::make('Ledger Lines', 'lines', JournalEntryLine::class),
        ];
    }
}
