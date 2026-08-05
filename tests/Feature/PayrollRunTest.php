<?php

namespace Tests\Feature;

use App\Modules\Accounting\Models\Payment;
use App\Modules\Accounting\Services\PaymentService;
use App\Modules\Core\Models\CompanyModule;
use App\Modules\Employees\Models\Employee;
use App\Modules\Employees\Models\EmployeeSetting;
use App\Modules\Payroll\Models\PayrollRun;
use App\Modules\Payroll\Models\Payslip;
use App\Modules\Payroll\Services\MonthlyPayrollService;
use Tests\AccountingTestCase;
use Tests\Concerns\InteractsWithTenant;

/**
 * A month of payroll as something that can be signed off.
 *
 * Payslips were independent rows: nothing to approve, so nothing was ever agreed, and
 * nothing to lock, so a payslip could be edited after it had been sent to the
 * employee, paid into their bank and posted to the ledger. Only `sent_at` hinted that
 * it should not be, and a hint is not a control.
 */
class PayrollRunTest extends AccountingTestCase
{
    use InteractsWithTenant;

    private Employee $employee;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs($this->makeUser('Administrator', 'runs@test.local'));
        $company = $this->setCurrentTenant();

        foreach (['employees', 'payroll', 'accounting'] as $module) {
            CompanyModule::updateOrCreate(
                ['company_id' => $company->getKey(), 'module' => $module],
                ['licensed' => true, 'enabled' => true],
            );
        }
        modules()->flush();

        $this->seed(\Database\Seeders\TransactionTypeSeeder::class);

        $this->employee = Employee::create([
            'user_id' => $this->makeUser('Employee', 'runemployee@test.local')->id,
            'employee_id' => 'EMP-RUN',
            'gender' => 'Male',
            'phone' => '0300-0000000',
            'is_active' => 1,
        ]);

        EmployeeSetting::create([
            'employee_id' => $this->employee->id,
            'fiscal_year_id' => $this->fiscalYear->id,
            'start_date' => '2026-07-01',
            'end_date' => '2027-06-30',
            'basic_wage' => 400000,
        ]);
    }

    private function payslip(string $month = 'August'): Payslip
    {
        return Payslip::create([
            'employee_id' => $this->employee->id,
            'fiscal_year_id' => $this->fiscalYear->id,
            'month' => $month,
            'total_working_days' => 22,
            'paid_days' => 22,
        ]);
    }

    private function payrollRun(string $month = 'August'): PayrollRun
    {
        return PayrollRun::forMonth($month, $this->fiscalYear);
    }

    // ---- The run exists without anybody making it --------------------------

    public function test_a_payslip_joins_its_months_run(): void
    {
        $payslip = $this->payslip();

        $this->assertNotNull($payslip->payroll_run_id);
        $this->assertSame('August', $payslip->payrollRun->month);
    }

    public function test_two_payslips_in_a_month_share_one_run(): void
    {
        $first = $this->payslip('August');

        $other = Employee::create([
            'user_id' => $this->makeUser('Employee', 'second@test.local')->id,
            'employee_id' => 'EMP-2',
            'gender' => 'Male',
            'phone' => '0300-0000001',
        ]);

        EmployeeSetting::create([
            'employee_id' => $other->id,
            'fiscal_year_id' => $this->fiscalYear->id,
            'start_date' => '2026-07-01',
            'end_date' => '2027-06-30',
            'basic_wage' => 250000,
        ]);

        $second = Payslip::create([
            'employee_id' => $other->id,
            'fiscal_year_id' => $this->fiscalYear->id,
            'month' => 'August',
            'total_working_days' => 22,
            'paid_days' => 22,
        ]);

        $this->assertSame($first->payroll_run_id, $second->payroll_run_id);
        $this->assertSame(1, PayrollRun::count());
    }

    public function test_different_months_are_different_runs(): void
    {
        $this->assertNotSame(
            $this->payslip('July')->payroll_run_id,
            $this->payslip('August')->payroll_run_id,
        );
    }

    public function test_the_run_reports_what_the_month_came_to(): void
    {
        $payslip = $this->payslip()->fresh();
        $totals = $this->payrollRun()->totals();

        $this->assertSame(1, $totals['payslips']);
        $this->assertSame(round((float) $payslip->total_earnings, 2), $totals['gross']);
        $this->assertSame(round((float) $payslip->net_salary, 2), $totals['net']);
        $this->assertSame(0, $totals['accepted'], 'nobody has agreed to it yet');
    }

    // ---- Signing off -------------------------------------------------------

    public function test_an_empty_month_cannot_be_signed_off(): void
    {
        // There is nothing to agree.
        $this->expectExceptionMessage('nothing to agree');

        $this->payrollRun()->lock(auth()->user());
    }

    public function test_signing_off_records_who_and_when(): void
    {
        $this->payslip();

        $run = $this->payrollRun()->lock(auth()->user());

        $this->assertTrue($run->isLocked());
        $this->assertSame(auth()->id(), $run->locked_by);
        $this->assertNotNull($run->locked_at);
    }

    /**
     * The control itself, and enforced on the model: Administrators pass every policy
     * check, and a sign-off the most privileged user walks through is not a sign-off.
     */
    public function test_a_signed_off_payslip_cannot_be_changed(): void
    {
        $payslip = $this->payslip();
        $this->payrollRun()->lock(auth()->user());

        $this->assertTrue(auth()->user()->isAdministrator());

        try {
            $payslip->update(['paid_days' => 20]);
            $this->fail('a locked payslip was changed');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('signed off', $e->getMessage());
        }

        $this->assertSame(22, (int) $payslip->fresh()->paid_days);
    }

    public function test_a_signed_off_month_takes_no_new_payslips(): void
    {
        $this->payslip();
        $this->payrollRun()->lock(auth()->user());

        $other = Employee::create([
            'user_id' => $this->makeUser('Employee', 'late@test.local')->id,
            'employee_id' => 'EMP-LATE',
            'gender' => 'Male',
            'phone' => '0300-0000002',
        ]);

        $this->expectExceptionMessage('signed off');

        Payslip::create([
            'employee_id' => $other->id,
            'fiscal_year_id' => $this->fiscalYear->id,
            'month' => 'August',
            'total_working_days' => 22,
            'paid_days' => 22,
        ]);
    }

    public function test_a_signed_off_payslip_cannot_be_deleted(): void
    {
        $payslip = $this->payslip();
        $this->payrollRun()->lock(auth()->user());

        $this->expectExceptionMessage('signed off');

        $payslip->delete();
    }

    /**
     * An employee accepting what was sent to them is not a change to the month's
     * figures — and refusing it would leave them unable to respond to a payslip they
     * have already been given.
     */
    public function test_an_employee_can_still_accept_a_signed_off_payslip(): void
    {
        $payslip = $this->payslip();
        $this->payrollRun()->lock(auth()->user());

        $payslip->recordEmployeeReview(Payslip::REVIEW_ACCEPTED);

        $this->assertSame(Payslip::REVIEW_ACCEPTED, $payslip->fresh()->employee_review);
    }

    /** Locking freezes the figures, not the money already on its way out. */
    public function test_a_signed_off_month_can_still_be_paid(): void
    {
        $payslip = $this->payslip();
        $payslip->recordEmployeeReview(Payslip::REVIEW_ACCEPTED);

        app(PaymentService::class)->generateSalaryPayments('August', $this->fiscalYear);
        $this->payrollRun()->lock(auth()->user());

        $payment = Payment::where('payslip_id', $payslip->id)->firstOrFail();
        $released = app(PaymentService::class)->release([$payment], 'BATCH-LOCKED');

        $this->assertCount(1, $released);
        $this->assertSame(Payment::STATUS_EXPORTED, $payment->fresh()->status);
    }

    public function test_the_scheduler_will_not_add_to_a_signed_off_month(): void
    {
        $this->payslip();
        $this->payrollRun()->lock(auth()->user());

        $this->expectExceptionMessage('Reopen the run');

        app(MonthlyPayrollService::class)->openMonth('August', $this->fiscalYear);
    }

    // ---- Reopening ---------------------------------------------------------

    public function test_reopening_needs_a_reason(): void
    {
        // The question an auditor asks about a month that was agreed and then changed.
        $this->payslip();
        $run = $this->payrollRun()->lock(auth()->user());

        $this->expectExceptionMessage('needs a reason');

        $run->reopen(auth()->user(), '  ');
    }

    public function test_reopening_records_the_reason_and_allows_changes_again(): void
    {
        $payslip = $this->payslip();
        $run = $this->payrollRun()->lock(auth()->user());

        $run->reopen(auth()->user(), 'Attendance for two employees arrived after sign-off.');

        $this->assertFalse($run->fresh()->isLocked());
        $this->assertStringContainsString('Attendance', $run->fresh()->reopen_reason);
        $this->assertSame(auth()->id(), $run->fresh()->reopened_by);

        $payslip->update(['paid_days' => 20]);
        $this->assertSame(20, (int) $payslip->fresh()->paid_days);
    }

    public function test_an_open_month_cannot_be_reopened(): void
    {
        $this->payslip();

        $this->expectExceptionMessage('is not locked');

        $this->payrollRun()->reopen(auth()->user(), 'Nothing to reopen.');
    }

    public function test_signing_off_twice_is_refused(): void
    {
        $this->payslip();
        $run = $this->payrollRun()->lock(auth()->user());

        $this->expectExceptionMessage('already locked');

        $run->lock(auth()->user());
    }
}
