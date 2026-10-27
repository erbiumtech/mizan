<?php

namespace Tests\Feature;

use App\Modules\Employees\Models\Employee;
use App\Modules\Employees\Models\EmployeeSetting;
use App\Modules\Payroll\Models\PayComponent;
use App\Modules\Payroll\Models\Payslip;
use App\Modules\Payroll\Services\PayComponentRecorder;
use Tests\AccountingTestCase;
use Tests\Concerns\InteractsWithTenant;

/**
 * The recorder writes the component projection from the calculation, not the columns — docs/payslip-component-input-plan.md Phase 5.
 *
 * The hinge of the column retirement: once the projection is built from what the save computed rather than
 * from the column it wrote, the column can be dropped. The compute stashes its figures on the payslip as
 * `computedAmounts`; this drives the recorder with a value that disagrees with the column to show it followed
 * the calculation, and that it falls back to the column when no calculation ran.
 */
class PayComponentRecorderSourceTest extends AccountingTestCase
{
    use InteractsWithTenant;

    private Employee $employee;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs($this->makeUser('Administrator', 'recorder@test.local'));
        $this->setCurrentTenant();

        PayComponent::firstOrCreate(
            ['code' => 'device_allowance'],
            ['label' => 'Device allowance', 'kind' => PayComponent::KIND_EARNING, 'is_column_backed' => true, 'is_active' => true],
        );

        $this->employee = Employee::create([
            'user_id' => $this->makeUser('Employee', 'recorder-emp@test.local')->id,
            'employee_id' => 'EMP-REC',
            'gender' => 'Male',
            'is_active' => 1,
        ]);

        EmployeeSetting::create([
            'employee_id' => $this->employee->id,
            'fiscal_year_id' => $this->fiscalYear->id,
            'start_date' => '2026-07-01',
            'end_date' => '2027-06-30',
            'basic_wage' => 200000,
            'device_allowance' => 5000,
        ]);
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
            'device_allowance' => 7000,
        ])->fresh();
    }

    private function deviceComponent(Payslip $payslip): ?float
    {
        $row = $payslip->components()
            ->whereHas('component', fn ($q) => $q->where('code', 'device_allowance'))
            ->first();

        return $row ? (float) $row->amount : null;
    }

    public function test_the_recorder_writes_the_calculated_amount_not_the_column(): void
    {
        $payslip = $this->payslip();
        $this->assertSame(7000.0, $this->deviceComponent($payslip), 'the column figure was recorded on create');

        // The calculation said 9,000; the column still says 7,000. The recorder must follow the calculation.
        $payslip->computedAmounts = ['device_allowance' => 9000];
        app(PayComponentRecorder::class)->record($payslip);

        $this->assertSame(9000.0, $this->deviceComponent($payslip->fresh()), 'the recorder followed computedAmounts');
    }

    public function test_the_recorder_falls_back_to_the_column_when_no_calculation_ran(): void
    {
        $payslip = $this->payslip();

        // No computedAmounts set — a save that ran no calculation. The column is the fallback.
        $payslip->computedAmounts = null;
        app(PayComponentRecorder::class)->record($payslip);

        $this->assertSame(7000.0, $this->deviceComponent($payslip->fresh()), 'the recorder used the column');
    }
}
