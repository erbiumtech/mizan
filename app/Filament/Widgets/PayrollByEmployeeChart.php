<?php

namespace App\Filament\Widgets;

use App\Models\Employee;
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

        // Aggregate within the tenant database only. `users` lives in the
        // landlord database, so we resolve employee names via the Eloquent
        // relationship (a separate query on the users connection) instead of
        // a cross-connection SQL JOIN.
        $totalsByEmployee = Payslip::query()
            ->when($fiscalYearId, fn ($q) => $q->where('fiscal_year_id', $fiscalYearId))
            ->selectRaw('employee_id, SUM(net_salary) as total')
            ->groupBy('employee_id')
            ->pluck('total', 'employee_id');

        $employees = Employee::with('user')
            ->whereIn('id', $totalsByEmployee->keys()->all())
            ->get()
            ->keyBy('id');

        $totals = $totalsByEmployee
            ->mapWithKeys(function ($total, $employeeId) use ($employees) {
                $name = $employees->get($employeeId)?->user?->name ?? "Employee #{$employeeId}";

                return [$name => round((float) $total, 2)];
            })
            ->sortDesc();

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
