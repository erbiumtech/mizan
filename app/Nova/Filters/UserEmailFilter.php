<?php

namespace App\Nova\Filters;

use Laravel\Nova\Filters\Filter;
use Laravel\Nova\Http\Requests\NovaRequest;
use App\Models\User;

class UserEmailFilter extends Filter
{
    public $name = 'User Email';

    public function apply(NovaRequest $request, $query, $value)
    {
        return $query->where('id', $value);
    }

    public function options(NovaRequest $request)
    {
        return User::pluck('id', 'email')->toArray();
    }
}
