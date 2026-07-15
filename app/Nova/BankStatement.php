<?php

namespace App\Nova;

use App\Nova\Actions\AutoMatchStatement;
use App\Nova\Actions\CompleteReconciliation;
use App\Nova\Actions\ImportStatementLines;
use Laravel\Nova\Fields\Badge;
use Laravel\Nova\Fields\BelongsTo;
use Laravel\Nova\Fields\Currency;
use Laravel\Nova\Fields\Date;
use Laravel\Nova\Fields\DateTime;
use Laravel\Nova\Fields\HasMany;
use Laravel\Nova\Fields\ID;
use Laravel\Nova\Fields\Text;
use Laravel\Nova\Http\Requests\NovaRequest;

class BankStatement extends Resource
{
    public static $model = \App\Models\BankStatement::class;

    public static $title = 'id';

    public static $search = ['id'];

    public static $group = 'Accounting';

    public function title()
    {
        return "Statement #{$this->id}";
    }

    public function fields(NovaRequest $request)
    {
        return [
            ID::make()->sortable(),

            BelongsTo::make('Bank Account', 'account', Account::class)
                ->relatableQueryUsing(fn (NovaRequest $request, $query) => $query->postable()->ofType('asset'))
                ->rules('required'),

            Date::make('Statement Date', 'statement_date')->rules('required')->sortable(),

            Currency::make('Opening Balance', 'opening_balance')->rules('numeric'),

            Currency::make('Closing Balance', 'closing_balance')->rules('numeric'),

            Badge::make('Status', 'status')->map([
                'draft' => 'info',
                'in_progress' => 'warning',
                'completed' => 'success',
            ])->sortable()->filterable(),

            Text::make('Progress', fn () => $this->lines()->count()
                ? "{$this->matchedCount()} / {$this->lines()->count()} matched"
                : '—')->exceptOnForms(),

            BelongsTo::make('Completed By', 'completedBy', User::class)->onlyOnDetail(),

            DateTime::make('Completed At', 'completed_at')->onlyOnDetail(),

            HasMany::make('Lines', 'lines', BankStatementLine::class),
        ];
    }

    public function actions(NovaRequest $request)
    {
        return [
            ImportStatementLines::make()
                ->canRun(fn ($request, $statement) => $request->user()?->can('import', $statement) ?? false),

            AutoMatchStatement::make()
                ->showInline()
                ->canRun(fn ($request, $statement) => $request->user()?->can('match', $statement) ?? false),

            CompleteReconciliation::make()
                ->showInline()
                ->canRun(fn ($request, $statement) => $request->user()?->can('complete', $statement) ?? false),
        ];
    }
}
