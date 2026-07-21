<?php

namespace App\Nova\Filters;

use Laravel\Nova\Filters\Filter;
use Laravel\Nova\Http\Requests\NovaRequest;
use App\Models\Employee;

class EmployeeEmailFilter extends Filter
{
    public $name = 'Employee Email';

    public function apply(NovaRequest $request, $query, $value)
    {
        return $query->where('id', $value);
    }

    public function options(NovaRequest $request)
    {
        return Employee::with('user')
            ->get()
            ->mapWithKeys(function ($employee) {
                $email = $employee->user?->email ?? 'No Email';
                return [$email => $employee->id];
            })
            ->toArray();
    }
}
