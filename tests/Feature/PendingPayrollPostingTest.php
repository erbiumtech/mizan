<?php

namespace Tests\Feature;

use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\JournalEntry;
use App\Modules\Accounting\Services\JournalEntryService;
use App\Modules\Core\Models\User;
use App\Modules\Employees\Models\Employee;
use App\Modules\Employees\Models\EmployeeSetting;
use App\Modules\Payroll\Models\Payslip;
use App\Modules\Payroll\Services\PayrollAutoPosting;
use App\Modules\Payroll\Services\PendingPayrollPoster;
use Illuminate\Support\Facades\Artisan;
use Tests\AccountingTestCase;

/**
 * The payroll backlog, and the flag that stops one forming.
 *
 * Found in production the expensive way: with auto-posting off, a payslip's entry stops at
 * `pending_approval` and no balance moves, so paying the salary debits 2300 Salaries Payable
 * against a credit that was never posted and the liability goes negative — the books saying
 * money was paid out that was never owed. Turning the flag on fixes the next payslip and not
 * the backlog, which is what these two services are between them for.
 *
 * Asserted against the services rather than the commands: both are wrapped in Spatie's
 * TenantAware trait, which iterates real per-tenant database connections and cannot run in
 * this single-database suite (same as PayrollAccountCheckTest).
 */
class PendingPayrollPostingTest extends AccountingTestCase
{
    private Employee $employee;

    protected function setUp(): void
    {
        parent::setUp();

        $user = User::create([
            'name' => 'Backlog Employee',
            'email' => 'backlog-emp@test.local',
            'password' => bcrypt('password'),
            'status' => 1,
        ]);

        $this->employee = Employee::create([
            'user_id' => $user->id,
            'employee_id' => 'EMP-B1',
            'phone' => '0300-0000000',
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
            'petrol_allowance' => 13500,
            'advances' => 10000,
            'meal_deduction' => 2000,
            'esi_health_insurance' => 1500,
        ]);
    }

    private function poster(): PendingPayrollPoster
    {
        return app(PendingPayrollPoster::class);
    }

    private function payslip(string $month = 'July'): Payslip
    {
        return Payslip::create([
            'employee_id' => $this->employee->id,
            'month' => $month,
            'fiscal_year_id' => $this->fiscalYear->id,
            'total_working_days' => 22,
            'paid_days' => 22,
            'lop_days' => 0,
            'leaves_taken' => 0,
        ]);
    }

    private function payable(): float
    {
        return (float) Account::where('code', '2300')->value('balance');
    }

    public function test_both_commands_are_registered(): void
    {
        $this->assertArrayHasKey('payroll:post-pending', Artisan::all());
        $this->assertArrayHasKey('payroll:auto-post', Artisan::all());
    }

    public function test_a_payslip_saved_with_auto_posting_off_leaves_an_unposted_entry(): void
    {
        config([PayrollAutoPosting::SETTING_KEY => false]);

        $payslip = $this->payslip();

        $this->assertCount(1, $this->poster()->pending());
        $this->assertSame('pending_approval', $payslip->journalEntries()->first()->status);
        $this->assertSame(0.0, $this->payable(), 'nothing reached the ledger');
    }

    public function test_posting_the_backlog_moves_the_payable_to_what_is_owed(): void
    {
        config([PayrollAutoPosting::SETTING_KEY => false]);

        $payslip = $this->payslip();

        $result = $this->poster()->post();

        $this->assertCount(1, $result['posted']);
        $this->assertSame([], $result['failed']);
        $this->assertSame((float) $payslip->fresh()->net_salary, $this->payable());
        $this->assertSame([], $this->poster()->pending()->all(), 'nothing left pending');
    }

    /** The bug that started this: a payment against an unposted accrual. */
    public function test_it_clears_a_negative_payable_left_by_paying_an_unposted_accrual(): void
    {
        config([PayrollAutoPosting::SETTING_KEY => false]);

        $payslip = $this->payslip();
        $net = (float) $payslip->fresh()->net_salary;

        // The salary payment, posted on its own: debit the payable, credit the bank.
        $entries = app(JournalEntryService::class);
        $payment = $entries->create(
            ['entry_date' => '2026-07-31', 'entry_type' => 'general', 'memo' => 'Employee Salary July 2026'],
            [
                ['account_id' => Account::where('code', '2300')->firstOrFail()->id, 'debit_amount' => $net],
                ['account_id' => Account::where('code', '1100')->firstOrFail()->id, 'credit_amount' => $net],
            ],
        );
        $payment->update(['status' => JournalEntry::STATUS_APPROVED, 'approved_at' => now()]);
        $entries->post($payment);

        $this->assertSame(-$net, $this->payable(), 'the reported symptom: a negative liability');

        $this->poster()->post();

        $this->assertSame(0.0, $this->payable(), 'accrual and payment now cancel');
    }

    public function test_a_dry_run_lists_without_posting(): void
    {
        config([PayrollAutoPosting::SETTING_KEY => false]);

        $this->payslip();

        $result = $this->poster()->post(dryRun: true);

        $this->assertCount(1, $result['posted']);
        $this->assertSame(0.0, $this->payable(), 'a dry run must not touch a balance');
        $this->assertCount(1, $this->poster()->pending(), 'and must leave the entry pending');
    }

    public function test_it_finds_nothing_when_payslips_post_themselves(): void
    {
        config([PayrollAutoPosting::SETTING_KEY => true]);

        $payslip = $this->payslip();

        $this->assertSame([], $this->poster()->pending()->all());
        $this->assertSame((float) $payslip->fresh()->net_salary, $this->payable());
    }

    public function test_it_leaves_manual_entries_alone(): void
    {
        config([PayrollAutoPosting::SETTING_KEY => false]);

        // A manual entry awaiting approval is awaiting a person. This command is not that
        // person, which is why the query is scoped to payroll-sourced entries.
        $manual = app(JournalEntryService::class)->create(
            ['entry_date' => '2026-07-15', 'entry_type' => 'general', 'memo' => 'Utilities August'],
            [
                ['account_id' => Account::where('code', '5750')->firstOrFail()->id, 'debit_amount' => 90000],
                ['account_id' => Account::where('code', '2400')->firstOrFail()->id, 'credit_amount' => 90000],
            ],
        );

        $this->payslip();

        $pending = $this->poster()->pending();

        $this->assertCount(1, $pending);
        $this->assertNotContains($manual->entry_number, $pending->pluck('entry_number')->all());

        $this->poster()->post();

        $this->assertFalse((bool) $manual->fresh()->is_posted, 'the manual entry is untouched');
    }

    public function test_it_posts_every_month_it_finds(): void
    {
        config([PayrollAutoPosting::SETTING_KEY => false]);

        $july = $this->payslip('July');
        $august = $this->payslip('August');

        $this->assertCount(2, $this->poster()->pending());

        $result = $this->poster()->post();

        $this->assertCount(2, $result['posted']);
        $this->assertSame(
            round((float) $july->fresh()->net_salary + (float) $august->fresh()->net_salary, 2),
            round($this->payable(), 2),
        );
    }

    /** Posted by the system under the auto-post policy, and recorded as such. */
    public function test_posted_entries_carry_no_approver(): void
    {
        config([PayrollAutoPosting::SETTING_KEY => false]);

        $payslip = $this->payslip();

        $this->poster()->post();

        $entry = $payslip->journalEntries()->first();

        $this->assertTrue((bool) $entry->is_posted);
        $this->assertSame('posted', $entry->status);
        $this->assertNull($entry->approved_by);
        $this->assertNotNull($entry->approved_at);
    }

    public function test_the_toggle_reads_and_writes_the_company_setting(): void
    {
        $autoPosting = app(PayrollAutoPosting::class);

        $autoPosting->set(true);
        $this->assertTrue($autoPosting->isEnabled());
        $this->assertTrue((bool) setting(PayrollAutoPosting::SETTING_KEY), 'the same key PayrollPostingService reads');

        $autoPosting->set(false);
        $this->assertFalse($autoPosting->isEnabled());
    }

    /** Switching it on decides the next payslip, never the ones already sitting there. */
    public function test_turning_it_on_does_not_post_the_backlog(): void
    {
        config([PayrollAutoPosting::SETTING_KEY => false]);

        $this->payslip();

        app(PayrollAutoPosting::class)->set(true);

        $this->assertCount(1, $this->poster()->pending(), 'the backlog is still there');
        $this->assertSame(0.0, $this->payable());

        // ...and the next payslip does post itself.
        $august = $this->payslip('August');

        $this->assertSame((float) $august->fresh()->net_salary, $this->payable());
        $this->assertCount(1, $this->poster()->pending());
    }
}
