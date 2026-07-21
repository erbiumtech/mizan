<?php

namespace App\Nova\Filters;

use Laravel\Nova\Filters\Filter;
use Laravel\Nova\Http\Requests\NovaRequest;
use App\Models\FiscalYear;

class FiscalYearFilter extends Filter
{
    public $name = 'Fiscal Year';

  
    public function apply(NovaRequest $request, $query, $value)
    {
        return $query->where('fiscal_year_id', $value);
    }

    
    public function options(NovaRequest $request)
    {
        return FiscalYear::pluck('id', 'name')->toArray();
    }
}