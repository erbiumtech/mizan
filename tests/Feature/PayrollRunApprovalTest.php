<?php

namespace Tests\Feature;

use App\Modules\Attendance\Models\AttendanceDay;
use App\Modules\Core\Models\CompanyModule;
use App\Modules\Core\Models\User;
use App\Modules\Employees\Models\Employee;
use App\Modules\Employees\Models\EmployeeSetting;
use App\Modules\Payroll\Models\PayrollRun;
use App\Modules\Payroll\Models\Payslip;
use App\Modules\Payroll\Services\MonthlyPayrollService;
use App\Modules\Payroll\Services\PayrollPreflight;
use App\Support\TenantSettings;
use Illuminate\Support\Carbon;
use InvalidArgumentException;
use Tests\AccountingTestCase;
use Tests\Concerns\InteractsWithTenant;

/**
 * The payroll run's approval, pre-flight and staleness — docs/saas-launch-plan.md §14.1.
 *
 * One rule: a run never charges a day nobody has marked without saying so. The
 * claims: approved sits between open and locked and is optional until the
 * company says otherwise; the pre-flight blocks a run that would be wrong by
 * construction and warns about what the clerk should know; the run knows when
 * an input moved after it read them; net pay rounds to the company's step.
 */
class PayrollRunApprovalTest extends AccountingTestCase
{
    use InteractsWithTenant;

    private User $accountant;

    private User $manager;

    private Employee $employee;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-09-05 10:00:00');

        $this->actingAs($this->makeUser('Administrator', 'approval-admin@test.local'));
        $company = $this->setCurrentTenant();

        foreach (['employees', 'payroll', 'accounting', 'attendance'] as $module) {
            CompanyModule::updateOrCreate(['company_id' => $company->getKey(), 'module' => $module], ['licensed' => true, 'enabled' => true]);
        }
        modules()->flush();
        $this->seed(\Database\Seeders\TransactionTypeSeeder::class);

        $this->accountant = $this->makeUser('Accountant', 'approval-acc@test.local');
        $this->manager = $this->makeUser('Manager', 'approval-mgr@test.local');

        $this->employee = Employee::create([
            'user_id' => $this->makeUser('Employee', 'approval-emp@test.local')->id, 'employee_id' => 'EMP-A', 'name' => 'Ayesha',
            'gender' => 'Female', 'is_active' => 1, 'bank_account_no' => '0011223344',
        ]);
        EmployeeSetting::create(['employee_id' => $this->employee->id, 'fiscal_year_id' => $this->fiscalYear->id, 'start_date' => '2026-07-01', 'end_date' => '2027-06-30', 'basic_wage' => 100000]);
    }

    private function raise(string $month = 'August'): PayrollRun
    {
        app(MonthlyPayrollService::class)->openMonth($month, $this->fiscalYear);

        return PayrollRun::forMonth($month, $this->fiscalYear);
    }

    // ───────────────────────────────────────────────────────────── approval ──

    public function test_approved_sits_between_open_and_locked_and_send_back_returns_it(): void
    {
        $run = $this->raise();
        $this->assertTrue($run->isOpen());

        $run->approve($this->manager);
        $this->assertTrue($run->fresh()->isApproved());
        $this->assertSame($this->manager->getKey(), (int) $run->fresh()->approved_by);

        $run->fresh()->sendBack($this->manager, 'Overtime missing for EMP-A');
        $fresh = $run->fresh();
        $this->assertTrue($fresh->isOpen());
        $this->assertNull($fresh->approved_by);
        $this->assertSame('Overtime missing for EMP-A', $fresh->sent_back_reason);

        $fresh->approve($this->manager);
        $this->assertNull($fresh->fresh()->sent_back_reason, 'a fresh approval clears the old reason');

        $fresh->fresh()->lock($this->accountant);
        $this->assertTrue($run->fresh()->isLocked());

        $run->fresh()->reopen($this->accountant, 'A late claim');
        $this->assertTrue($run->fresh()->isOpen());
        $this->assertNull($run->fresh()->approved_at, 'reopening withdraws the approval');
    }

    public function test_signing_off_needs_an_approval_only_when_the_company_says_so(): void
    {
        $run = $this->raise();
        $run->lock($this->accountant);
        $this->assertTrue($run->fresh()->isLocked(), 'off by default: the accountant signs off directly');

        app(TenantSettings::class)->set('payroll.require_approval', true);
        $september = $this->raise('September');

        try {
            $september->lock($this->accountant);
            $this->fail('An unapproved run was signed off with the switch on.');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('has not been approved', $e->getMessage());
        }

        $september->approve($this->manager);
        $september->fresh()->lock($this->accountant);
        $this->assertTrue($september->fresh()->isLocked());
    }

    public function test_the_approve_permission_is_its_own(): void
    {
        $run = $this->raise();

        $this->assertTrue($this->manager->can('approve', $run));
        $this->assertFalse($this->accountant->can('approve', $run), 'records, does not approve');
        $this->assertTrue($this->accountant->can('update', $run), 'but may still sign off');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('not open');
        $run->approve($this->manager);
        $run->fresh()->approve($this->manager);
    }

    // ─────────────────────────────────────────────────────────── pre-flight ──

    public function test_the_preflight_blocks_what_would_be_wrong_and_warns_about_the_rest(): void
    {
        // A second employee with no package and no account; the month has not ended.
        $bilal = Employee::create(['user_id' => $this->makeUser('Employee', 'bilal-pre@test.local')->id, 'employee_id' => 'EMP-B', 'name' => 'Bilal', 'gender' => 'Male', 'is_active' => 1]);

        $result = app(PayrollPreflight::class)->check('September', $this->fiscalYear);

        $this->assertCount(1, $result['blockers']);
        $this->assertStringContainsString('No salary package covering the month: EMP-B', $result['blockers'][0]);
        $this->assertStringContainsString('September 2026 has not ended', $result['warnings'][0]);
        $this->assertStringContainsString('Working days with nothing marked', $result['warnings'][1]);
        $this->assertStringContainsString('EMP-A - Employee (', $result['warnings'][1]);

        // Give Bilal a package but no account: blocked while salaries go by bank, not once they are cash.
        EmployeeSetting::create(['employee_id' => $bilal->id, 'fiscal_year_id' => $this->fiscalYear->id, 'start_date' => '2026-07-01', 'end_date' => '2027-06-30', 'basic_wage' => 50000]);
        $result = app(PayrollPreflight::class)->check('September', $this->fiscalYear);
        $this->assertCount(1, $result['blockers']);
        $this->assertStringContainsString('Paid by bank with no account number or IBAN: EMP-B', $result['blockers'][0]);

        app(TenantSettings::class)->set('payroll.pay_by_bank', false);
        $this->assertSame([], app(PayrollPreflight::class)->check('September', $this->fiscalYear)['blockers']);

        // A month that has ended, every working day marked: nothing to say.
        foreach (Carbon::parse('2026-08-01')->daysUntil('2026-08-31') as $day) {
            foreach ([$this->employee, $bilal] as $employee) {
                AttendanceDay::create(['employee_id' => $employee->getKey(), 'date' => $day->toDateString(), 'status' => $day->isWeekday() ? AttendanceDay::STATUS_PRESENT : AttendanceDay::STATUS_WEEKLY_OFF]);
            }
        }
        $this->assertSame(['blockers' => [], 'warnings' => []], app(PayrollPreflight::class)->check('August', $this->fiscalYear));

        // Unmarked days are counted only up to today, never into the future.
        $figures = app(\App\Modules\Payroll\Services\AttendanceFigures::class);
        $this->assertSame(0, $figures->unmarkedDays($this->employee, 'August', $this->fiscalYear, Carbon::now()));
        $this->assertGreaterThan(0, $figures->unmarkedDays($this->employee, 'September', $this->fiscalYear, Carbon::now()));
        $this->assertLessThanOrEqual(4, $figures->unmarkedDays($this->employee, 'September', $this->fiscalYear, Carbon::now()), 'the 1st to the 4th of September, Saturday excluded');
        $this->assertSame(22, $figures->unmarkedDays($this->employee, 'September', $this->fiscalYear), 'the whole month when no day is given');
    }

    public function test_the_command_refuses_on_a_blocker(): void
    {
        config(['multitenancy.switch_tenant_tasks' => [\App\Multitenancy\Tasks\SetPermissionsTeamIdTask::class]]);
        Employee::create(['user_id' => $this->makeUser('Employee', 'nopkg@test.local')->id, 'employee_id' => 'EMP-N', 'name' => 'Nadia', 'gender' => 'Female', 'is_active' => 1]);

        $this->artisan('payroll:open-month', ['--tenant' => [$this->tenant->getKey()], '--month' => 'August', '--fiscal-year' => $this->fiscalYear->getKey()])
            ->expectsOutputToContain('No salary package covering the month: EMP-N')
            ->assertFailed();

        $this->assertSame(0, Payslip::count());
    }

    // ─────────────────────────────────────────────────────────── staleness ──

    public function test_the_run_knows_when_an_input_moved_after_it_read_them(): void
    {
        $run = $this->raise();
        $this->assertNotNull($run->inputs_as_of);
        $this->assertSame([], $run->staleInputs());
        $this->assertFalse($run->isStale());

        Carbon::setTestNow('2026-09-06 09:00:00');
        AttendanceDay::create(['employee_id' => $this->employee->getKey(), 'date' => '2026-08-12', 'status' => AttendanceDay::STATUS_ABSENT]);

        $stale = $run->fresh()->staleInputs();
        $this->assertSame(['attendance'], array_keys($stale));
        $this->assertSame('2026-09-06 09:00:00', $stale['attendance']->toDateTimeString());

        // A change in another month is not this run's business.
        AttendanceDay::create(['employee_id' => $this->employee->getKey(), 'date' => '2026-09-01', 'status' => AttendanceDay::STATUS_ABSENT]);
        $this->assertSame(['attendance'], array_keys($run->fresh()->staleInputs()));

        $run->fresh()->markInputsRead();
        $this->assertFalse($run->fresh()->isStale());
    }

    // ───────────────────────────────────────────────────────────── rounding ──

    public function test_net_pay_rounds_to_the_companys_step(): void
    {
        EmployeeSetting::where('employee_id', $this->employee->id)->first()->update(['basic_wage' => 100007]);
        $run = $this->raise();
        $net = (float) $run->payslips()->first()->net_salary;
        $this->assertNotSame(0.0, fmod($net, 10.0), 'the fixture must need rounding for the test to mean anything');

        app(TenantSettings::class)->set('payroll.rounding', 10);
        $september = $this->raise('September');
        $rounded = (float) $september->payslips()->first()->net_salary;
        $this->assertSame(0.0, fmod($rounded, 10.0), 'to the nearest ten');
        $this->assertEqualsWithDelta($net, $rounded, 5.0);

        app(TenantSettings::class)->set('payroll.pay_day', 28);
        $this->assertSame('2026-08-28', $run->payDay()->toDateString());
    }
}
