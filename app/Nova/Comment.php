<?php

namespace App\Nova;

use Laravel\Nova\Fields\Badge;
use Laravel\Nova\Fields\BelongsTo;
use Laravel\Nova\Fields\DateTime;
use Laravel\Nova\Fields\ID;
use Laravel\Nova\Fields\MorphTo;
use Laravel\Nova\Fields\Textarea;
use Laravel\Nova\Http\Requests\NovaRequest;

class Comment extends Resource
{
    public static $model = \App\Models\Comment::class;

    public static $title = 'body';

    public static $search = ['body'];

    public static $group = 'Audit';

    public static $displayInNavigation = false;

    public function fields(NovaRequest $request)
    {
        return [
            ID::make()->sortable(),

            MorphTo::make('On', 'commentable')->types([
                Payslip::class,
            ])->exceptOnForms(),

            BelongsTo::make('By', 'user', User::class)
                ->default(fn ($request) => $request->user()->id)
                ->exceptOnForms(),

            Textarea::make('Comment', 'body')->rules('required')->alwaysShow(),

            Badge::make('Status', fn () => $this->isResolved() ? 'resolved' : 'open')->map([
                'open' => 'warning',
                'resolved' => 'success',
            ]),

            BelongsTo::make('Resolved By', 'resolver', User::class)->onlyOnDetail(),

            DateTime::make('Created', 'created_at')->exceptOnForms()->sortable(),
        ];
    }

    public function actions(NovaRequest $request)
    {
        return [
            Actions\ResolveComment::make()
                ->showInline()
                ->canRun(fn ($request, $comment) => $request->user()->can('resolve', $comment)),
        ];
    }
}
