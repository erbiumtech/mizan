<?php

namespace App\Nova;

use App\Nova\Actions\DownloadMprPdf;
use App\Nova\Actions\DownloadSingleMprPdf;
use App\Services\RoleService;
use Illuminate\Contracts\Database\Eloquent\Builder;       // Top Header Dynamic Comparison Action
use Laravel\Nova\Fields\BelongsTo;
use Laravel\Nova\Fields\Date;
use Laravel\Nova\Fields\ID;
use Laravel\Nova\Fields\Trix;
use Laravel\Nova\Http\Requests\NovaRequest;

class MPR extends Resource
{
    public static $model = \App\Models\MPR::class;

    public static function label()
    {
        return 'MPR';
    }

    // Search kis base par ho
    public static $search = [
        'id',
    ];

    /**
     * Actions defined for the resource.
     */
    public function actions(NovaRequest $request)
    {
        return [
            // 1. Yeh action sirf 3-dots dropdown me individual row par dikhe ga
            (new DownloadSingleMprPdf)
                ->showInline()
                ->showOnDetail(),

            // 2. Yeh action standalone helper ki wajah se sirf TOP HEADER par dikhe ga
            (new DownloadMprPdf)
                ->standalone(),
        ];
    }

    /**
     * Build an "index" query for the given resource.
     */
    public static function indexQuery(NovaRequest $request, Builder $query): Builder
    {
        $user = $request->user();

        if ($user) {
            $roleService = new RoleService;

            if (!$roleService->isAdmin($user)) {
                return $query->where('user_id', $user->id);
            }
        }

        return $query;
    }

    /**
     * Fields defined for the resource.
     */
    public function fields(NovaRequest $request)
    {
        return [
            // ID: Sirf listing aur detail par dikhe gi, database se khud aye gi
            ID::make()->sortable(),

            // 1. Users Dropdown (Har page par dikhe ga, listing par user ka name show kare ga)
            BelongsTo::make('User', 'user', User::class)
                ->sortable()
                ->rules('required'),

            // 2. Date Picker (Listing, Create aur Edit par show hoga)
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
                ->hideFromIndex() // Listing se hide kiya taake table clean rahe
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
                ->hideFromIndex() // Isko optional rakh sakte hain
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
}
