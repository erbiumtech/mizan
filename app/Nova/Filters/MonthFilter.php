<?php

namespace App\Nova\Filters;

use Laravel\Nova\Filters\Filter;
use Laravel\Nova\Http\Requests\NovaRequest;

class MonthFilter extends Filter
{
    public $name = 'Month';

    public function apply(NovaRequest $request, $query, $value)
    {
        return $query->where('month', $value);
    }

    public function options(NovaRequest $request)
    {
        return [
            'January' => 'January', 'February' => 'February', 'March' => 'March',
            'April' => 'April', 'May' => 'May', 'June' => 'June',
            'July' => 'July', 'August' => 'August', 'September' => 'September',
            'October' => 'October', 'November' => 'November', 'December' => 'December',
        ];
    }
}
