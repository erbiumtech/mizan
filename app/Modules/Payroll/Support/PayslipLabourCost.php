<?php

namespace App\Modules\Payroll\Support;

use App\Modules\Core\Models\FiscalYear;
use App\Modules\Employees\Models\Employee;
use App\Modules\Payroll\Models\Payslip;
use App\Modules\Payroll\Services\OvertimeRate;
use App\Support\Contracts\LabourCost;
use Illuminate\Support\Carbon;

/**
 * An hour's cost, from the payslip that paid for it.
 *
 * The month's `total_earnings` — everything the payslip paid the employee, before deductions — over the
 * hours they were contracted for that month. The contracted hours are the work pattern's, exactly as
 * `OvertimeRate` reads them for the overtime rate, so the two figures cannot disagree about how long a month
 * is; a company without Attendance has no pattern to ask, and falls back to the payslip's own working days
 * at eight hours each.
 *
 * `total_earnings` rather than `basic_wage`: an allowance is as much a cost of the hour as the basic is,
 * and a project margin that ignored a 25,000 fuel allowance would flatter every project by that much.
 * Employer statutory contributions (EOBI, social security) are not in it — they are the company's cost too,
 * but they are posted from a different figure and adding them here is the day somebody asks.
 *
 * Memoised per employee and month, because the report asks for every entry and a year of timesheets is
 * thousands of them against a few dozen payslips.
 */
class PayslipLabourCost implements LabourCost
{
    /** @var array<string, float|null> */
    private array $memo = [];

    public function __construct(private readonly OvertimeRate $overtime) {}

    public function hourlyFor(int|string $employeeId, string $on): ?float
    {
        $date = Carbon::parse($on)->startOfMonth();
        $key = $employeeId.'-'.$date->format('Y-m');

        if (array_key_exists($key, $this->memo)) {
            return $this->memo[$key];
        }

        return $this->memo[$key] = $this->compute((int) $employeeId, $date);
    }

    private function compute(int $employeeId, Carbon $firstDay): ?float
    {
        $fiscalYear = FiscalYear::query()
            ->whereDate('start_date', '<=', $firstDay->toDateString())
            ->whereDate('end_date', '>=', $firstDay->toDateString())
            ->first();

        if ($fiscalYear === null) {
            return null;
        }

        $payslip = Payslip::query()
            ->where('employee_id', $employeeId)
            ->where('month', $firstDay->monthName)
            ->where('fiscal_year_id', $fiscalYear->getKey())
            ->first();

        if ($payslip === null || (float) $payslip->total_earnings <= 0) {
            return null;
        }

        $employee = Employee::query()->find($employeeId);

        $hours = $employee ? $this->overtime->contractedHoursIn($employee, $firstDay) : 0.0;

        if ($hours <= 0) {
            // ponytail: eight hours a day when no work pattern can say. A company on nine-hour days reads 12% cheap
            // here until it licenses Attendance and gives its people a pattern.
            $hours = (float) $payslip->total_working_days * 8;
        }

        return $hours > 0 ? round((float) $payslip->total_earnings / $hours, 4) : null;
    }
}
