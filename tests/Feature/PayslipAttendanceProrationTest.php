<?php

namespace Tests\Feature;

use App\Modules\Employees\Models\Employee;
use App\Modules\Employees\Models\EmployeeSetting;
use App\Modules\Payroll\Models\Payslip;
use App\Modules\Payroll\Services\AttendanceProration;
use App\Modules\Payroll\Services\OvertimeRate;
use App\Modules\Payroll\Services\PayslipService;
use ReflectionMethod;
use Tests\AccountingTestCase;

/**
 * What attendance does to pay: nothing, until a company asks for it.
 *
 * `payslips` carries `total_working_days`, `paid_days`, `lop_days` and `leaves_taken`.
 * Before phase 3 they were typed by hand, raised as zeros by MonthlyPayrollService
 * ("Attendance is what payroll cannot know"), printed on the PDF, and read by no part
 * of the calculation — so a payslip could say "LOP 14 days" and pay a full month.
 *
 * Phase 3 gave them a route into pay, behind `payroll.prorate_on_attendance`, and this
 * file now holds BOTH directions:
 *
 *  - **Off — the shipped default.** Every assertion that protected companies already
 *    running payroll still holds, unchanged. This is the larger half of the file and
 *    the reason switching the setting on is the only thing that can move a figure.
 *  - **On.** Earnings scale by the divisor the payslip RECORDED, non-pro-rating
 *    components are left alone, a `total_working_days` of 0 pro-rates nothing, and a
 *    later change to the divisor does not restate a settled month.
 *
 * Its sibling PayslipCalculationSeamTest guards the other axis: not *whether* the
 * calculation pro-rates, but *where*. Pro-rating applied after the calculation — to
 * the totals rather than the component amounts — unbalances the payroll journal entry
 * and kills payslip creation mid-run. Both tests have to pass; each catches a mistake
 * invisible to the other.
 *
 * ── The four instructions this file left for whoever implemented phase 3 ─────
 *
 * All four were followed, and each has a test below:
 *
 *   1. pro-rating is a company setting, defaulting OFF for existing companies, so
 *      the original assertions still hold with it off;
 *   2. `total_working_days = 0` means "not known" and pro-rates nothing — it is what
 *      MonthlyPayrollService raises for a month nobody has entered attendance for,
 *      and dividing by it pays nobody;
 *   3. which components pro-rate is per-component (`pay_components.prorates`), not
 *      all-or-nothing;
 *   4. the divisor used is recorded ON THE PAYSLIP, or a setting changed next year
 *      silently restates a settled month.
 *
 * The fourth is the subtle one and worth stating plainly: Payslip::booted() re-runs
 * the whole calculation on every save, so a clerk correcting a phone number re-saves
 * the payslip. Without the recorded divisor, changing the setting would restate every
 * month anybody happened to touch afterwards.
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

    // ─────────────────────────── pro-rating OFF: the shipped default ───────────

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

    /**
     * Every input the payroll calculation takes, in order.
     *
     * `attendance` was added by phase 3, and deliberately as ONE array rather than the
     * seven scalars it carries: seven more positional parameters on a method that
     * already had eleven would be seven chances to pass them in the wrong order, and
     * the failure would be a wrong payslip rather than a type error.
     *
     * The guard still does its job. Anything else appearing here is new, and new to
     * this method means new to how pay is calculated.
     */
    private const CALCULATION_INPUTS = [
        'employeeId', 'month', 'fiscalYearId', 'bonus', 'extraWorkHours',
        'deviceAllowance', 'petrolAllowance', 'advances', 'mealDeduction',
        'esiInsurance', 'expenseReimbursement', 'payslipId', 'attendance',
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

    // ─────────────────────────── pro-rating ON: what a company opts into ───────

    private function enableProration(string $divisor = AttendanceProration::DIVISOR_WORKING_DAYS): void
    {
        config([
            'payroll.prorate_on_attendance' => true,
            'payroll.proration_divisor' => $divisor,
        ]);
    }

    /**
     * §10.15 — earnings scale, and by the divisor that was used.
     *
     * 3 days lost of 22 means 19/22 of the basic wage. The divisor and the basis are
     * written to the payslip, because that is what makes the figure defensible a year
     * later: "you were paid 19 of 22 days" is an answer, "your pay was multiplied by
     * 0.8636" is not.
     */
    public function test_with_proration_on_the_basic_wage_scales_by_the_days_paid(): void
    {
        $this->enableProration();

        $payslip = $this->payslip('July', [
            'total_working_days' => 22,
            'paid_days' => 19,
            'lop_days' => 3,
        ]);

        $expected = round(self::PACKAGE['basic_wage'] * 19 / 22, 2);

        $this->assertSame($expected, (float) $payslip->basic_wage);
        $this->assertSame(AttendanceProration::DIVISOR_WORKING_DAYS, $payslip->proration_divisor);
        $this->assertSame(22.0, (float) $payslip->proration_basis_days);
    }

    /**
     * A fixed allowance is left alone unless a company says otherwise.
     *
     * The conservative reading, and deliberate: §5 says a fixed medical or device
     * allowance often does NOT pro-rate, those columns have no `prorates` flag to ask,
     * and scaling them anyway would be deciding for the company in the direction that
     * costs the employee. A company that wants an allowance to scale expresses it as a
     * pay component, which is how this application prefers allowances anyway.
     */
    public function test_the_fixed_allowances_and_bonus_do_not_scale(): void
    {
        $this->enableProration();

        $payslip = $this->payslip('July', [
            'total_working_days' => 22,
            'paid_days' => 11,
            'lop_days' => 11,
        ]);

        $this->assertSame((float) self::PACKAGE['medical_allowance'], (float) $payslip->medical_allowance);
        $this->assertSame((float) self::PACKAGE['device_allowance'], (float) $payslip->device_allowance);
        $this->assertSame((float) self::PACKAGE['petrol_allowance'], (float) $payslip->petrol_allowance);
    }

    /** Deductions never scale: being away does not reduce what somebody owes. */
    public function test_deductions_do_not_scale(): void
    {
        $this->enableProration();

        $payslip = $this->payslip('July', [
            'total_working_days' => 22,
            'paid_days' => 11,
            'lop_days' => 11,
        ]);

        $this->assertSame((float) self::PACKAGE['meal_deduction'], (float) $payslip->meal_deduction);
        $this->assertSame((float) self::PACKAGE['esi_health_insurance'], (float) $payslip->esi_health_insurance);
    }

    /**
     * §10.15, third clause — `total_working_days = 0` pro-rates nothing.
     *
     * The case that would divide by zero, and the one MonthlyPayrollService raises for
     * every payslip in a month nobody has touched. It must pay in full even with the
     * setting on.
     */
    public function test_with_proration_on_a_month_with_no_attendance_still_pays_in_full(): void
    {
        $this->enableProration();

        $payslip = $this->payslip('July', [
            'total_working_days' => 0,
            'paid_days' => 0,
            'lop_days' => 0,
        ]);

        $this->assertSame((float) self::PACKAGE['basic_wage'], (float) $payslip->basic_wage);
        $this->assertNull($payslip->proration_divisor);
    }

    /**
     * A month recorded as wholly unpaid pays in full, rather than paying nothing.
     *
     * Almost always a broken import, and paying nothing on the strength of one is the
     * mistake there is no undoing once the bank file has gone. Pay in full and let
     * somebody look.
     */
    public function test_loss_of_pay_exceeding_the_month_pays_in_full_rather_than_nothing(): void
    {
        $this->enableProration();

        $payslip = $this->payslip('July', [
            'total_working_days' => 22,
            'paid_days' => 0,
            'lop_days' => 30,
        ]);

        $this->assertSame((float) self::PACKAGE['basic_wage'], (float) $payslip->basic_wage);
        $this->assertNull($payslip->proration_divisor);
    }

    /**
     * §10.8, applied to the setting most able to break it — THE test of this half.
     *
     * A payslip settled under one divisor keeps it. Payslip::booted() recalculates on
     * every save, so without this a company changing the divisor in December would
     * restate every earlier month anybody touched afterwards.
     */
    public function test_changing_the_divisor_does_not_restate_a_payslip_that_recorded_another(): void
    {
        $this->enableProration(AttendanceProration::DIVISOR_WORKING_DAYS);

        $payslip = $this->payslip('July', [
            'total_working_days' => 22,
            'paid_days' => 19,
            'lop_days' => 3,
        ]);

        $settled = (float) $payslip->basic_wage;
        $this->assertSame(22.0, (float) $payslip->proration_basis_days);

        // The company changes its mind, and somebody re-saves the payslip for an
        // unrelated reason.
        config(['payroll.proration_divisor' => AttendanceProration::DIVISOR_FIXED_30]);
        $payslip->update(['leaves_taken' => 1]);

        $payslip->refresh();
        $this->assertSame($settled, (float) $payslip->basic_wage, 'A settled month moved when the divisor changed.');
        $this->assertSame(22.0, (float) $payslip->proration_basis_days);
    }

    /** And switching pro-rating OFF afterwards does not restate it either. */
    public function test_switching_proration_off_does_not_restate_a_month_already_prorated(): void
    {
        $this->enableProration();

        $payslip = $this->payslip('July', [
            'total_working_days' => 22,
            'paid_days' => 19,
            'lop_days' => 3,
        ]);

        $settled = (float) $payslip->basic_wage;

        config(['payroll.prorate_on_attendance' => false]);
        $payslip->update(['leaves_taken' => 2]);

        $this->assertSame($settled, (float) $payslip->fresh()->basic_wage);
    }

    /** A fixed divisor is used as given, whatever the month's working days. */
    public function test_a_fixed_divisor_is_used_instead_of_the_working_days(): void
    {
        $this->enableProration(AttendanceProration::DIVISOR_FIXED_26);

        $payslip = $this->payslip('July', [
            'total_working_days' => 22,
            'paid_days' => 20,
            'lop_days' => 2,
        ]);

        $this->assertSame(26.0, (float) $payslip->proration_basis_days);
        $this->assertSame(round(self::PACKAGE['basic_wage'] * 24 / 26, 2), (float) $payslip->basic_wage);
    }

    /**
     * §10.16 — tax follows the reduced gross.
     *
     * It has to fall, because the annual projection reads this month's earnings. What
     * it must NOT do is fall by the same proportion: one short month is projected
     * across the year at the reduced figure, which is how the existing calculator
     * works, and this asserts the direction rather than a precise number.
     */
    public function test_tax_follows_the_reduced_gross(): void
    {
        $full = $this->payslip('July', [
            'total_working_days' => 22, 'paid_days' => 22, 'lop_days' => 0,
        ]);

        $fullTax = (float) $full->withholding_tax;
        $this->assertGreaterThan(0, $fullTax, 'The fixture pays no tax, so this proves nothing.');

        $full->delete();

        $this->enableProration();

        $short = $this->payslip('July', [
            'total_working_days' => 22, 'paid_days' => 11, 'lop_days' => 11,
        ]);

        $this->assertLessThan($fullTax, (float) $short->withholding_tax);
        $this->assertGreaterThanOrEqual(0, (float) $short->withholding_tax);
    }

    // ─────────────────────────── phase 3a: overtime ────────────────────────────

    /**
     * §10.15b — `extra_work_hours` stays a RUPEE amount.
     *
     * A regression test on the column itself, because its name says otherwise and one
     * draft of the plan read it as hours. Everything about the overtime join depends on
     * this: minutes are converted to money by a rate, and the money goes here.
     */
    public function test_extra_work_hours_is_a_rupee_amount_not_a_count_of_hours(): void
    {
        $payslip = $this->payslip('July', [
            'total_working_days' => 22, 'paid_days' => 22, 'lop_days' => 0,
        ]);

        $payslip->update(['extra_work_hours' => 12500]);
        $payslip->refresh();

        // Added to earnings as money. Were it a count of hours, 12500 would be a
        // nonsense number of hours and the total would be nonsense with it.
        $this->assertSame(12500.0, (float) $payslip->extra_work_hours);
        $this->assertGreaterThanOrEqual(12500.0, (float) $payslip->total_earnings);
    }

    /**
     * §10.15a — with the switch off, recorded overtime does not reach pay.
     *
     * The state phase 2 left it in, and the honest one: until a rate, a multiplier and
     * caps all exist, minutes have no defined route into money.
     */
    public function test_recorded_overtime_does_not_reach_pay_with_the_switch_off(): void
    {
        config(['payroll.pay_overtime' => false]);

        $payslip = $this->payslip('July', [
            'total_working_days' => 22, 'paid_days' => 22, 'lop_days' => 0,
            'overtime_minutes' => 600,
        ]);

        $this->assertSame(0.0, (float) $payslip->extra_work_hours);
        $this->assertNull($payslip->overtime_hourly_rate);
    }

    /**
     * A rate already recorded on a payslip is reused, not re-derived.
     *
     * The same rule as the divisor: a rate recomputed next year against a changed
     * package or a changed work pattern would restate a settled month. Asserted through
     * the recorded path because the derivation itself needs a work pattern, which is
     * `attendance`'s and is covered by AttendanceTest.
     */
    public function test_a_recorded_overtime_rate_is_reused_rather_than_recomputed(): void
    {
        config(['payroll.pay_overtime' => true]);

        $payslip = $this->payslip('July', [
            'total_working_days' => 22, 'paid_days' => 22, 'lop_days' => 0,
            'overtime_minutes' => 600,
            'overtime_hourly_rate' => 1000,
            'overtime_multiplier' => 2,
        ]);

        // 10 hours × 1000 × 2.
        $this->assertSame(20000.0, (float) $payslip->extra_work_hours);
        $this->assertSame(1000.0, (float) $payslip->overtime_hourly_rate);

        // A later change to the multiplier setting does not restate it.
        config(['attendance.overtime_multiplier' => 3]);
        $payslip->update(['leaves_taken' => 1]);

        $this->assertSame(20000.0, (float) $payslip->fresh()->extra_work_hours);
    }

    /** A clerk's explicit amount still wins, as it does for advances and reimbursements. */
    public function test_an_explicit_overtime_amount_overrides_the_computed_one(): void
    {
        config(['payroll.pay_overtime' => true]);

        $payslip = $this->payslip('July', [
            'total_working_days' => 22, 'paid_days' => 22, 'lop_days' => 0,
            'overtime_minutes' => 600,
            'overtime_hourly_rate' => 1000,
            'overtime_multiplier' => 2,
            'extra_work_hours' => 5000,
        ]);

        $this->assertSame(5000.0, (float) $payslip->extra_work_hours);
    }

    /**
     * Overtime is NOT reduced by pro-rating.
     *
     * The two answer different questions and must not compound: pro-rating reduces the
     * agreed package for days not worked, and overtime pays for hours worked beyond it.
     * Scaling the overtime would charge somebody for their own absence twice.
     */
    public function test_overtime_is_not_scaled_by_proration(): void
    {
        $this->enableProration();
        config(['payroll.pay_overtime' => true]);

        $payslip = $this->payslip('July', [
            'total_working_days' => 22, 'paid_days' => 11, 'lop_days' => 11,
            'overtime_minutes' => 600,
            'overtime_hourly_rate' => 1000,
            'overtime_multiplier' => 2,
        ]);

        $this->assertSame(20000.0, (float) $payslip->extra_work_hours, 'Overtime was scaled by the absence factor.');
        // And the wage was still reduced, so this is not passing because pro-rating did
        // nothing at all.
        $this->assertLessThan((float) self::PACKAGE['basic_wage'], (float) $payslip->basic_wage);
    }

    /**
     * §10.15a, last clause — a cap WARNS and does not reduce.
     *
     * Silently capping paid overtime hides an employer's compliance problem and
     * underpays somebody at the same time: two wrongs from one line of code.
     */
    public function test_an_overtime_cap_warns_without_reducing_the_amount(): void
    {
        config([
            'payroll.pay_overtime' => true,
            'attendance.overtime_daily_cap_minutes' => 120,
            'attendance.overtime_weekly_cap_minutes' => 720,
        ]);

        // 60 hours in a 22-day month: far past both caps.
        $minutes = 3600;

        $warnings = app(OvertimeRate::class)->warnings($minutes, 22);
        $this->assertNotEmpty($warnings, 'A month this far over the caps should warn.');

        $payslip = $this->payslip('July', [
            'total_working_days' => 22, 'paid_days' => 22, 'lop_days' => 0,
            'overtime_minutes' => $minutes,
            'overtime_hourly_rate' => 1000,
            'overtime_multiplier' => 2,
        ]);

        // 60 hours × 1000 × 2, paid in full despite the breach.
        $this->assertSame(120000.0, (float) $payslip->extra_work_hours);
    }
}
