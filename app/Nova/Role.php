<?php

namespace App\Nova;

use Illuminate\Support\Str;
use Laravel\Nova\Fields\BelongsToMany;
use Laravel\Nova\Fields\ID;
use Laravel\Nova\Fields\Slug;
use Laravel\Nova\Fields\Text;
use Laravel\Nova\Http\Requests\NovaRequest;

class Role extends Resource
{
    public static $model = \App\Models\Role::class;

    public static $title = 'name';

    public static $search = [
        'id', 'name', 'slug',
    ];

    public function fields(NovaRequest $request): array
    {
        return [
            ID::make()->sortable(),

            Text::make('Name')
                ->sortable()
                ->rules('required', 'max:255'),

            Slug::make('Slug')
                ->from('Name')
                ->separator('-')
                ->rules('required', 'max:255')
                ->creationRules('unique:roles,slug')
                ->updateRules('unique:roles,slug,{{resourceId}}'),

            BelongsToMany::make('Users', 'users', User::class),
        ];
    }

    public static function afterCreate(NovaRequest $request, $model): void
    {
        if (empty($model->slug)) {
            $model->forceFill(['slug' => Str::slug($model->name)])->save();
        }
    }
}

