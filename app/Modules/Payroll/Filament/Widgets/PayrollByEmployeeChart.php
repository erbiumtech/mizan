<?php

namespace App\Modules\Payroll\Filament\Widgets;

use App\Filament\Concerns\WidgetBelongsToModule;
use App\Modules\Core\Models\FiscalYear;
use App\Modules\Employees\Models\Employee;
use App\Modules\Payroll\Models\Payslip;
use App\Support\Reporting\DashboardWidgets;
use Filament\Widgets\ChartWidget;

/**
 * Net salary paid per employee in the active fiscal year — mirrors the Nova
 * PayrollByEmployee partition (pie) card.
 */
class PayrollByEmployeeChart extends ChartWidget
{
    use WidgetBelongsToModule;

    /** What the people cost, last of the people band. */
    protected static bool $isLazy = true;

    /**
     * No polling — `docs/reports-expansion-plan.md` Phase 5.7 asks for it and Filament's default is against
     * it: `CanPoll::$pollingInterval` is `'5s'`, so every widget in this panel was re-running its aggregates
     * every five seconds, per open tab, unasked. On a dashboard of twenty-three widgets that is the cost
     * Phase 5.8's cache exists to avoid, incurred twelve times a minute instead of once a page.
     */
    protected ?string $pollingInterval = null;

    protected static ?int $sort = DashboardWidgets::PEOPLE + 4;

    public function getHeading(): ?string
    {
        return 'Payroll by Employee (Fiscal Year)';
    }

    public static function canView(): bool
    {
        if (! static::moduleIsAvailable()) {
            return false;
        }

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
                    // Brand greens first (see AdminPanelProvider), then a
                    // distinguishable spread for the remaining slices.
                    'backgroundColor' => [
                        '#3E894A', '#91BD55', '#D3DA54', '#2F6B3A', '#B8CE6A',
                        '#1F4E79', '#4F94CD', '#6B7280', '#A78BFA', '#F59E0B',
                    ],
                ],
            ],
            'labels' => $totals->keys()->all(),
        ];
    }
}
