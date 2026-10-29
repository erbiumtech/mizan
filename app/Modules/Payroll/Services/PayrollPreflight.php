<?php

namespace App\Modules\Payroll\Services;

use App\Modules\Core\Models\FiscalYear;
use App\Modules\Employees\Models\Employee;
use App\Support\PayrollMonth;
use Illuminate\Support\Carbon;

/**
 * Before payslips are raised — docs/saas-launch-plan.md §14.1.
 *
 * One rule: a run never charges a day nobody has marked without saying so.
 * Blockers refuse the run — an active employee with no package, a bank-paid
 * employee with no account — because raising it would make payslips that are
 * wrong by construction. Warnings are shown and acknowledged — unmarked days,
 * listed by employee; a run before the month has ended — because the clerk may
 * know something the records do not.
 */
class PayrollPreflight
{
    public function __construct(
        private readonly MonthlyPayrollService $payroll,
        private readonly AttendanceFigures $figures,
    ) {}

    /** @return array{blockers: array<int, string>, warnings: array<int, string>} */
    public function check(string $month, FiscalYear $fiscalYear, ?Carbon $today = null): array
    {
        $today ??= Carbon::now();
        $blockers = [];
        $warnings = [];

        $withoutPackage = $this->payroll->employeesWithoutASetting($month, $fiscalYear)->load('user');

        if ($withoutPackage->isNotEmpty()) {
            $blockers[] = 'No salary package covering the month: '.$withoutPackage->map(fn (Employee $e): string => $e->display_label)->join(', ').'.';
        }

        $due = $this->payroll->employeesDueAPayslip($month, $fiscalYear)->load('user');

        if ($this->paidByBank()) {
            $noAccount = $due->filter(fn (Employee $e): bool => blank($e->bank_account_no) && blank($e->iban_no));

            if ($noAccount->isNotEmpty()) {
                $blockers[] = 'Paid by bank with no account number or IBAN: '.$noAccount->map(fn (Employee $e): string => $e->display_label)->join(', ').'.';
            }
        }

        $first = PayrollMonth::firstDay($month, $fiscalYear);
        $last = $first->copy()->endOfMonth();

        if ($today->lt($last)) {
            $warnings[] = "{$first->format('F Y')} has not ended: payslips raised today will not see attendance after {$today->format('d M')}.";
        }

        if (modules()->enabled('attendance')) {
            $unmarked = [];

            foreach ($due as $employee) {
                $days = $this->figures->unmarkedDays($employee, $month, $fiscalYear, $today);

                if ($days > 0) {
                    $unmarked[] = "{$employee->display_label} ({$days})";
                }
            }

            if ($unmarked !== []) {
                $warnings[] = 'Working days with nothing marked, which pay as present: '.implode(', ', $unmarked).'.';
            }
        }

        return ['blockers' => $blockers, 'warnings' => $warnings];
    }

    public function paidByBank(): bool
    {
        return (bool) setting('payroll.pay_by_bank', config('payroll.pay_by_bank', true));
    }
}
