<?php

namespace App\Filament\Widgets;

use App\Models\FiscalYear;
use App\Models\Payslip;
use Filament\Widgets\ChartWidget;

/**
 * Net salary paid per employee in the active fiscal year — mirrors the Nova
 * PayrollByEmployee partition (pie) card.
 */
class PayrollByEmployeeChart extends ChartWidget
{
    protected static ?int $sort = 4;

    public function getHeading(): ?string
    {
        return 'Payroll by Employee (Fiscal Year)';
    }

    public static function canView(): bool
    {
        return (bool) auth()->user()?->can('PayslipCreate');
    }

    protected function getType(): string
    {
        return 'pie';
    }

    protected function getData(): array
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
            ->map(fn ($v) => round((float) $v, 2));

        return [
            'datasets' => [
                [
                    'label' => 'Net Salary',
                    'data' => $totals->values()->all(),
                    'backgroundColor' => [
                        '#f59e0b', '#3b82f6', '#22c55e', '#ef4444', '#8b5cf6',
                        '#ec4899', '#14b8a6', '#eab308', '#6366f1', '#f97316',
                    ],
                ],
            ],
            'labels' => $totals->keys()->all(),
        ];
    }
}
