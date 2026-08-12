<?php

namespace Tests\Feature;

use App\Modules\Employees\Models\Employee;
use App\Modules\Employees\Models\EmployeeSetting;
use App\Modules\Payroll\Models\Payslip;
use App\Modules\Payroll\Services\PayslipService;
use ReflectionMethod;
use Tests\AccountingTestCase;

/**
 * What attendance does to pay today: nothing.
 *
 * `payslips` carries `total_working_days`, `paid_days`, `lop_days` and
 * `leaves_taken`. They are typed by hand on the payslip form, raised as zeros by
 * MonthlyPayrollService ("Attendance is what payroll cannot know"), and printed on
 * the payslip PDF — and no part of the calculation reads them. PayslipService
 * ::calculateByParams() is not even *given* them: it takes the employee, the
 * month, the fiscal year and a handful of overrides, and reads the rest from the
 * agreed package in EmployeeSetting.
 *
 * So a payslip can say "LOP 14 days" and pay a full month, and that is not a bug
 * in the sense that anything is broken — it is the absence of a leave and
 * attendance module. The columns are a record of attendance, not an input to pay.
 *
 * ── If you are reading this because the test failed ─────────────────────────
 *
 * Then you are implementing pro-rating (docs/hrms-plan.md §5), and this test is
 * the thing that was protecting every company already running payroll. Do not
 * delete it. Turn it into the switched-off case:
 *
 *   - pro-rating is a company setting, defaulting OFF for existing companies, so
 *     these assertions must still hold with it off;
 *   - `total_working_days = 0` means "not known" and must pro-rate nothing — it is
 *     what MonthlyPayrollService raises for every payslip in a month nobody has
 *     entered attendance for, and dividing by it pays nobody;
 *   - which components pro-rate is per-component (`pay_components.prorates`), not
 *     all-or-nothing;
 *   - the divisor used belongs on the payslip, or a setting changed next year
 *     silently restates a settled month.
 *
 * A characterisation test: it pins what the code does, not what it ought to do.
 * That is the point — the behaviour is load-bearing for existing payrolls until
 * somebody deliberately changes it.
 */
class PayslipAttendanceProrationTest extends AccountingTestCase
{
    private Employee $employee;

    /** The agreed monthly package: what a full month pays, whatever the attendance. */
    private const PACKAGE = [
        'basic_wage' => 200000,
        'medical_allowance' => 20000,
        'device_allowance' => 5000,
        'petrol_allowance' => 13500,
        'meal_deduction' => 2000,
        'esi_health_insurance' => 1500,
    ];

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs($this->makeUser('Administrator', 'proration@test.local'));

        $this->employee = Employee::create([
            'user_id' => $this->makeUser('Employee', 'attendee@test.local')->id,
            'employee_id' => 'EMP-ATT-1',
            'phone' => '0300-0000000',
            'gender' => 'Male',
            'is_active' => 1,
        ]);

        EmployeeSetting::create([
            'employee_id' => $this->employee->id,
            'fiscal_year_id' => $this->fiscalYear->id,
            'start_date' => '2026-07-01',
            'end_date' => '2027-06-30',
            ...self::PACKAGE,
        ]);
    }

    /**
     * @param  array<string, float|int>  $attendance
     */
    private function payslip(string $month, array $attendance): Payslip
    {
        return Payslip::create([
            'employee_id' => $this->employee->id,
            'fiscal_year_id' => $this->fiscalYear->id,
            'month' => $month,
            ...$attendance,
        ])->fresh();
    }

    /** The three figures a company would notice moving. */
    private function money(Payslip $payslip): array
    {
        return [
            'basic_wage' => (float) $payslip->basic_wage,
            'total_earnings' => (float) $payslip->total_earnings,
            'total_deductions' => (float) $payslip->total_deductions,
            'net_salary' => (float) $payslip->net_salary,
        ];
    }

    public function test_a_month_of_loss_of_pay_pays_the_same_as_a_full_month(): void
    {
        $full = $this->payslip('July', [
            'total_working_days' => 22,
            'paid_days' => 22,
            'lop_days' => 0,
            'leaves_taken' => 0,
        ]);

        $mostlyAbsent = $this->payslip('August', [
            'total_working_days' => 22,
            'paid_days' => 8,
            'lop_days' => 14,
            'leaves_taken' => 14,
        ]);

        // The comparison is only worth making if the two months genuinely differ.
        // Two identical fixtures would satisfy every assertion below while
        // demonstrating nothing — so the difference is asserted, not assumed.
        $this->assertNotSame(
            (float) $full->paid_days,
            (float) $mostlyAbsent->paid_days,
            'Both fixtures have the same attendance, so this test compares a month with itself.',
        );

        $this->assertSame(
            $this->money($full),
            $this->money($mostlyAbsent),
            'Attendance is recorded on the payslip and does not reach the calculation.',
        );

        // Not a zero-sum coincidence: there is real money on the payslip.
        $this->assertGreaterThan(0, $this->money($full)['net_salary']);
        $this->assertSame((float) self::PACKAGE['basic_wage'], $this->money($mostlyAbsent)['basic_wage']);
    }

    public function test_changing_the_attendance_leaves_the_net_where_it_was(): void
    {
        // The update path matters on its own: saving a payslip re-runs the whole
        // calculation (Payslip::booted()), so this asserts the recalculation
        // ignores attendance rather than that nothing recalculated.
        $payslip = $this->payslip('July', [
            'total_working_days' => 22,
            'paid_days' => 22,
            'lop_days' => 0,
        ]);

        $before = $this->money($payslip);
        $attendanceBefore = (float) $payslip->paid_days;

        $payslip->update(['paid_days' => 11, 'lop_days' => 11, 'leaves_taken' => 11]);

        // Same precaution: an update that did not move the attendance would leave
        // the net unchanged for the uninteresting reason.
        $this->assertNotSame(
            $attendanceBefore,
            (float) $payslip->fresh()->paid_days,
            'The attendance did not actually change, so this proves nothing about the recalculation.',
        );

        $this->assertSame($before, $this->money($payslip->fresh()));
    }

    public function test_a_payslip_with_no_attendance_recorded_pays_the_full_month(): void
    {
        // Exactly what MonthlyPayrollService::openMonth() raises: all four columns
        // zero. Zero working days must never mean zero pay, and it is the case a
        // pro-rating divisor would divide by.
        $raised = $this->payslip('July', [
            'total_working_days' => 0,
            'paid_days' => 0,
            'lop_days' => 0,
            'leaves_taken' => 0,
        ]);

        $this->assertSame((float) self::PACKAGE['basic_wage'], (float) $raised->basic_wage);
        $this->assertGreaterThan(0, (float) $raised->net_salary);
    }

    public function test_the_attendance_figures_are_still_stored_and_readable(): void
    {
        // Unused by the calculation is not the same as unused: the figures are on
        // the payslip PDF, and a leave module will read them back. Deleting the
        // columns as dead weight would be the wrong cleanup.
        $payslip = $this->payslip('July', [
            'total_working_days' => 22,
            'paid_days' => 19,
            'lop_days' => 3,
            'leaves_taken' => 2,
        ]);

        $this->assertSame(22.0, (float) $payslip->total_working_days);
        $this->assertSame(19.0, (float) $payslip->paid_days);
        $this->assertSame(3.0, (float) $payslip->lop_days);
        $this->assertSame(2.0, (float) $payslip->leaves_taken);
    }

    /** Every input the payroll calculation takes, in order. */
    private const CALCULATION_INPUTS = [
        'employeeId', 'month', 'fiscalYearId', 'bonus', 'extraWorkHours',
        'deviceAllowance', 'petrolAllowance', 'advances', 'mealDeduction',
        'esiInsurance', 'expenseReimbursement', 'payslipId',
    ];

    public function test_the_calculation_takes_no_input_it_does_not_take_today(): void
    {
        // Asserted at the seam rather than through the outcome, because this is the
        // reason the outcome holds: the calculation cannot pro-rate on a number it
        // never receives. Adding an input is the moment pro-rating starts, and it
        // should fail here first, with this file's docblock as the instructions.
        //
        // The whole list is pinned rather than searched for attendance-sounding
        // words. The first version of this test looked for 'paidday', 'lop',
        // 'attendance' and three more — a guess at what somebody would call the
        // parameter, and `$daysWorked`, `$presentDays` or `$divisor` would all have
        // walked straight past it. What the seam can state with certainty is what
        // it takes *today*; anything else is new, and new is the signal.
        $parameters = collect((new ReflectionMethod(PayslipService::class, 'calculateByParams'))->getParameters())
            ->map(fn ($parameter) => $parameter->getName())
            ->all();

        $this->assertSame(
            self::CALCULATION_INPUTS,
            $parameters,
            "The inputs to PayslipService::calculateByParams() have changed.\n"
            .'Added: '.(implode(', ', array_diff($parameters, self::CALCULATION_INPUTS)) ?: 'none')."\n"
            .'Removed: '.(implode(', ', array_diff(self::CALCULATION_INPUTS, $parameters)) ?: 'none')."\n"
            .'If the new input is attendance, read this file\'s docblock before going further: '
            .'pro-rating must be a company setting, off by default for companies already running '
            .'payroll, and it must not divide by a total_working_days of zero. '
            .'If it is unrelated, add it to CALCULATION_INPUTS.',
        );
    }
}
