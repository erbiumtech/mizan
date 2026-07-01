<?php

namespace App\Nova;

use Laravel\Nova\Fields\ID;
use Laravel\Nova\Fields\Text;
use Laravel\Nova\Fields\Email;
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
            Text::make('Name', 'name')->rules('required'),
            Email::make('Email', 'email')->rules('required', 'email' , 'unique:users,email'),
        ];
    }

    public static function afterCreate(NovaRequest $request, $model)
    {
        $model->syncRoles(['Employee']);

        \App\Models\Employee::create([
            'user_id'     => $model->id,
            'employee_id' => 'EMP-' . $model->id,
            'rate_per_hour' => 0,
            'salary'      => 0,
            'is_active'   => 1,
        ]);
    }
}
