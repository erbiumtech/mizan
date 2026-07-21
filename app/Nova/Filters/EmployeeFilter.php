<?php

namespace App\Nova\Filters;

use Laravel\Nova\Filters\Filter;
use Laravel\Nova\Http\Requests\NovaRequest;
use App\Models\Employee;

class EmployeeFilter extends Filter
{
    public $name = 'Employee';

    public function apply(NovaRequest $request, $query, $value)
    {
        return $query->where('employee_id', $value);
    }

    public function options(NovaRequest $request)
    {
        return Employee::with('user')
            ->get()
            ->mapWithKeys(function ($employee) {
                $title = $employee->employee_id . ' - ' . ($employee->user?->name ?? '');
                return [$title => $employee->id];
            })
            ->toArray();
    }
}
