<?php

namespace App\Nova\Filters;

use Laravel\Nova\Filters\Filter;
use Laravel\Nova\Http\Requests\NovaRequest;
use App\Models\User;

class UserNameFilter extends Filter
{
    public $name = 'User Name';

    public function apply(NovaRequest $request, $query, $value)
    {
        return $query->where('user_id', $value);
    }

    public function options(NovaRequest $request)
    {
        return User::pluck('id', 'name')->toArray();
    }
}
