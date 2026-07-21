<?php

namespace App\Nova;

use App\Models\Employee;
use App\Nova\Filters\UserEmailFilter;
use App\Nova\Filters\UserNameFilter;
use Laravel\Nova\Fields\Email;
use Laravel\Nova\Fields\ID;
use Laravel\Nova\Fields\Password;
use Laravel\Nova\Fields\Text;
use Laravel\Nova\Http\Requests\NovaRequest;

class User extends Resource
{
    public static $model = \App\Models\User::class;

    public static $title = 'name';

    public static $search = ['id', 'name', 'email'];

    public function fields(NovaRequest $request): array
    {
        return [
            ID::make()->sortable(),

            Text::make('Name', 'name')
                ->rules('required', 'max:255'),

            Email::make('Email', 'email')
                ->rules('required', 'email', 'max:255')
                ->creationRules('unique:users,email')
                ->updateRules('unique:users,email,{{resourceId}}'),

            Password::make('Password')
                ->creationRules('required', 'min:8')
                ->updateRules('nullable', 'min:8')
                ->hideFromIndex(),
        ];
    }

    public static function afterCreate(NovaRequest $request, $model)
    {
        $model->syncRoles(['Employee']);

        Employee::create([
            'user_id' => $model->id,
            'employee_id' => 'EMP-'.$model->id,
            'is_active' => 1,
        ]);
    }

    public function filters(NovaRequest $request)
    {
        return [
            new UserNameFilter,
            new UserEmailFilter,
        ];
    }
}
