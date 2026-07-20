<?php

namespace App\Nova\Metrics;

use App\Models\FiscalYear;
use App\Models\Payslip;
use Laravel\Nova\Http\Requests\NovaRequest;
use Laravel\Nova\Metrics\Partition;
use Laravel\Nova\Metrics\PartitionResult;

/**
 * Net salary paid per employee in the active fiscal year.
 */
class PayrollByEmployee extends Partition
{
    public $name = 'Payroll by Employee (Fiscal Year)';

    public function calculate(NovaRequest $request): PartitionResult
    {
        $fiscalYearId = FiscalYear::where('is_active', true)->value('id');

        $totals = Payslip::query()
            ->when($fiscalYearId, fn ($q) => $q->where('payslips.fiscal_year_id', $fiscalYearId))
            ->join('employees', 'employees.id', '=', 'payslips.employee_id')
            ->join('users', 'users.id', '=', 'employees.user_id')
            ->selectRaw('users.name as employee_name, SUM(payslips.net_salary) as total')
            ->groupBy('users.name')
            ->orderByDesc('total')
            ->pluck('total', 'employee_name')
            ->map(fn ($v) => round((float) $v, 2))
            ->all();

        return $this->result($totals);
    }

    public function uriKey(): string
    {
        return 'payroll-by-employee';
    }
}
