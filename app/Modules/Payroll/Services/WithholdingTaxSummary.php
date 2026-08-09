<?php

namespace App\Modules\Payroll\Services;

use App\Modules\Core\Models\FiscalYear;
use App\Modules\Payroll\Models\Payslip;
use Illuminate\Support\Collection;

/**
 * Salary withholding tax, by employee and by month.
 *
 * The same data EmployeeWithholdingTaxExport writes into the FBR monthly file, as
 * something readable: the file answers "what do we file this month" and could not
 * answer "what have we withheld from this person this year", which is the question
 * an employee asks and the one a year-end reconciliation needs.
 *
 * Taxable amount is total earnings, the same figure the FBR export puts in
 * Taxable_Amount — deliberately, because a summary that disagreed with the file
 * filed against it would be worse than no summary.
 */
class WithholdingTaxSummary
{
    /**
     * @return array{
     *     fiscal_year: string|null,
     *     month: string|null,
     *     employees: array<int, array{employee_id: int, name: string, nic: string, taxable: float, tax: float, months: int}>,
     *     months: array<int, array{month: string, taxable: float, tax: float, employees: int}>,
     *     taxable_total: float, tax_total: float
     * }
     */
    public function summary(?int $fiscalYearId = null, ?string $month = null): array
    {
        $fiscalYear = $fiscalYearId ? FiscalYear::find($fiscalYearId) : FiscalYear::current();

        $payslips = $this->payslips($fiscalYear?->getKey(), $month);

        return [
            'fiscal_year' => $fiscalYear?->name,
            'fiscal_year_id' => $fiscalYear?->getKey(),
            'month' => $month,
            'employees' => $this->byEmployee($payslips),
            'months' => $this->byMonth($payslips, $fiscalYear),
            'taxable_total' => round((float) $payslips->sum('total_earnings'), 2),
            'tax_total' => round((float) $payslips->sum('withholding_tax'), 2),
        ];
    }

    /**
     * Payslips that actually withheld something.
     *
     * A payslip below the taxable threshold withholds nothing and belongs on no tax
     * return, so it is left out here exactly as the FBR export leaves it out.
     */
    private function payslips(?int $fiscalYearId, ?string $month): Collection
    {
        return Payslip::query()
            ->with('employee.user')
            ->where('withholding_tax', '>', 0)
            ->when($fiscalYearId, fn ($query) => $query->where('fiscal_year_id', $fiscalYearId))
            ->when($month, fn ($query) => $query->where('month', $month))
            ->get();
    }

    /** @return array<int, array<string, mixed>> */
    private function byEmployee(Collection $payslips): array
    {
        return $payslips
            ->groupBy('employee_id')
            ->map(fn (Collection $rows, $employeeId): array => [
                'employee_id' => (int) $employeeId,
                'name' => $rows->first()->employee?->user?->name ?? "Employee #{$employeeId}",
                'nic' => (string) ($rows->first()->employee?->nic ?? ''),
                'taxable' => round((float) $rows->sum('total_earnings'), 2),
                'tax' => round((float) $rows->sum('withholding_tax'), 2),
                'months' => $rows->count(),
            ])
            ->sortByDesc('tax')
            ->values()
            ->all();
    }

    /**
     * In fiscal-year order rather than alphabetically or by whichever payslip was
     * created first: a tax summary read down the page is read in the order the
     * months were paid.
     *
     * @return array<int, array<string, mixed>>
     */
    private function byMonth(Collection $payslips, ?FiscalYear $fiscalYear): array
    {
        $order = $this->monthOrder($fiscalYear);

        return $payslips
            ->groupBy('month')
            ->map(fn (Collection $rows, string $month): array => [
                'month' => $month,
                'taxable' => round((float) $rows->sum('total_earnings'), 2),
                'tax' => round((float) $rows->sum('withholding_tax'), 2),
                'employees' => $rows->pluck('employee_id')->unique()->count(),
            ])
            ->sortBy(fn (array $row): int => $order[$row['month']] ?? 99)
            ->values()
            ->all();
    }

    /** @return array<string, int> month name => position in the fiscal year */
    private function monthOrder(?FiscalYear $fiscalYear): array
    {
        $start = $fiscalYear?->start_date?->copy() ?? now()->startOfYear();
        $order = [];

        for ($i = 0; $i < 12; $i++) {
            $order[$start->copy()->addMonths($i)->format('F')] = $i;
        }

        return $order;
    }
}
