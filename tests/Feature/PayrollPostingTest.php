<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Modules\Employees\Models\Employee;
use App\Modules\Employees\Models\EmployeeSetting;
use App\Models\JournalEntry;
use App\Models\Payslip;
use App\Models\User;
use App\Services\JournalEntryService;
use Tests\AccountingTestCase;

class PayrollPostingTest extends AccountingTestCase
{
    private Employee $employee;

    protected function setUp(): void
    {
        parent::setUp();

        $user = User::create([
            'name' => 'Payroll Employee',
            'email' => 'payroll-emp@test.local',
            'password' => bcrypt('password'),
            'status' => 1,
        ]);

        $this->employee = Employee::create([
            'user_id' => $user->id,
            'employee_id' => 'EMP-T9',
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

    private function makePayslip(): Payslip
    {
        return Payslip::create([
            'employee_id' => $this->employee->id,
            'month' => 'July',
            'fiscal_year_id' => $this->fiscalYear->id,
            'total_working_days' => 22,
            'paid_days' => 22,
            'lop_days' => 0,
            'leaves_taken' => 0,
        ]);
    }

    public function test_saving_payslip_creates_balanced_pending_entry(): void
    {
        $payslip = $this->makePayslip();
        $entry = $payslip->journalEntries()->first();

        $this->assertNotNull($entry);
        $this->assertSame('pending_approval', $entry->status);
        $this->assertTrue($entry->isBalanced());
        $this->assertSame((float) $payslip->total_earnings, $entry->total_debits);
        $this->assertSame('2026-07-01', $entry->entry_date->toDateString());
    }

    public function test_entry_lines_map_components_to_correct_accounts(): void
    {
        $payslip = $this->makePayslip();
        $lines = $payslip->journalEntries()->first()->lines()->with('account')->get();

        $byCode = $lines->keyBy(fn ($l) => $l->account->code);

        $this->assertSame((float) $payslip->basic_wage, (float) $byCode['5100']->debit_amount);
        $this->assertSame((float) $payslip->medical_allowance, (float) $byCode['5200']->debit_amount);
        $this->assertSame((float) $payslip->withholding_tax, (float) $byCode['2100']->credit_amount);
        $this->assertSame((float) $payslip->esi_health_insurance, (float) $byCode['2200']->credit_amount);
        $this->assertSame((float) $payslip->advances, (float) $byCode['1200']->credit_amount);
        $this->assertSame((float) $payslip->net_salary, (float) $byCode['2300']->credit_amount);
    }

    public function test_updating_payslip_replaces_pending_entry(): void
    {
        $payslip = $this->makePayslip();
        $originalEntryId = $payslip->journalEntries()->first()->id;

        $payslip->update(['bonus' => 50000]);

        $live = JournalEntry::where('source_type', Payslip::class)
            ->where('source_id', $payslip->id)
            ->get();

        $this->assertCount(1, $live);
        $this->assertNotSame($originalEntryId, $live->first()->id);
        $this->assertSame((float) $payslip->fresh()->total_earnings, $live->first()->total_debits);
        $this->assertNull(JournalEntry::find($originalEntryId));
    }

    public function test_deleting_payslip_with_posted_entry_creates_reversal(): void
    {
        $payslip = $this->makePayslip();
        $entry = $payslip->journalEntries()->first();

        $approver = $this->makeUser('Manager', 'payroll-approver@test.local');
        $service = app(JournalEntryService::class);
        $service->approve($entry, $approver);
        $service->post($entry);

        $salariesPayable = Account::where('code', '2300')->firstOrFail();
        $this->assertGreaterThan(0, (float) $salariesPayable->fresh()->balance);

        $payslip->delete();

        $this->assertSame(0.0, (float) $salariesPayable->fresh()->balance);
        $this->assertSame(0.0, (float) Account::where('code', '5100')->first()->balance);
        $this->assertSame(1, JournalEntry::where('entry_type', 'reversing')->count());
    }

    public function test_deleting_payslip_with_pending_entry_just_deletes_it(): void
    {
        $payslip = $this->makePayslip();

        $payslip->delete();

        $this->assertSame(0, JournalEntry::count());
    }

    public function test_auto_post_config_posts_immediately(): void
    {
        config(['accounting.auto_post_payroll' => true]);

        $payslip = $this->makePayslip();
        $entry = $payslip->journalEntries()->first();

        $this->assertSame('posted', $entry->status);
        $this->assertTrue($entry->is_posted);
        $this->assertSame(
            (float) $payslip->net_salary,
            (float) Account::where('code', '2300')->first()->balance
        );
    }
}
