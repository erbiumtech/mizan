<?php

namespace App\Nova;

use Laravel\Nova\Fields\BelongsTo;
use Laravel\Nova\Fields\Currency;
use Laravel\Nova\Fields\ID;
use Laravel\Nova\Fields\Text;
use Laravel\Nova\Http\Requests\NovaRequest;

class JournalEntryLine extends Resource
{
    public static $model = \App\Models\JournalEntryLine::class;

    public static $title = 'id';

    public static $search = ['id', 'description'];

    public static $group = 'Accounting';

    public static $displayInNavigation = false;

    public function fields(NovaRequest $request)
    {
        return [
            ID::make()->sortable(),

            BelongsTo::make('Journal Entry', 'journalEntry', JournalEntry::class),

            BelongsTo::make('Account', 'account', Account::class)
                ->searchable()
                ->relatableQueryUsing(function (NovaRequest $request, $query) {
                    return $query->where('is_active', true)
                        ->where('allow_manual_entry', true)
                        ->whereDoesntHave('children');
                }),

            Currency::make('Debit', 'debit_amount')->rules('required', 'numeric', 'min:0')->default(0),

            Currency::make('Credit', 'credit_amount')->rules('required', 'numeric', 'min:0')->default(0),

            Text::make('Description', 'description')->nullable(),
        ];
    }

    // public static function authorizedToCreate(\Illuminate\Http\Request $request)
    // {
    //     return $request->user()->can('create', \App\Models\JournalEntry::class);
    // }
    public static function authorizedToCreate(\Illuminate\Http\Request $request)
    {
        return $request->user()?->can('create', \App\Models\JournalEntry::class) ?? false;
    }
}
