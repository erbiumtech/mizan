<?php

namespace App\Nova;

use App\Nova\Actions\DownloadMprPdf;
use App\Nova\Actions\DownloadSingleMprPdf;
use Illuminate\Contracts\Database\Eloquent\Builder;
use Laravel\Nova\Fields\BelongsTo;
use Laravel\Nova\Fields\Date;
use Laravel\Nova\Fields\ID;
use Laravel\Nova\Fields\Trix;
use Laravel\Nova\Http\Requests\NovaRequest;
use App\Nova\Filters\UserNameFilter;

class MPR extends Resource
{
    public static $model = \App\Models\MPR::class;

    public static $group = 'MPR';

    public static function label()
    {
        return 'MPR';
    }

    public static $search = [
        'id',
        'user.name'
    ];

    public static function indexQuery(NovaRequest $request, $query): Builder
    {
        if ($request->user()->hasRole('Administrator')) {
            return $query;
        }

        return $query->where('user_id', $request->user()->id);
    }

    /**
     * Actions defined for the resource.
     */
    public function actions(NovaRequest $request)
    {
        return [
            (new DownloadSingleMprPdf)
                ->showInline()
                ->showOnDetail()
                ->canRun(function () {
                    return true;
                })->withoutConfirmation(),

            (new DownloadMprPdf)
                ->standalone()
                ->canRun(function () {
                    return true;
                }),
        ];
    }

    /**
     * Fields defined for the resource.
     */
    public function fields(NovaRequest $request)
    {
        return [

            ID::make()->sortable(),

            BelongsTo::make('User', 'user', User::class)
                ->sortable()
                ->rules('required'),

            Date::make('Date', 'mpr_date')
                ->sortable()
                ->rules('required')
                ->default(function () {
                    return now();
                })
                ->displayUsing(function ($date) {
                    return $date ? $date->format('d-m-Y') : null;
                }),

            // 3. Feedback Rich Text Editor
            Trix::make('Feedback', 'feedback')
                ->rules('required')
                ->hideFromIndex()
                ->withFiles('public', 'Mpr'),

            // 4. Topics & Scope Rich Text Editor
            Trix::make('Topics & Scope', 'topics_scope')
                ->rules('required')
                ->hideFromIndex()
                ->withFiles('public', 'Mpr'),

            // 5. Recent Module Rich Text Editor
            Trix::make('Recent Module', 'recent_module')
                ->rules('required')
                ->hideFromIndex()
                ->withFiles('public', 'Mpr'),

            // 6. Employee Request Rich Text Editor
            Trix::make('Employee Request', 'employee_request')
                ->rules('required')
                ->hideFromIndex()
                ->withFiles('public', 'Mpr'),

            // 7. Next Mpr Goal Rich Text Editor
            Trix::make('Next Mpr Goal', 'next_mpr_goal')
                ->rules('required')
                ->hideFromIndex()
                ->withFiles('public', 'Mpr'),

            // 7. What have you learnt this month
            Trix::make('What have you learnt this month?', 'current_month_learning')
                ->rules('required')
                ->hideFromIndex()
                ->withFiles('public', 'Mpr'),
        ];
    }

    public function filters(NovaRequest $request)
    {
        return [
            new UserNameFilter,
        ];
    }
}
