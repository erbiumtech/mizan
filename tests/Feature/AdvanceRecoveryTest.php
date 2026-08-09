<?php

namespace Tests\Feature;

use App\Modules\Advances\Models\Advance;
use App\Modules\Advances\Services\AdvanceService;
use App\Modules\Core\Models\CompanyModule;
use App\Modules\Employees\Models\Employee;
use App\Modules\Employees\Models\EmployeeSetting;
use App\Modules\Payroll\Models\Payslip;
use Tests\AccountingTestCase;
use Tests\Concerns\InteractsWithTenant;

/**
 * Advances lent to employees and recovered through payroll.
 *
 * The balance is money somebody is owed, so the cases that matter are the ones
 * where it could drift: a payslip saved twice, a payslip corrected downwards, a
 * payslip deleted, and the final instalment landing on a part-paid balance.
 */
class AdvanceRecoveryTest extends AccountingTestCase
{
    use InteractsWithTenant;

    private Employee $employee;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs($this->makeUser('Administrator', 'advances@test.local'));
        $company = $this->setCurrentTenant();

        // Advances drives the payslip deduction only while the module is on.
        CompanyModule::updateOrCreate(
            ['company_id' => $company->getKey(), 'module' => 'advances'],
            ['licensed' => true, 'enabled' => true],
        );
        CompanyModule::updateOrCreate(
            ['company_id' => $company->getKey(), 'module' => 'employees'],
            ['licensed' => true, 'enabled' => true],
        );
        modules()->flush();

        $this->employee = Employee::create([
            'user_id' => $this->makeUser('Employee', 'borrower@test.local')->id,
            'employee_id' => 'EMP-ADV',
            'gender' => 'Male',
            'phone' => '0300-0000000',
        ]);

        EmployeeSetting::create([
            'employee_id' => $this->employee->id,
            'fiscal_year_id' => $this->fiscalYear->id,
            'start_date' => '2026-07-01',
            'end_date' => '2027-06-30',
            'basic_wage' => 400000,
        ]);
    }

    private function advance(float $total = 1500000, float $instalment = 60000): Advance
    {
        return Advance::create([
            'employee_id' => $this->employee->id,
            'total_amount' => $total,
            'monthly_instalment' => $instalment,
            'started_on' => '2026-07-01',
            'status' => Advance::STATUS_ACTIVE,
        ]);
    }

    private function payslip(string $month): Payslip
    {
        return Payslip::create([
            'employee_id' => $this->employee->id,
            'fiscal_year_id' => $this->fiscalYear->id,
            'month' => $month,
            'total_working_days' => 22,
            'paid_days' => 22,
        ]);
    }

    public function test_payroll_deducts_the_instalment_from_the_ledger(): void
    {
        $advance = $this->advance();

        $payslip = $this->payslip('July');

        $this->assertSame(60000.0, (float) $payslip->fresh()->advances);
        $this->assertSame(60000.0, $advance->fresh()->recoveredAmount());
        $this->assertSame(1440000.0, $advance->fresh()->remainingAmount());
    }

    public function test_the_balance_falls_month_by_month(): void
    {
        $advance = $this->advance();

        $this->payslip('July');
        $this->payslip('August');

        $this->assertSame(120000.0, $advance->fresh()->recoveredAmount());
        $this->assertSame(1380000.0, $advance->fresh()->remainingAmount());
    }

    /**
     * Payroll recalculates a payslip on every save, so without the unique key on
     * (advance, payslip) the same instalment would be recovered again each time
     * somebody opened and saved it.
     */
    public function test_re_saving_a_payslip_does_not_recover_twice(): void
    {
        $advance = $this->advance();
        $payslip = $this->payslip('July');

        $payslip->update(['paid_days' => 21]);
        $payslip->update(['paid_days' => 22]);

        $this->assertSame(1, $advance->recoveries()->count());
        $this->assertSame(60000.0, $advance->fresh()->recoveredAmount());
    }

    public function test_deleting_a_payslip_gives_the_recovery_back(): void
    {
        // The money was never taken, so the balance has to go back up.
        $advance = $this->advance();
        $payslip = $this->payslip('July');

        $this->assertSame(60000.0, $advance->fresh()->recoveredAmount());

        $payslip->delete();

        $this->assertSame(0.0, $advance->fresh()->recoveredAmount());
        $this->assertSame(1500000.0, $advance->fresh()->remainingAmount());
    }

    /**
     * Otherwise the last month takes a full instalment against a smaller balance
     * and the employee ends up owed money back.
     */
    public function test_the_final_instalment_is_trimmed_to_what_is_left(): void
    {
        $advance = $this->advance(total: 100000, instalment: 60000);

        $this->payslip('July');
        $this->assertSame(40000.0, $advance->fresh()->remainingAmount());

        $august = $this->payslip('August');

        $this->assertSame(40000.0, (float) $august->fresh()->advances, 'only what was left');
        $this->assertSame(0.0, $advance->fresh()->remainingAmount());
    }

    /**
     * A payslip is recalculated on every save, and its own recovery has already
     * reduced the balance by then. Counting it would quietly shrink the deduction
     * each time somebody opened the payslip in the advance's final month.
     */
    public function test_recalculating_a_payslip_ignores_its_own_recovery(): void
    {
        $this->advance(total: 100000, instalment: 60000);
        $payslip = $this->payslip('July');

        $service = app(\App\Modules\Payroll\Services\PayslipService::class);

        $asItself = $service->calculateByParams(
            $this->employee->id, 'July', $this->fiscalYear->id,
            null, null, null, null, null, null, null, null, $payslip->id,
        );
        $this->assertSame(60000.0, (float) $asItself['advances'], 'the payslip keeps its own instalment');

        // Anything else looking at the same employee sees the reduced balance.
        $asAnother = $service->calculateByParams(
            $this->employee->id, 'August', $this->fiscalYear->id,
        );
        $this->assertSame(40000.0, (float) $asAnother['advances'], 'only 40,000 is left for next month');
    }

    public function test_a_cleared_advance_settles_and_stops_deducting(): void
    {
        $advance = $this->advance(total: 60000, instalment: 60000);

        $this->payslip('July');

        $this->assertSame(Advance::STATUS_SETTLED, $advance->fresh()->status);

        $august = $this->payslip('August');

        $this->assertSame(0.0, (float) $august->fresh()->advances, 'nothing left to take');
    }

    public function test_a_cancelled_advance_is_not_deducted(): void
    {
        // Stops payroll taking more without writing off what is owed.
        $advance = $this->advance();
        $advance->update(['status' => Advance::STATUS_CANCELLED]);

        $payslip = $this->payslip('July');

        $this->assertSame(0.0, (float) $payslip->fresh()->advances);
        $this->assertSame(0.0, $advance->fresh()->recoveredAmount());
    }

    public function test_two_advances_are_recovered_oldest_first(): void
    {
        $first = Advance::create([
            'employee_id' => $this->employee->id,
            'total_amount' => 50000, 'monthly_instalment' => 30000,
            'started_on' => '2026-05-01', 'status' => Advance::STATUS_ACTIVE,
        ]);
        $second = Advance::create([
            'employee_id' => $this->employee->id,
            'total_amount' => 200000, 'monthly_instalment' => 20000,
            'started_on' => '2026-06-01', 'status' => Advance::STATUS_ACTIVE,
        ]);

        $payslip = $this->payslip('July');

        $this->assertSame(50000.0, (float) $payslip->fresh()->advances, '30,000 + 20,000');
        $this->assertSame(30000.0, $first->fresh()->recoveredAmount());
        $this->assertSame(20000.0, $second->fresh()->recoveredAmount());
    }

    public function test_a_repayment_made_outside_payroll_is_recorded(): void
    {
        $advance = $this->advance();

        app(AdvanceService::class)->recordManualRecovery($advance, 500000, '2026-07-15', 'Cash returned');

        $this->assertSame(500000.0, $advance->fresh()->recoveredAmount());
        $this->assertSame(1000000.0, $advance->fresh()->remainingAmount());
    }

    public function test_a_repayment_cannot_exceed_what_is_owed(): void
    {
        $advance = $this->advance(total: 100000, instalment: 10000);

        $this->expectExceptionMessage('more than the 100,000.00 still outstanding');

        app(AdvanceService::class)->recordManualRecovery($advance, 150000, '2026-07-15');
    }

    public function test_an_employee_with_no_advance_is_unaffected(): void
    {
        // The settings figure still applies, so payroll is unchanged for everybody
        // who has not been lent anything.
        EmployeeSetting::where('employee_id', $this->employee->id)->update(['advances' => 5000]);

        $payslip = $this->payslip('July');

        $this->assertSame(5000.0, (float) $payslip->fresh()->advances);
    }

    public function test_the_deduction_falls_back_to_settings_when_the_module_is_off(): void
    {
        $this->advance();
        EmployeeSetting::where('employee_id', $this->employee->id)->update(['advances' => 5000]);

        CompanyModule::where('module', 'advances')->update(['enabled' => false]);
        modules()->flush();

        $payslip = $this->payslip('July');

        $this->assertSame(5000.0, (float) $payslip->fresh()->advances, 'payroll carries on without the ledger');
    }
}
