<?php

namespace App\Modules\Payroll\Services;

use App\Modules\Core\Models\FiscalYear;
use App\Modules\Employees\Models\Employee;
use App\Modules\Payroll\Models\Payslip;
use App\Support\PayrollMonth;
use Illuminate\Support\Collection;

/**
 * The monthly EOBI return, as a file.
 *
 * Modelled on the FBR tax file this application already produces, and for the same reason:
 * the scheme wants a monthly submission per employee, and building it by hand from a
 * payroll report is where the transcription errors come from.
 *
 * **What it is not.** It does not submit anything, and it does not compute what should
 * have been deducted — it reports what the payslips actually carry. If a company has not
 * put the EOBI component on anybody's package, the return is empty, and that is the
 * correct answer rather than a silently-invented one: a file full of contributions nobody
 * deducted would be a false declaration.
 *
 * The contribution basis is the MINIMUM WAGE rather than actual pay, which is why the
 * employee figure is the same for everybody and why a rate applied to a real salary would
 * be several times too large. See StatutoryContributions::eobi().
 */
class EobiReturn
{
    public function __construct(private readonly StatutoryContributions $statutory) {}

    /**
     * One row per employee paid in the month.
     *
     * @return Collection<int, array{
     *     employee_code: string, name: string, cnic: ?string,
     *     employee_contribution: float, employer_contribution: float,
     * }>
     */
    public function rows(string $month, FiscalYear $fiscalYear): Collection
    {
        $components = $this->statutory->components();

        $employeeCode = $components[StatutoryContributions::COMPONENT_EOBI_EMPLOYEE]->id ?? null;
        $employerCode = $components[StatutoryContributions::COMPONENT_EOBI_EMPLOYER]->id ?? null;

        return Payslip::query()
            ->where('month', $month)
            ->where('fiscal_year_id', $fiscalYear->getKey())
            ->with(['employee.user', 'components'])
            ->get()
            ->map(function (Payslip $payslip) use ($employeeCode, $employerCode): ?array {
                $employee = $payslip->employee;

                if (! $employee) {
                    return null;
                }

                // Read from the payslip's own component rows, not recomputed. A return has
                // to agree with what was paid; recomputing would produce a file that
                // disagrees with the payslips it claims to describe the moment a rate
                // changes.
                $employeeAmount = (float) $payslip->components
                    ->where('pay_component_id', $employeeCode)
                    ->sum('amount');

                $employerAmount = (float) $payslip->components
                    ->where('pay_component_id', $employerCode)
                    ->sum('amount');

                if ($employeeAmount <= 0 && $employerAmount <= 0) {
                    return null;
                }

                return [
                    'employee_code' => (string) $employee->employee_id,
                    'name' => $employee->user?->name ?? $employee->name ?? '',
                    'cnic' => $employee->nic,
                    'employee_contribution' => round($employeeAmount, 2),
                    'employer_contribution' => round($employerAmount, 2),
                ];
            })
            ->filter()
            ->values();
    }

    /**
     * The return as CSV.
     *
     * A plain file rather than the scheme's own format, deliberately: PRAL-style
     * integrations for EOBI vary by province and by year, and shipping a parser for one
     * would be a driver nobody here can test — the same position §4.2 takes on biometric
     * devices. A CSV every submission portal accepts is the useful boundary.
     */
    public function csv(string $month, FiscalYear $fiscalYear): string
    {
        $rows = $this->rows($month, $fiscalYear);

        $lines = ['employee_code,name,cnic,employee_contribution,employer_contribution'];

        foreach ($rows as $row) {
            $lines[] = implode(',', [
                $this->escape($row['employee_code']),
                $this->escape($row['name']),
                $this->escape((string) $row['cnic']),
                number_format($row['employee_contribution'], 2, '.', ''),
                number_format($row['employer_contribution'], 2, '.', ''),
            ]);
        }

        return implode("\n", $lines)."\n";
    }

    /**
     * What the return totals, for the payment that follows it.
     *
     * @return array{employees: int, employee_total: float, employer_total: float, grand_total: float}
     */
    public function summary(string $month, FiscalYear $fiscalYear): array
    {
        $rows = $this->rows($month, $fiscalYear);

        $employee = round($rows->sum('employee_contribution'), 2);
        $employer = round($rows->sum('employer_contribution'), 2);

        return [
            'employees' => $rows->count(),
            'employee_total' => $employee,
            'employer_total' => $employer,
            'grand_total' => round($employee + $employer, 2),
        ];
    }

    /**
     * Whether this month can produce a return at all, and why not when it cannot.
     *
     * Said in words rather than returning an empty file: "no rows" and "you have not set
     * the EOBI component up" look identical in a CSV, and only one of them is somebody's
     * fault.
     */
    public function readiness(string $month, FiscalYear $fiscalYear): ?string
    {
        $components = $this->statutory->components();

        if (! isset($components[StatutoryContributions::COMPONENT_EOBI_EMPLOYEE])) {
            return 'The EOBI pay component does not exist in this company, so no payslip can carry a contribution. '
                .'Run the statutory component seeder, or create it by hand pointing at the EOBI Payable account.';
        }

        if ($this->rows($month, $fiscalYear)->isEmpty()) {
            return 'No payslip in '.PayrollMonth::firstDay($month, $fiscalYear)->format('F Y')
                .' carries an EOBI amount. The component exists but is not on anybody\'s package — '
                .'add it to the employees it applies to, then run this again.';
        }

        return null;
    }

    /** Employees whose package is below the provincial minimum wage. Warnings only. */
    public function minimumWageWarnings(): array
    {
        return $this->statutory->minimumWageBreaches();
    }

    private function escape(string $value): string
    {
        return str_contains($value, ',') || str_contains($value, '"')
            ? '"'.str_replace('"', '""', $value).'"'
            : $value;
    }

    /** Kept so a caller can name the employees a return covers without re-querying. */
    public function employees(string $month, FiscalYear $fiscalYear): Collection
    {
        return Employee::query()
            ->whereIn('employee_id', $this->rows($month, $fiscalYear)->pluck('employee_code'))
            ->get();
    }
}
