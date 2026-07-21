<?php

namespace App\Nova\Filters;

use Laravel\Nova\Filters\Filter;
use Laravel\Nova\Http\Requests\NovaRequest;
use App\Models\Employee;

class EmployeeNameFilter extends Filter
{
    public $name = 'Employee Name';

    public function apply(NovaRequest $request, $query, $value)
    {
        return $query->where('id', $value);
    }

    public function options(NovaRequest $request)
    {
        return Employee::with('user')
            ->get()
            ->mapWithKeys(function ($employee) {
                $name = $employee->user?->name ?? 'Unknown';
                return [$name => $employee->id];
            })
            ->toArray();
    }
}
