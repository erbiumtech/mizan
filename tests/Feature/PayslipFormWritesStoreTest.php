<?php

namespace Tests\Feature;

use App\Modules\Core\Models\CompanyModule;
use App\Modules\Employees\Models\Employee;
use App\Modules\Employees\Models\EmployeeSetting;
use App\Modules\Payroll\Filament\Resources\Payslips\Pages\CreatePayslip;
use App\Modules\Payroll\Filament\Resources\Payslips\Pages\EditPayslip;
use App\Modules\Payroll\Models\PayComponent;
use App\Modules\Payroll\Models\Payslip;
use Livewire\Livewire;
use Tests\AccountingTestCase;
use Tests\Concerns\InteractsWithTenant;

/**
 * The payslip form writes the typed overrides to the input store, not the columns — docs/payslip-component-input-plan.md Phase 4.
 *
 * Driven through the real Livewire pages, because the change lives in their save lifecycle: the typed
 * overrides are pulled out of the save data so they never write a column, handed to the model, and the save
 * hook syncs the store from them. The columns are written by the calculation, as output.
 */
class PayslipFormWritesStoreTest extends AccountingTestCase
{
    use InteractsWithTenant;

    private Employee $employee;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs($this->makeUser('Administrator', 'formstore@test.local'));
        $company = $this->setCurrentTenant();

        foreach (['employees', 'payroll', 'accounting'] as $module) {
            CompanyModule::updateOrCreate(
                ['company_id' => $company->getKey(), 'module' => $module],
                ['licensed' => true, 'enabled' => true],
            );
        }

        PayComponent::firstOrCreate(
            ['code' => 'device_allowance'],
            ['label' => 'Device allowance', 'kind' => PayComponent::KIND_EARNING, 'is_column_backed' => true],
        );

        $this->employee = Employee::create([
            'user_id' => $this->makeUser('Employee', 'formstore-emp@test.local')->id,
            'employee_id' => 'EMP-FORM',
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
        ]);
    }

    private function payslip(array $attributes = []): Payslip
    {
        return Payslip::create(array_merge([
            'employee_id' => $this->employee->id,
            'fiscal_year_id' => $this->fiscalYear->id,
            'month' => 'July',
            'total_working_days' => 22,
            'paid_days' => 22,
            'lop_days' => 0,
        ], $attributes))->fresh();
    }

    private function storedDevice(Payslip $payslip): ?float
    {
        $row = $payslip->componentInputs()
            ->whereHas('component', fn ($q) => $q->where('code', 'device_allowance'))
            ->first();

        return $row ? (float) $row->amount : null;
    }

    public function test_editing_an_override_writes_the_store_and_recomputes_the_column(): void
    {
        $payslip = $this->payslip(['device_allowance' => 7000]);
        $this->assertSame(7000.0, $this->storedDevice($payslip));

        Livewire::test(EditPayslip::class, ['record' => $payslip->getRouteKey()])
            ->fillForm(['device_allowance' => 9000])
            ->call('save')
            ->assertHasNoFormErrors();

        $payslip = $payslip->fresh();
        $this->assertSame(9000.0, $this->storedDevice($payslip), 'the store holds the new override');
        $this->assertSame(9000.0, (float) $payslip->device_allowance, 'the column is the recomputed output');
    }

    public function test_clearing_an_override_removes_the_store_row(): void
    {
        $payslip = $this->payslip(['device_allowance' => 7000]);

        Livewire::test(EditPayslip::class, ['record' => $payslip->getRouteKey()])
            ->fillForm(['device_allowance' => 0])
            ->call('save')
            ->assertHasNoFormErrors();

        $payslip = $payslip->fresh();
        $this->assertNull($this->storedDevice($payslip), 'no override row survives the clear');
        $this->assertSame(5000.0, (float) $payslip->device_allowance, 'the setting returns');
    }

    public function test_an_untouched_save_does_not_recompute_against_a_changed_setting(): void
    {
        $payslip = $this->payslip(['device_allowance' => 7000]);
        $originalBasic = (float) $payslip->basic_wage;
        $originalNet = (float) $payslip->net_salary;

        // The setting changes after the payslip is issued. A recompute would pick it up; opening and saving
        // the form without a change must not, or re-opening a settled payslip would silently restate it —
        // the same reason the backfill never re-saves a column.
        EmployeeSetting::where('employee_id', $this->employee->id)->update(['basic_wage' => 250000]);

        Livewire::test(EditPayslip::class, ['record' => $payslip->getRouteKey()])
            ->call('save')
            ->assertHasNoFormErrors();

        $payslip = $payslip->fresh();
        $this->assertSame($originalBasic, (float) $payslip->basic_wage, 'the untouched save did not recompute');
        $this->assertSame($originalNet, (float) $payslip->net_salary);
        $this->assertSame(7000.0, $this->storedDevice($payslip), 'and left the override where it was');
    }

    public function test_creating_through_the_form_stores_the_override_off_the_column(): void
    {
        Livewire::test(CreatePayslip::class)
            ->fillForm([
                'employee_id' => $this->employee->id,
                'fiscal_year_id' => $this->fiscalYear->id,
                'month' => 'August',
                'device_allowance' => 7000,
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $payslip = Payslip::where('employee_id', $this->employee->id)->where('month', 'August')->firstOrFail();

        $this->assertSame(7000.0, $this->storedDevice($payslip), 'the typed override landed in the store');
        $this->assertSame(7000.0, (float) $payslip->device_allowance, 'the column holds the computed output');
    }
}
