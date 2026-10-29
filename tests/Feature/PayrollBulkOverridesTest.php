<?php

namespace Tests\Feature;

use App\Modules\Employees\Models\Employee;
use App\Modules\Employees\Models\EmployeeSetting;
use App\Modules\Payroll\Filament\Resources\Payslips\Pages\ListPayslips;
use App\Modules\Payroll\Models\PayComponent;
use App\Modules\Payroll\Models\Payslip;
use App\Modules\Payroll\Services\MonthlyPayrollService;
use Filament\Actions\Testing\TestAction;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Tests\AccountingTestCase;
use Tests\Concerns\InteractsWithTenant;

/**
 * Raising a whole month from the payslips list, with fuel and meals typed over the package.
 *
 * Two employees in every fixture, deliberately: the overrides are keyed by employee, and a
 * one-employee fixture would pass with the keys ignored altogether.
 */
class PayrollBulkOverridesTest extends AccountingTestCase
{
    use InteractsWithTenant;

    private Employee $ali;

    private Employee $sara;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs($this->makeUser('Administrator', 'payroll@test.local'));
        $this->setCurrentTenant();

        Notification::fake();

        $this->ali = $this->employee('EMP-1', 'Ali Raza');
        $this->sara = $this->employee('EMP-2', 'Sara Khan');
    }

    private function employee(string $code, string $name): Employee
    {
        $employee = Employee::create([
            'employee_id' => $code,
            'name' => $name,
            'gender' => 'Male',
            'is_active' => true,
            'date_of_joining' => '2020-01-01',
            // An account number, because the pre-flight blocks the run without one when the
            // company pays by bank (§14.1) and the screen below goes through the pre-flight.
            'bank_account_no' => '000'.substr($code, -1).'1234567',
        ]);

        EmployeeSetting::create([
            'employee_id' => $employee->id,
            'fiscal_year_id' => $this->fiscalYear->id,
            'start_date' => '2026-07-01',
            'end_date' => '2027-06-30',
            'basic_wage' => 200000,
            'petrol_allowance' => 3000,
            'meal_deduction' => 500,
        ]);

        return $employee;
    }

    private function payslipFor(Employee $employee): Payslip
    {
        return Payslip::where('employee_id', $employee->id)
            ->where('month', 'August')
            ->where('fiscal_year_id', $this->fiscalYear->id)
            ->sole();
    }

    /** @return array<int, array<string, mixed>> */
    private function rows(float $aliPetrol, float $aliMeal): array
    {
        return [
            ['employee_id' => $this->ali->id, 'name' => 'EMP-1 - Ali Raza', 'petrol_allowance' => $aliPetrol, 'meal_deduction' => $aliMeal],
            ['employee_id' => $this->sara->id, 'name' => 'EMP-2 - Sara Khan', 'petrol_allowance' => 3000, 'meal_deduction' => 500],
        ];
    }

    public function test_the_screen_raises_the_month_with_what_was_typed(): void
    {
        Livewire::test(ListPayslips::class)
            ->callAction(TestAction::make('openMonth'), [
                'month' => 'August',
                'fiscal_year_id' => $this->fiscalYear->id,
                // The pre-flight warns that no attendance is marked for the month
                // (docs/saas-launch-plan.md §14.1) and will not raise anything until
                // somebody says they have read it. Nothing here is a blocker.
                'acknowledge' => true,
                'employees' => $this->rows(9000, 1500),
            ]);

        $overridden = $this->payslipFor($this->ali);

        $this->assertEquals(9000, $overridden->petrol_allowance, 'the fuel typed on the screen is what the month pays');
        $this->assertEquals(1500, $overridden->meal_deduction, 'and so is the meal figure');

        // Not a global change: an employee nobody typed over is still on their package.
        $untouched = $this->payslipFor($this->sara);

        $this->assertEquals(3000, $untouched->petrol_allowance);
        $this->assertEquals(500, $untouched->meal_deduction);
    }

    /** The modal opens on the current month with everybody due already listed. */
    public function test_the_screen_lists_who_is_due(): void
    {
        Livewire::test(ListPayslips::class)
            ->mountAction(TestAction::make('openMonth'))
            ->assertMountedActionModalSee('EMP-1 - Ali Raza')
            ->assertMountedActionModalSee('EMP-2 - Sara Khan');
    }

    /**
     * The override is an input to the calculation, not a figure written past it.
     *
     * Earnings have to move with the fuel, or the payslip would show 9,000 of petrol in a
     * total that counted 3,000 — which is the shape of bug that reaches the bank file.
     *
     * And the tax has to move with the earnings. A fuel allowance is taxable pay, so the
     * extra 6,000 raises the year's projection and this month's withholding with it: the
     * deductions are the extra meal charge **plus** that, and asserting a flat 1,000 here
     * was this test's own first mistake.
     */
    public function test_the_override_is_carried_through_the_totals(): void
    {
        app(MonthlyPayrollService::class)->openMonth('August', $this->fiscalYear, [
            $this->ali->id => ['petrol_allowance' => 9000, 'meal_deduction' => 1500],
        ]);

        $overridden = $this->payslipFor($this->ali);
        $untouched = $this->payslipFor($this->sara);

        $this->assertEquals(6000, $overridden->total_earnings - $untouched->total_earnings);

        $extraTax = $overridden->withholding_tax - $untouched->withholding_tax;

        $this->assertGreaterThan(0, $extraTax, 'a fuel allowance is taxable pay');
        $this->assertEquals(1000 + $extraTax, $overridden->total_deductions - $untouched->total_deductions);
        $this->assertEquals(6000 - (1000 + $extraTax), $overridden->net_salary - $untouched->net_salary);
    }

    /**
     * The override reaches the input store, not the column — docs/payslip-component-input-plan.md Phase 4.
     *
     * The monthly run hands the typed overrides to the model the same way the form does, so they land in
     * `payslip_component_inputs`; the column is written only as the calculation's output.
     */
    public function test_the_override_is_written_to_the_input_store(): void
    {
        foreach (['petrol_allowance' => PayComponent::KIND_EARNING, 'meal_deduction' => PayComponent::KIND_DEDUCTION] as $code => $kind) {
            PayComponent::firstOrCreate(
                ['code' => $code],
                ['label' => ucfirst(str_replace('_', ' ', $code)), 'kind' => $kind, 'is_column_backed' => true],
            );
        }

        app(MonthlyPayrollService::class)->openMonth('August', $this->fiscalYear, [
            $this->ali->id => ['petrol_allowance' => 9000, 'meal_deduction' => 1500],
        ]);

        $stored = $this->payslipFor($this->ali)->componentInputs()->with('component:id,code')->get()
            ->mapWithKeys(fn ($input): array => [(string) $input->component?->code => (float) $input->amount]);

        $this->assertSame(9000.0, $stored['petrol_allowance'] ?? null, 'the typed fuel is in the store');
        $this->assertSame(1500.0, $stored['meal_deduction'] ?? null, 'and the typed meal charge');

        // The employee nobody typed over is on the package, so nothing is an override and the store is empty.
        $this->assertSame(0, $this->payslipFor($this->sara)->componentInputs()->count());
    }

    /** Nothing passed is the behaviour the scheduler has always had. */
    public function test_without_overrides_the_package_is_what_is_raised(): void
    {
        app(MonthlyPayrollService::class)->openMonth('August', $this->fiscalYear);

        foreach ([$this->ali, $this->sara] as $employee) {
            $payslip = $this->payslipFor($employee);

            $this->assertEquals(3000, $payslip->petrol_allowance);
            $this->assertEquals(500, $payslip->meal_deduction);
        }
    }
}
