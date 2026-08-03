<?php

namespace App\Modules\Payroll\Services;

use App\Modules\Core\Models\FiscalYear;
use App\Modules\Employees\Models\Employee;
use App\Modules\Employees\Models\EmployeeSetting;
use App\Modules\Payroll\Models\Payslip;
use App\Modules\Payroll\Support\PayrollMonth;
use Illuminate\Support\Collection;

/**
 * Opening a payroll month: a payslip for everyone who should have one.
 *
 * Only the inputs are set. Payslip::booted() runs the calculation, the annual tax
 * sync, the journal posting and the advance recovery, so a payslip the scheduler
 * raises is identical to one somebody adds by hand — there is no second
 * implementation of payroll here to drift from the first.
 */
class MonthlyPayrollService
{
    /**
     * Raise the month's payslips.
     *
     * Idempotent, and deliberately more than the unique key gives: an employee
     * who already has a payslip for the month is skipped rather than recalculated,
     * so a rerun cannot disturb a payslip somebody has since corrected by hand or
     * an employee has already accepted.
     *
     * @return Collection<int, Payslip> the payslips created by this call
     */
    public function openMonth(string $month, FiscalYear $fiscalYear): Collection
    {
        $created = collect();

        foreach ($this->employeesDueAPayslip($month, $fiscalYear) as $employee) {
            $created->push(Payslip::create([
                'employee_id' => $employee->id,
                'fiscal_year_id' => $fiscalYear->id,
                'month' => $month,
                // Attendance is what payroll cannot know: these are the defaults a
                // clerk adjusts, the same ones the form starts from.
                'total_working_days' => 0,
                'paid_days' => 0,
                'lop_days' => 0,
                'leaves_taken' => 0,
            ]));
        }

        return $created;
    }

    /**
     * Employees who should have a payslip this month and do not.
     *
     * An employee needs a salary setting covering the month — somebody with no
     * agreed package cannot be paid, and raising an empty payslip for them would
     * put a zero in the payroll and a name in the bank file.
     *
     * @return Collection<int, Employee>
     */
    public function employeesDueAPayslip(string $month, FiscalYear $fiscalYear): Collection
    {
        $date = PayrollMonth::firstDay($month, $fiscalYear)->toDateString();

        $alreadyHave = Payslip::where('month', $month)
            ->where('fiscal_year_id', $fiscalYear->id)
            ->pluck('employee_id')
            ->all();

        return Employee::query()
            ->where('is_active', 1)
            ->whereNotIn('id', $alreadyHave)
            ->orderBy('id')
            ->get()
            ->filter(fn (Employee $employee): bool => EmployeeSetting::getActiveSettingForDate(
                $employee->id,
                $date,
                $fiscalYear->id,
            ) !== null)
            ->values();
    }

    /**
     * Employees with no package covering the month — reported rather than
     * silently passed over, because a missing setting is usually an oversight
     * rather than a decision.
     *
     * @return Collection<int, Employee>
     */
    public function employeesWithoutASetting(string $month, FiscalYear $fiscalYear): Collection
    {
        $date = PayrollMonth::firstDay($month, $fiscalYear)->toDateString();

        return Employee::query()
            ->where('is_active', 1)
            ->orderBy('id')
            ->get()
            ->filter(fn (Employee $employee): bool => EmployeeSetting::getActiveSettingForDate(
                $employee->id,
                $date,
                $fiscalYear->id,
            ) === null)
            ->values();
    }
}
