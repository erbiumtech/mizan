<?php

namespace Tests\Feature;

use App\Modules\Employees\Models\Employee;
use App\Modules\Employees\Models\EmployeeSetting;
use App\Modules\Payroll\Filament\Resources\Payslips\Pages\EditPayslip;
use App\Modules\Payroll\Models\Payslip;
use Livewire\Livewire;
use Tests\AccountingTestCase;
use Tests\Concerns\InteractsWithTenant;

/**
 * The payslip form saying when a figure on it disagrees with the employee's
 * settings.
 *
 * A figure typed on a payslip outranks the settings, deliberately — correcting
 * one month by hand is a real need. But nothing said so on screen, and a device
 * allowance of 5,000 went out as 1.00 for a month: the payslip was accepted by
 * the employee, paid, and only turned up afterwards as a suspicious 1.00 in the
 * Profit & Loss.
 */
class PayslipOverrideWarningTest extends AccountingTestCase
{
    use InteractsWithTenant;

    private Employee $employee;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs($this->makeUser('Administrator', 'warning@test.local'));
        $this->setCurrentTenant();

        $this->employee = Employee::create([
            'user_id' => $this->makeUser('Employee', 'overridden@test.local')->id,
            'employee_id' => 'EMP-1',
            'gender' => 'Male',
            'phone' => '0300-0000000',
        ]);

        EmployeeSetting::create([
            'employee_id' => $this->employee->id,
            'fiscal_year_id' => $this->fiscalYear->id,
            'start_date' => '2026-07-01',
            'end_date' => '2027-06-30',
            'basic_wage' => 196745,
            'device_allowance' => 5000,
            'petrol_allowance' => 7500,
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
        ], $attributes));
    }

    /** The case that was paid: a stray digit where 5,000 belonged. */
    public function test_a_figure_that_disagrees_with_the_settings_is_flagged(): void
    {
        $payslip = $this->payslip(['device_allowance' => 1]);

        $this->assertSame(1.0, (float) $payslip->fresh()->device_allowance, 'the override took, as it should');

        Livewire::test(EditPayslip::class, ['record' => $payslip->getRouteKey()])
            ->assertSee('Overrides 5,000.00');
    }

    public function test_a_figure_matching_the_settings_says_nothing(): void
    {
        // Payroll fills these from the settings on its own, so every untouched
        // payslip would carry a warning otherwise — and a warning on everything is
        // a warning on nothing.
        $payslip = $this->payslip();

        $this->assertSame(5000.0, (float) $payslip->fresh()->device_allowance);

        Livewire::test(EditPayslip::class, ['record' => $payslip->getRouteKey()])
            ->assertDontSee('Overrides');
    }

    public function test_each_overridable_field_is_covered(): void
    {
        // Petrol, meal, ESI and the rest override exactly as the device allowance
        // does, so leaving any of them unflagged leaves the same hole open.
        $payslip = $this->payslip(['device_allowance' => 1, 'petrol_allowance' => 2]);

        Livewire::test(EditPayslip::class, ['record' => $payslip->getRouteKey()])
            ->assertSee('Overrides 5,000.00')
            ->assertSee('Overrides 7,500.00');
    }

    /**
     * The hint names what the field would hold if it were cleared, so for the
     * advance deduction that is the ledger's instalment rather than a settings
     * figure — the number the payroll clerk is actually overriding.
     */
    public function test_the_advance_hint_comes_from_the_ledger(): void
    {
        $company = \App\Modules\Core\Models\Company::current() ?? $this->tenant;

        foreach (['employees', 'payroll', 'advances'] as $module) {
            \App\Modules\Core\Models\CompanyModule::updateOrCreate(
                ['company_id' => $company->getKey(), 'module' => $module],
                ['licensed' => true, 'enabled' => true],
            );
        }
        modules()->flush();

        \App\Modules\Advances\Models\Advance::create([
            'employee_id' => $this->employee->id,
            'total_amount' => 500000,
            'monthly_instalment' => 60000,
            'started_on' => '2026-07-01',
            'status' => \App\Modules\Advances\Models\Advance::STATUS_ACTIVE,
        ]);

        $payslip = $this->payslip(['advances' => 10000]);

        Livewire::test(EditPayslip::class, ['record' => $payslip->getRouteKey()])
            ->assertSee('Overrides 60,000.00');
    }
}
