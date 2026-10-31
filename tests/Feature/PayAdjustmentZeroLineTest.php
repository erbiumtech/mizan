<?php

namespace Tests\Feature;

use App\Modules\Core\Models\CompanyModule;
use App\Modules\Employees\Models\Employee;
use App\Modules\Employees\Models\EmployeeSetting;
use App\Modules\Payroll\Models\PayAdjustment;
use App\Modules\Payroll\Models\PayComponent;
use App\Modules\Payroll\Models\Payslip;
use App\Modules\Payroll\Services\MonthlyPayrollService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use Tests\AccountingTestCase;
use Tests\Concerns\InteractsWithTenant;

/**
 * B3: an adjustment that takes a line to exactly 0 is honoured.
 *
 * A typed 0 used to mean "not set", so a −1,500 waiver on a 1,500 meal deduction brought the package's 1,500
 * straight back. The input store now holds the 0 as an override, for the lines a pay adjustment took there.
 */
class PayAdjustmentZeroLineTest extends AccountingTestCase
{
    use InteractsWithTenant;

    private Employee $employee;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-09-10 10:00:00');
        Notification::fake();

        $this->actingAs($this->makeUser('Administrator', 'zero-admin@test.local'));
        $company = $this->setCurrentTenant();

        foreach (['employees', 'payroll', 'accounting'] as $module) {
            CompanyModule::updateOrCreate(['company_id' => $company->getKey(), 'module' => $module], ['licensed' => true, 'enabled' => true]);
        }
        modules()->flush();
        $this->seed([\Database\Seeders\TransactionTypeSeeder::class, \Database\Seeders\PayComponentSeeder::class]);

        $this->employee = Employee::create([
            'user_id' => $this->makeUser('Employee', 'zero-emp@test.local')->id, 'employee_id' => 'EMP-Z', 'name' => 'Zia',
            'gender' => 'Male', 'is_active' => 1, 'bank_account_no' => '1',
        ]);

        EmployeeSetting::create([
            'employee_id' => $this->employee->id, 'fiscal_year_id' => $this->fiscalYear->id, 'start_date' => '2026-07-01', 'end_date' => '2027-06-30',
            'basic_wage' => 40000, 'petrol_allowance' => 3000, 'meal_deduction' => 1500,
        ]);
    }

    private function adjust(string $line, float $amount): PayAdjustment
    {
        return PayAdjustment::create([
            'employee_id' => $this->employee->id, 'fiscal_year_id' => $this->fiscalYear->id, 'month' => 'August',
            'pay_component_id' => PayComponent::query()->where('code', $line)->value('id'), 'amount' => $amount, 'reason' => 'Waiver',
        ]);
    }

    private function august(): Payslip
    {
        return Payslip::query()->where('employee_id', $this->employee->id)->where('month', 'August')->sole();
    }

    public function test_a_waiver_recorded_before_the_month_is_raised_takes_the_line_to_zero(): void
    {
        $this->adjust('meal_deduction', -1500);

        app(MonthlyPayrollService::class)->openMonth('August', $this->fiscalYear);

        $this->assertSame(0.0, (float) $this->august()->meal_deduction);
        $this->assertSame(43000.0, (float) $this->august()->net_salary, 'nothing deducted');
    }

    public function test_a_waiver_recorded_after_the_month_is_raised_takes_the_line_to_zero(): void
    {
        app(MonthlyPayrollService::class)->openMonth('August', $this->fiscalYear);
        $this->assertSame(1500.0, (float) $this->august()->meal_deduction);

        Carbon::setTestNow('2026-09-10 10:05:00');
        $this->adjust('meal_deduction', -1500);

        $this->assertSame(0.0, (float) $this->august()->meal_deduction);
    }

    /** A save that only re-reads the columns keeps the waiver — the 0 is on the store, not just the column. */
    public function test_the_zero_survives_a_plain_re_save(): void
    {
        $this->adjust('meal_deduction', -1500);
        app(MonthlyPayrollService::class)->openMonth('August', $this->fiscalYear);

        Carbon::setTestNow('2026-09-10 10:05:00');
        $this->august()->saveWithInputs(['bonus' => 1000]);

        $this->assertSame(1000.0, (float) $this->august()->bonus, 'the save did recalculate');
        $this->assertSame(0.0, (float) $this->august()->meal_deduction);
    }

    public function test_deleting_the_waiver_brings_the_package_figure_back(): void
    {
        $waiver = $this->adjust('meal_deduction', -1500);
        app(MonthlyPayrollService::class)->openMonth('August', $this->fiscalYear);

        Carbon::setTestNow('2026-09-10 10:05:00');
        $waiver->delete();

        $this->assertSame(1500.0, (float) $this->august()->meal_deduction);
    }

    /** A line cannot go below nothing: an adjustment larger than the line takes it to 0, not to a negative. */
    public function test_an_adjustment_larger_than_the_line_stops_at_zero(): void
    {
        $this->adjust('petrol_allowance', -5000);

        app(MonthlyPayrollService::class)->openMonth('August', $this->fiscalYear);

        $this->assertSame(0.0, (float) $this->august()->petrol_allowance);
    }
}
