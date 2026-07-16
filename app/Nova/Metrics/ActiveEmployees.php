<?php

namespace App\Nova\Metrics;

use App\Models\Employee;
use Laravel\Nova\Http\Requests\NovaRequest;
use Laravel\Nova\Metrics\Value;
use Laravel\Nova\Metrics\ValueResult;

class ActiveEmployees extends Value
{
    public $name = 'Employees';

    public function calculate(NovaRequest $request): ValueResult
    {
        return $this->result(Employee::where('is_active', 1)->count())
            ->allowZeroResult()
            ->suffix('active');
    }

    public function ranges(): array
    {
        return [];
    }

    public function uriKey(): string
    {
        return 'active-employees';
    }
}
