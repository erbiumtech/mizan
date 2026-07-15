<?php

namespace App\Nova;

use Laravel\Nova\Fields\Code;
use Laravel\Nova\Fields\DateTime;
use Laravel\Nova\Fields\ID;
use Laravel\Nova\Fields\MorphTo;
use Laravel\Nova\Fields\Select;
use Laravel\Nova\Fields\Text;
use Laravel\Nova\Http\Requests\NovaRequest;

class ActivityLog extends Resource
{
    public static $model = \Spatie\Activitylog\Models\Activity::class;

    public static $title = 'description';

    public static $search = ['id', 'description', 'log_name'];

    public static $group = 'Audit';

    public static function label()
    {
        return 'Activity Log';
    }

    public function fields(NovaRequest $request)
    {
        return [
            ID::make()->sortable(),

            Text::make('Model', 'log_name')->sortable()->filterable(),

            Select::make('Event', 'event')->options([
                'created' => 'Created',
                'updated' => 'Updated',
                'deleted' => 'Deleted',
            ])->displayUsingLabels()->sortable()->filterable(),

            Text::make('Description', 'description')->onlyOnDetail(),

            MorphTo::make('Subject', 'subject')->onlyOnDetail(),

            Text::make('Causer', function () {
                return $this->causer?->name ?? 'System';
            })->sortable(),

            Code::make('Changes', 'attribute_changes')->json()->onlyOnDetail(),

            Code::make('Extra Properties', 'properties')->json()->onlyOnDetail(),

            DateTime::make('When', 'created_at')->sortable()->filterable(),
        ];
    }

    public static function authorizedToCreate(\Illuminate\Http\Request $request)
    {
        return false;
    }

    public function authorizedToUpdate(\Illuminate\Http\Request $request)
    {
        return false;
    }

    public function authorizedToDelete(\Illuminate\Http\Request $request)
    {
        return false;
    }

    public function authorizedToReplicate(\Illuminate\Http\Request $request)
    {
        return false;
    }
}
