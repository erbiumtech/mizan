<?php

namespace App\Nova;

use Laravel\Nova\Fields\ID;
use Laravel\Nova\Fields\Text;
use Laravel\Nova\Fields\Email;
use Laravel\Nova\Http\Requests\NovaRequest;

class User extends Resource
{
    // Tumhara User Model link ho gaya
    public static $model = \App\Models\User::class;

    public static $title = 'name';

    public static $search = [
        'id', 'name', 'email',
    ];

    public function fields(NovaRequest $request)
    {
        return [
            // 1. ID field (Database se khud hi aye gi)
            ID::make()->sortable(),

            // 2. Name field
            Text::make('Name')
                ->sortable()
                ->rules('required', 'max:255'),

            // 3. Email field (Validation ke sath taake unique rahe)
            Email::make('Email')
                ->sortable()
                ->rules('required', 'email', 'max:255')
                ->creationRules('unique:users,email')
                ->updateRules('unique:users,email,{{resourceId}}'),
        ];
    }
}
