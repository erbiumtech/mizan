<?php

namespace App\Modules\Payroll\Support;

use App\Modules\Core\Models\FiscalYear;
use App\Modules\Core\Models\User;
use App\Modules\Employees\Models\Employee;
use App\Modules\Payroll\Models\PayrollRun;
use App\Modules\Payroll\Models\Payslip;
use App\Support\Contracts\CsvImporter;
use App\Support\PayrollMonth;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Past months, as they were paid — docs/saas-launch-plan.md §14.1.
 *
 * A company arriving mid-year brings its payslips with it. Each row becomes a
 * payslip with the figures as imported — nothing recalculated, because the
 * month was already paid — in a run signed off at once, under a batch id that
 * `payroll:revert-import` can take back whole. Previewed first, as every CSV
 * import here is.
 */
class SalaryHistoryCsvImporter implements CsvImporter
{
    public const COLUMNS = [
        'employee_id', 'month', 'fiscal_year', 'basic_wage', 'medical_allowance', 'petrol_allowance', 'device_allowance',
        'bonus', 'overtime', 'withholding_tax', 'advances', 'meal_deduction', 'esi_health_insurance', 'net_salary',
    ];

    public function key(): string
    {
        return 'salary_history';
    }

    public function label(): string
    {
        return 'Salary history (past payslips, as paid)';
    }

    public function columns(): array
    {
        return self::COLUMNS;
    }

    public function example(): array
    {
        return ['EMP-001', 'July', '2026-2027', '80000', '8000', '5000', '0', '0', '0', '2500', '0', '1200', '0', '89300'];
    }

    public function dateField(): ?array
    {
        return null;
    }

    public function problemWith(array $row): ?string
    {
        $employee = Employee::query()->where('employee_id', trim((string) ($row['employee_id'] ?? '')))->first();

        if ($employee === null) {
            return 'no employee with code '.($row['employee_id'] ?? '');
        }

        $year = FiscalYear::query()->where('name', trim((string) ($row['fiscal_year'] ?? '')))->first();

        if ($year === null) {
            return 'no fiscal year named '.($row['fiscal_year'] ?? '');
        }

        $month = ucfirst(strtolower(trim((string) ($row['month'] ?? ''))));

        if (! array_key_exists($month, PayrollMonth::fiscalOrder($year))) {
            return "{$month} is not a month";
        }

        if (Payslip::query()->where('employee_id', $employee->getKey())->where('month', $month)->where('fiscal_year_id', $year->getKey())->exists()) {
            return "{$employee->employee_id} already has a payslip for {$month} {$year->name}";
        }

        foreach (array_slice(self::COLUMNS, 3) as $column) {
            if (($row[$column] ?? '') !== '' && ! is_numeric($row[$column])) {
                return "{$column} is not a number";
            }
        }

        return null;
    }

    public function write(Collection $rows, ?string $date = null): int
    {
        $batch = 'hist-'.Str::lower(Str::ulid());
        $runs = [];
        $written = 0;

        foreach ($rows as $row) {
            $employee = Employee::query()->where('employee_id', trim((string) $row['employee_id']))->firstOrFail();
            $year = FiscalYear::query()->where('name', trim((string) $row['fiscal_year']))->firstOrFail();
            $month = ucfirst(strtolower(trim((string) $row['month'])));
            $n = fn (string $key): float => round((float) ($row[$key] ?? 0), 2);

            $earnings = $n('basic_wage') + $n('medical_allowance') + $n('petrol_allowance') + $n('device_allowance') + $n('bonus') + $n('overtime');
            $deductions = $n('withholding_tax') + $n('advances') + $n('meal_deduction') + $n('esi_health_insurance');

            $payslip = new Payslip([
                'employee_id' => $employee->getKey(),
                'fiscal_year_id' => $year->getKey(),
                'month' => $month,
                'basic_wage' => $n('basic_wage'),
                'medical_allowance' => $n('medical_allowance'),
                'petrol_allowance' => $n('petrol_allowance'),
                'device_allowance' => $n('device_allowance'),
                'bonus' => $n('bonus'),
                'extra_work_hours' => $n('overtime'),
                'withholding_tax' => $n('withholding_tax'),
                'advances' => $n('advances'),
                'meal_deduction' => $n('meal_deduction'),
                'esi_health_insurance' => $n('esi_health_insurance'),
                'total_earnings' => round($earnings, 2),
                'total_deductions' => round($deductions, 2),
                'net_salary' => ($row['net_salary'] ?? '') !== '' ? $n('net_salary') : round($earnings - $deductions, 2),
            ]);
            $payslip->imported = true;
            $payslip->import_batch = $batch;
            $payslip->save();

            $runs[$payslip->payroll_run_id] = true;
            $written++;
        }

        // Signed off as imported history: a month already paid is not a month to edit. Straight
        // to locked — approval is for a run somebody raised here.
        $by = auth()->user();

        foreach (PayrollRun::query()->whereKey(array_keys($runs))->get() as $run) {
            if (! $run->isLocked()) {
                $run->update(['status' => PayrollRun::STATUS_LOCKED, 'locked_by' => $by instanceof User ? $by->getKey() : null, 'locked_at' => now(), 'notes' => trim(($run->notes ?? '')." Imported history, batch {$batch}.")]);
            }
        }

        return $written;
    }
}
