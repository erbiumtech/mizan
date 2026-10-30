<?php

namespace Tests\Feature;

use App\Modules\Employees\Models\Employee;
use App\Modules\Employees\Models\EmployeeSetting;
use App\Modules\Payroll\Models\PayComponent;
use App\Modules\Payroll\Models\Payslip;
use App\Modules\Payroll\Models\PayslipComponentInput;
use Tests\AccountingTestCase;
use Tests\Concerns\InteractsWithTenant;

/**
 * The save hook moves the typed overrides onto the input store — docs/payslip-component-input-plan.md Phase 3.
 *
 * Four facts carry the phase. A figure that differs from the setting is stored as an override; one that only
 * echoes the setting is not, so it keeps following it. A changed override replaces the row a previous save
 * left — the regression that matters, because a stale row would otherwise win over the new figure. Clearing an
 * override removes the row. And through all of it, no figure moves.
 */
class PayslipInputStoreSyncTest extends AccountingTestCase
{
    use InteractsWithTenant;

    private Employee $employee;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs($this->makeUser('Administrator', 'sync@test.local'));
        $this->setCurrentTenant();

        // The column-backed components the overrides land on; without the rows, syncInputStore has no id to
        // store against and simply leaves the figure on its column — so the test creates the two it exercises.
        foreach (['device_allowance' => 'Device allowance', 'petrol_allowance' => 'Petrol allowance'] as $code => $label) {
            PayComponent::firstOrCreate(
                ['code' => $code],
                ['label' => $label, 'kind' => PayComponent::KIND_EARNING, 'is_column_backed' => true],
            );
        }

        $this->employee = Employee::create([
            'user_id' => $this->makeUser('Employee', 'sync-emp@test.local')->id,
            'employee_id' => 'EMP-SYNC',
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
            'petrol_allowance' => 3000,
        ]);
    }

    private function payslip(array $overrides = []): Payslip
    {
        return Payslip::create(array_merge([
            'employee_id' => $this->employee->id,
            'fiscal_year_id' => $this->fiscalYear->id,
            'month' => 'July',
            'total_working_days' => 22,
            'paid_days' => 22,
            'lop_days' => 0,
        ], $overrides))->fresh();
    }

    /** @return array<string, float> code => amount */
    private function stored(Payslip $payslip): array
    {
        return $payslip->componentInputs()->with('component:id,code')->get()
            ->mapWithKeys(fn (PayslipComponentInput $i): array => [(string) $i->component?->code => (float) $i->amount])
            ->all();
    }

    public function test_a_figure_above_the_setting_is_stored_and_one_that_echoes_it_is_not(): void
    {
        // device typed above the 5,000 setting is an override; petrol left alone echoes its 3,000 setting.
        $payslip = $this->payslip(['device_allowance' => 7000]);

        $this->assertSame(['device_allowance' => 7000.0], $this->stored($payslip));
        $this->assertSame(7000.0, (float) $payslip->device_allowance);
        $this->assertSame(3000.0, (float) $payslip->petrol_allowance, 'petrol followed its setting');
    }

    public function test_a_changed_override_replaces_the_stale_row_and_the_new_figure_wins(): void
    {
        $payslip = $this->payslip(['device_allowance' => 7000]);

        $payslip->saveWithInputs(['device_allowance' => 9000]);
        $payslip = $payslip->fresh();

        // The row is 9,000, not the 7,000 a previous save left; and the column is 9,000, which it would not be
        // if the stale 7,000 row had won over the new figure the way calculateByParams reads the store first.
        $this->assertSame(['device_allowance' => 9000.0], $this->stored($payslip));
        $this->assertSame(9000.0, (float) $payslip->device_allowance);
    }

    public function test_clearing_an_override_removes_its_row_and_the_setting_returns(): void
    {
        $payslip = $this->payslip(['device_allowance' => 7000]);

        $payslip->saveWithInputs(['device_allowance' => 0]);
        $payslip = $payslip->fresh();

        $this->assertSame([], $this->stored($payslip), 'no override row survives the clear');
        $this->assertSame(5000.0, (float) $payslip->device_allowance, 'the 5,000 setting is back');
    }

    public function test_populating_the_store_moves_no_figure(): void
    {
        $payslip = $this->payslip(['device_allowance' => 7000]);
        $net = (float) $payslip->net_salary;

        // Re-saving reads the figure back from the store rather than the column; the net must not move. Forced
        // through saveWithInputs, because a plain save() with nothing dirty would not run the hook at all.
        $payslip->saveWithInputs([]);

        $this->assertSame($net, (float) $payslip->fresh()->net_salary);
    }
}
