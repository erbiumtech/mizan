<?php

namespace App\Nova;

use App\Nova\Actions\ExcludeStatementLine;
use App\Nova\Actions\MatchStatementLine;
use App\Nova\Actions\UnmatchStatementLine;
use Laravel\Nova\Fields\Badge;
use Laravel\Nova\Fields\BelongsTo;
use Laravel\Nova\Fields\Currency;
use Laravel\Nova\Fields\Date;
use Laravel\Nova\Fields\ID;
use Laravel\Nova\Fields\Text;
use Laravel\Nova\Http\Requests\NovaRequest;

class BankStatementLine extends Resource
{
    public static $model = \App\Models\BankStatementLine::class;

    public static $title = 'description';

    public static $search = ['description', 'reference'];

    public static $group = 'Accounting';

    public static $displayInNavigation = false;

    public function fields(NovaRequest $request)
    {
        return [
            ID::make()->sortable(),

            BelongsTo::make('Statement', 'bankStatement', BankStatement::class)->exceptOnForms(),

            Date::make('Transaction Date', 'transaction_date')->rules('required')->sortable(),

            Text::make('Description', 'description')->nullable(),

            Text::make('Reference', 'reference')->nullable(),

            Currency::make('Amount', 'amount')->rules('required', 'numeric')
                ->help('Signed: positive = money in, negative = money out'),

            Badge::make('Match Status', 'match_status')->map([
                'unmatched' => 'danger',
                'auto_matched' => 'success',
                'manually_matched' => 'success',
                'excluded' => 'info',
            ])->sortable()->filterable(),

            BelongsTo::make('Matched Ledger Line', 'matchedLine', JournalEntryLine::class)
                ->nullable()->exceptOnForms(),
        ];
    }

    public function actions(NovaRequest $request)
    {
        return [
            MatchStatementLine::make()
                ->showInline()
                ->canRun(fn ($request, $line) => $request->user()?->can('match', $line) ?? false),

            UnmatchStatementLine::make()
                ->showInline()
                ->canRun(fn ($request, $line) => $request->user()?->can('match', $line) ?? false),

            ExcludeStatementLine::make()
                ->showInline()
                ->canRun(fn ($request, $line) => $request->user()?->can('match', $line) ?? false),
        ];
    }
}
