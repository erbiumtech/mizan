<?php

namespace Tests\Feature;

use App\Modules\Core\Models\CompanyModule;
use App\Modules\Employees\Models\Employee;
use App\Modules\Employees\Models\EmployeeSetting;
use App\Modules\Payroll\Models\Payslip;
use App\Modules\Payroll\Services\PayslipService;
use Illuminate\Support\Facades\Schema;
use Tests\AccountingTestCase;
use Tests\Concerns\InteractsWithTenant;

/**
 * One place decides what a payslip pays.
 *
 * `Payslip::booted()` calls `PayslipService::calculateByParams()` and copies the
 * result onto the model. This test asserts the copy is faithful: every figure the
 * calculation produces that the payslip has a column for is stored **unchanged**.
 * Nothing between the service and the database may adjust a number.
 *
 * ── Why this exists, and it is not hypothetical ──────────────────────────────
 *
 * `Payslip::booted()` was deliberately mutated to pro-rate pay on attendance the
 * way anyone would reach for first — scale `basic_wage`, `total_earnings` and
 * `net_salary` by `paid_days / total_working_days` after the service returned.
 * The result was not a wrong payslip. It was:
 *
 *   InvalidArgumentException: Entry is not balanced:
 *   debits 211227.27 != credits 208626.82   (JournalEntryService.php:348)
 *
 * because PayrollPostingService books debits from the earning figures and credits
 * from the deductions and the net payable, so scaling one side leaves the other
 * where it was and payslip *creation* dies mid-run. The ledger caught it, which is
 * luck: it caught it at the end of the chain, with an error message about
 * accounting for a mistake made in payroll.
 *
 * This test catches the same mistake at the seam where it is made, and names it.
 *
 * ── Its relationship to PayslipAttendanceProrationTest ───────────────────────
 *
 * Complementary, not overlapping — pro-rating can be got wrong in two distinct
 * ways and each test sees one of them:
 *
 *   - that test fails when the calculation is *given* an attendance figure, i.e.
 *     when pro-rating is introduced at all, and its docblock says what the design
 *     requires (a company setting, defaulting off for existing companies);
 *   - this one fails when a figure is adjusted *after* the calculation, i.e. when
 *     pro-rating is introduced in the wrong place.
 *
 * Doing it correctly — inside `calculateByParams()`, on the component amounts the
 * posting reads — fails the first and passes this one. That is the intended
 * signal, not a nuisance.
 */
class PayslipCalculationSeamTest extends AccountingTestCase
{
    use InteractsWithTenant;

    private Employee $employee;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs($this->makeUser('Administrator', 'seam@test.local'));

        $this->employee = Employee::create([
            'user_id' => $this->makeUser('Employee', 'seam-emp@test.local')->id,
            'employee_id' => 'EMP-SEAM-1',
            'phone' => '0300-0000000',
            'gender' => 'Male',
            'is_active' => 1,
        ]);

        EmployeeSetting::create([
            'employee_id' => $this->employee->id,
            'fiscal_year_id' => $this->fiscalYear->id,
            'start_date' => '2026-07-01',
            'end_date' => '2027-06-30',
            'basic_wage' => 200000,
            'medical_allowance' => 20000,
            'device_allowance' => 5000,
            'petrol_allowance' => 13500,
            'advances' => 10000,
            'meal_deduction' => 2000,
            'esi_health_insurance' => 1500,
        ]);
    }

    /**
     * @param  array<string, float|int|string>  $attributes
     */
    private function payslip(array $attributes = []): Payslip
    {
        return Payslip::create([
            'employee_id' => $this->employee->id,
            'fiscal_year_id' => $this->fiscalYear->id,
            'month' => 'July',
            // Deliberately a month with days lost, not a clean 22 out of 22.
            // A full month makes any attendance-proportional adjustment a
            // multiplication by one — invisible, and the fixture would pass
            // while the mistake this file exists to catch went through. Found by
            // mutating the model and watching this test go green.
            'total_working_days' => 22,
            'paid_days' => 19,
            'lop_days' => 3,
            ...$attributes,
        ])->fresh();
    }

    /**
     * The fixture must contain the condition that makes the mistake visible.
     *
     * The mistake this file exists to catch is proportional: a figure scaled by
     * `paid_days / total_working_days` somewhere after the calculation. On a full
     * month that factor is 1, the scaling is a multiplication by one, and every
     * assertion below passes over the bug — which is exactly what happened when
     * this test was first written and checked against a deliberately mutated
     * `Payslip::booted()`.
     *
     * So the precondition is asserted rather than left to a comment: a fixture
     * tidied back to a clean 22-out-of-22 fails here, loudly, instead of going
     * green and quietly protecting nothing.
     */
    private function assertFixtureCouldDetectAProportionalAdjustment(Payslip $payslip): void
    {
        $working = (float) $payslip->total_working_days;
        $paid = (float) $payslip->paid_days;

        $this->assertGreaterThan(0, $working, 'The fixture needs working days for a ratio to exist at all.');

        $this->assertLessThan(
            $working,
            $paid,
            'This fixture has no lost days, so a figure scaled by paid_days / total_working_days '
            .'would be scaled by 1 and this test could not see it. Give the payslip real LOP.',
        );
    }

    /**
     * Recompute from the payslip as it stands, exactly as `Payslip::booted()`
     * does, and assert the stored figures are the returned ones.
     *
     * The columns compared are **derived** — every key the service returns that
     * the `payslips` table has a column for — so a figure added to the
     * calculation later is covered without anyone remembering to add it here.
     * A derived list can also come back empty and pass over nothing, which is
     * precisely how ModuleCoverageTest once went green while asserting nothing
     * (docs/modules-plan.md §13), so the count is asserted first.
     */
    private function assertStoredFiguresAreTheCalculatedOnes(Payslip $payslip): void
    {
        $this->assertFixtureCouldDetectAProportionalAdjustment($payslip);

        $calculated = app(PayslipService::class)->calculateByParams(
            $payslip->employee_id,
            $payslip->month,
            $payslip->fiscal_year_id,
            $payslip->bonus,
            $payslip->extra_work_hours,
            $payslip->device_allowance,
            $payslip->petrol_allowance,
            $payslip->advances,
            $payslip->meal_deduction,
            $payslip->esi_health_insurance,
            $payslip->expense_reimbursement,
            $payslip->id,
        );

        $this->assertNotNull($calculated, 'The calculation returned nothing for a payslip that exists.');

        $columns = array_intersect(
            array_keys($calculated),
            Schema::getColumnListing($payslip->getTable()),
        );

        $this->assertGreaterThanOrEqual(
            12,
            count($columns),
            'Too few figures compared — the derived column list has stopped finding them, '
            .'and this test would be passing over nothing.',
        );

        foreach ($columns as $column) {
            // To the paisa: the columns are decimal(10,2) and the service works in
            // floats, so the store rounds. A difference worth reporting is money.
            $this->assertEqualsWithDelta(
                (float) $calculated[$column],
                (float) $payslip->{$column},
                0.01,
                "`{$column}` on the payslip is not what the calculation produced. "
                .'Something between PayslipService and the database is adjusting a figure — '
                .'read this test\'s docblock before changing it.',
            );
        }
    }

    public function test_the_stored_figures_are_exactly_what_the_calculation_produced(): void
    {
        $this->assertStoredFiguresAreTheCalculatedOnes($this->payslip());
    }

    public function test_it_holds_when_a_clerk_overrides_a_figure_by_hand(): void
    {
        // An override is an input to the calculation, not an adjustment after it:
        // the service decides what an overridden bonus does to gross, tax and net.
        $this->assertStoredFiguresAreTheCalculatedOnes($this->payslip([
            'bonus' => 75000,
            'extra_work_hours' => 12,
        ]));
    }

    public function test_it_holds_after_the_payslip_is_saved_again(): void
    {
        // The update path runs the same calculation as create, and an adjustment
        // bolted onto one and not the other is the shape a correction bug takes.
        $payslip = $this->payslip();

        $payslip->update(['paid_days' => 20, 'lop_days' => 2]);

        $this->assertStoredFiguresAreTheCalculatedOnes($payslip->fresh());
    }

    public function test_the_seam_holds_with_the_ledger_out_of_the_way(): void
    {
        $company = $this->setCurrentTenant();

        // Accounting off, so payroll degrades to "no journal entry" instead of
        // posting one (ModuleDegradationTest pins that), which leaves the
        // calculation seam as the only thing this case can fail on.
        //
        // It earns its place: with posting on, a figure adjusted after the
        // calculation is reported by the ledger's balance check instead — a true
        // failure, but one that arrives from accounting, about a mistake made in
        // payroll, and only for a payslip whose imbalance happens to be non-zero.
        // This case fails with the message written below, every time.
        CompanyModule::updateOrCreate(
            ['company_id' => $company->getKey(), 'module' => 'accounting'],
            ['licensed' => false, 'enabled' => false],
        );
        modules()->flush();

        $this->assertFalse(modules()->enabled('accounting'), 'The ledger is meant to be switched off here.');

        $payslip = $this->payslip();

        $this->assertSame(0, $payslip->journalEntries()->count(), 'Nothing should have been posted.');

        $this->assertStoredFiguresAreTheCalculatedOnes($payslip);
    }

    public function test_it_holds_for_an_employee_whose_package_has_no_extras(): void
    {
        // Zeros are where an "if (empty) fall back" branch quietly substitutes
        // something the service did not return.
        EmployeeSetting::where('employee_id', $this->employee->id)->update([
            'device_allowance' => 0,
            'petrol_allowance' => 0,
            'advances' => 0,
            'meal_deduction' => 0,
            'esi_health_insurance' => 0,
        ]);

        $this->assertStoredFiguresAreTheCalculatedOnes($this->payslip());
    }
}
