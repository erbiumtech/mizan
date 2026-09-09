<?php

namespace Tests\Feature;

use App\Modules\Core\Models\User;
use App\Modules\Employees\Models\Employee;
use App\Modules\Employees\Models\EmployeeSetting;
use App\Modules\Payroll\Filament\Resources\Payslips\Pages\CreatePayslip;
use App\Modules\Payroll\Filament\Resources\Payslips\Pages\EditPayslip;
use App\Modules\Payroll\Filament\Resources\Payslips\Pages\ListPayslips;
use App\Modules\Payroll\Models\Payslip;
use Filament\Actions\Testing\TestAction;
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

    public function test_a_payslip_whose_working_days_were_never_known_opens_with_them_filled_in(): void
    {
        $payslip = Payslip::create([
            'employee_id' => $this->employee->id,
            'fiscal_year_id' => $this->fiscalYear->id,
            'month' => 'August',
            'basic_wage' => 200000,
            'net_salary' => 200000,
        ]);

        $this->assertSame(0.0, (float) $payslip->fresh()->total_working_days, 'raised with the month unknown');

        Livewire::test(EditPayslip::class, ['record' => $payslip->getKey()])
            ->assertFormSet(['total_working_days' => 21.0, 'paid_days' => 21.0]);

        // Shown, not written: nothing changes until somebody saves.
        $this->assertSame(0.0, (float) $payslip->fresh()->total_working_days);
    }

    /**
     * The month's payslips were raised before the month was measured; one click fills them. A payslip
     * that already knows its working days is left exactly as it was, whatever the records now say.
     */
    public function test_the_bulk_action_fills_unknown_working_days_and_leaves_known_ones_alone(): void
    {
        $unknown = Payslip::create([
            'employee_id' => $this->employee->id,
            'fiscal_year_id' => $this->fiscalYear->id,
            'month' => 'August',
            'basic_wage' => 200000,
            'net_salary' => 200000,
        ]);

        $known = Payslip::create([
            'employee_id' => $this->employee->id,
            'fiscal_year_id' => $this->fiscalYear->id,
            'month' => 'September',
            'total_working_days' => 26,
            'paid_days' => 24,
            'lop_days' => 2,
            'leaves_taken' => 1,
            'basic_wage' => 200000,
            'net_salary' => 200000,
        ]);

        Livewire::test(ListPayslips::class)
            ->selectTableRecords([$unknown->getKey(), $known->getKey()])
            ->callAction(TestAction::make('fillWorkingDaysBulk')->table()->bulk())
            ->assertNotified();

        $this->assertSame(21.0, (float) $unknown->fresh()->total_working_days);
        $this->assertSame(21.0, (float) $unknown->fresh()->paid_days);

        $this->assertSame(26.0, (float) $known->fresh()->total_working_days, 'entered figures outrank a recomputation');
        $this->assertSame(2.0, (float) $known->fresh()->lop_days);
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
