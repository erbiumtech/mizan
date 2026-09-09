<?php

namespace Tests\Feature;

use App\Modules\Core\Models\User;
use App\Modules\Employees\Models\Employee;
use App\Modules\Employees\Models\EmployeeSetting;
use App\Modules\Payroll\Filament\Resources\Payslips\Pages\CreatePayslip;
use App\Modules\Payroll\Filament\Resources\Payslips\Pages\EditPayslip;
use App\Modules\Payroll\Models\Payslip;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;
use Tests\AccountingTestCase;
use Tests\Concerns\InteractsWithTenant;

/**
 * A payslip created by hand starts with the month's real attendance figures, not four zeros.
 *
 * The monthly run has written them from AttendanceFigures since phase 3; the create form still defaulted
 * every column to 0 and left the clerk to know the month had 21 working days. Same source now for both.
 */
class PayslipFormAttendancePrefillTest extends AccountingTestCase
{
    use InteractsWithTenant;

    private Employee $employee;

    protected function setUp(): void
    {
        parent::setUp();

        Gate::before(fn () => true);
        $this->actingAs($this->makeUser('Administrator', 'prefill@test.local'));
        $this->setCurrentTenant();

        $this->employee = Employee::create([
            'user_id' => User::factory()->create(['name' => 'Ali Raza'])->id,
            'employee_id' => 'EMP-1',
            'gender' => 'Male',
            'is_active' => true,
            'date_of_joining' => '2020-01-01',
        ]);

        EmployeeSetting::create([
            'employee_id' => $this->employee->id,
            'fiscal_year_id' => $this->fiscalYear->id,
            'start_date' => '2026-07-01',
            'end_date' => '2027-06-30',
            'basic_wage' => 200000,
        ]);
    }

    public function test_choosing_employee_month_and_year_fills_the_working_days(): void
    {
        // August 2026 has 21 weekdays. The factory company has Attendance on and no pattern, and the
        // resolver assumes Monday to Friday for a company that has not configured one.
        Livewire::test(CreatePayslip::class)
            ->fillForm([
                'employee_id' => $this->employee->id,
                'month' => 'August',
                'fiscal_year_id' => $this->fiscalYear->id,
            ])
            ->assertFormSet([
                'total_working_days' => 21.0,
                'paid_days' => 21.0,
                'lop_days' => 0.0,
                'leaves_taken' => 0.0,
            ]);
    }

    public function test_an_existing_payslip_keeps_the_figures_it_was_given(): void
    {
        $payslip = Payslip::create([
            'employee_id' => $this->employee->id,
            'fiscal_year_id' => $this->fiscalYear->id,
            'month' => 'August',
            'total_working_days' => 26,
            'paid_days' => 24,
            'lop_days' => 2,
            'leaves_taken' => 1,
            'basic_wage' => 200000,
            'net_salary' => 200000,
        ]);

        // Changing a selector on an existing payslip recomputes the money, never the record of the month.
        Livewire::test(EditPayslip::class, ['record' => $payslip->getKey()])
            ->fillForm(['month' => 'September'])
            ->assertFormSet([
                'total_working_days' => 26,
                'paid_days' => 24,
                'lop_days' => 2,
                'leaves_taken' => 1,
            ]);
    }
}
