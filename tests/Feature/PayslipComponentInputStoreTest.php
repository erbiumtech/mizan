<?php

namespace Tests\Feature;

use App\Modules\Employees\Models\Employee;
use App\Modules\Employees\Models\EmployeeSetting;
use App\Modules\Payroll\Models\PayComponent;
use App\Modules\Payroll\Models\Payslip;
use App\Modules\Payroll\Models\PayslipComponentInput;
use App\Modules\Payroll\Services\PayslipService;
use Tests\AccountingTestCase;
use Tests\Concerns\InteractsWithTenant;

/**
 * The per-payslip input store, dual-read — docs/payslip-component-input-plan.md Phase 2.
 *
 * Two facts carry the phase: with the store empty the calculation is exactly what it was (the override map
 * decides), and a row in the store wins over the map, which is the seam a later phase fills to move the
 * input off the columns. The store never writes anything itself — it is read, never a side effect.
 */
class PayslipComponentInputStoreTest extends AccountingTestCase
{
    use InteractsWithTenant;

    private Employee $employee;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs($this->makeUser('Administrator', 'store@test.local'));
        $this->setCurrentTenant();

        $this->employee = Employee::create([
            'user_id' => $this->makeUser('Employee', 'store-emp@test.local')->id,
            'employee_id' => 'EMP-STORE',
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
        ]);
    }

    private function bonus(): PayComponent
    {
        return PayComponent::firstOrCreate(
            ['code' => 'bonus'],
            ['label' => 'Bonus', 'kind' => PayComponent::KIND_EARNING, 'is_column_backed' => true],
        );
    }

    private function payslip(): Payslip
    {
        return Payslip::create([
            'employee_id' => $this->employee->id,
            'fiscal_year_id' => $this->fiscalYear->id,
            'month' => 'July',
            'total_working_days' => 22,
            'paid_days' => 22,
            'lop_days' => 0,
        ])->fresh();
    }

    public function test_an_empty_store_leaves_the_calculation_to_the_passed_map(): void
    {
        $this->bonus();
        $payslip = $this->payslip();

        $data = app(PayslipService::class)->calculateByParams(
            $this->employee->id, 'July', $this->fiscalYear->id,
            ['bonus' => 8000],
            $payslip->id,
        );

        $this->assertSame(8000.0, (float) $data['bonus'], 'with no store row, the map decides');
        $this->assertSame(0, PayslipComponentInput::count(), 'the read writes nothing');
    }

    public function test_a_stored_override_wins_over_the_passed_map(): void
    {
        $bonus = $this->bonus();
        $payslip = $this->payslip();

        PayslipComponentInput::create([
            'payslip_id' => $payslip->id,
            'pay_component_id' => $bonus->id,
            'amount' => 12000,
        ]);

        $data = app(PayslipService::class)->calculateByParams(
            $this->employee->id, 'July', $this->fiscalYear->id,
            ['bonus' => 8000],
            $payslip->id,
        );

        $this->assertSame(12000.0, (float) $data['bonus'], 'the store row wins over the passed 8000');
    }

    public function test_a_store_row_for_another_payslip_does_not_leak(): void
    {
        $bonus = $this->bonus();
        $mine = $this->payslip();

        $otherEmployee = Employee::create([
            'user_id' => $this->makeUser('Employee', 'other-store@test.local')->id,
            'employee_id' => 'EMP-STORE-2', 'gender' => 'Male', 'is_active' => 1,
        ]);
        EmployeeSetting::create([
            'employee_id' => $otherEmployee->id, 'fiscal_year_id' => $this->fiscalYear->id,
            'start_date' => '2026-07-01', 'end_date' => '2027-06-30', 'basic_wage' => 100000,
        ]);
        $theirs = Payslip::create([
            'employee_id' => $otherEmployee->id, 'fiscal_year_id' => $this->fiscalYear->id,
            'month' => 'July', 'total_working_days' => 22, 'paid_days' => 22, 'lop_days' => 0,
        ])->fresh();

        PayslipComponentInput::create(['payslip_id' => $theirs->id, 'pay_component_id' => $bonus->id, 'amount' => 99000]);

        $data = app(PayslipService::class)->calculateByParams(
            $this->employee->id, 'July', $this->fiscalYear->id,
            ['bonus' => 8000],
            $mine->id,
        );

        $this->assertSame(8000.0, (float) $data['bonus'], "another payslip's store row is not read here");
    }
}
