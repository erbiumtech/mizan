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
        $run = \App\Modules\Payroll\Models\PayrollRun::forMonth($month, $fiscalYear);

        if ($run->isLocked()) {
            throw new \InvalidArgumentException(
                "{$run->periodLabel()} payroll has been signed off. Reopen the run to add payslips to it."
            );
        }

        $created = collect();

        $figures = app(AttendanceFigures::class);

        foreach ($this->employeesDueAPayslip($month, $fiscalYear) as $employee) {
            $payslip = Payslip::create([
                'employee_id' => $employee->id,
                'fiscal_year_id' => $fiscalYear->id,
                'month' => $month,
                // Attendance was what payroll could not know. With `leave` and
                // `attendance` licensed it now can, so these come from the records
                // rather than from zeros a clerk has to correct — and AttendanceFigures
                // returns exactly those zeros for a company that has neither module,
                // which is the behaviour this line always had.
                //
                // Zeros still mean "not known", and pro-rating still refuses to divide
                // by them. Filling these in does not by itself change any pay: that
                // needs payroll.prorate_on_attendance, which is off.
                ...$figures->for($employee, $month, $fiscalYear),
                ...$this->overtimeFor($figures, $employee, $month, $fiscalYear),
            ]);

            // Claim the leave days this payslip counted, so no later month counts them
            // again — and so leave approved for a month that is already signed off lands
            // here rather than being lost. Done AFTER creation because it needs the
            // payslip's id, and only here: reading the figures happens on every form
            // render, and a read that claimed days would burn them for whoever looked.
            $figures->settle($employee, $month, $fiscalYear, $payslip);

            $created->push($payslip);
        }

        return $created;
    }

    /**
     * The month's overtime minutes, when attendance can say.
     *
     * Kept separate from the four attendance columns because it is phase 3a rather than
     * phase 3, and because an empty array is the right answer for a company without
     * attendance — writing `overtime_minutes => 0` would claim the month had none,
     * where null says nobody measured.
     *
     * @return array<string, int>
     */
    private function overtimeFor(AttendanceFigures $figures, $employee, string $month, FiscalYear $fiscalYear): array
    {
        $minutes = $figures->overtimeMinutes($employee, $month, $fiscalYear);

        return $minutes > 0 ? ['overtime_minutes' => $minutes] : [];
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
