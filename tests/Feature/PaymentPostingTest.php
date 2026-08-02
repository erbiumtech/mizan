<?php

namespace Tests\Feature;

use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\Beneficiary;
use App\Modules\Accounting\Models\JournalEntry;
use App\Modules\Accounting\Models\Payment;
use App\Modules\Accounting\Models\TransactionType;
use App\Modules\Accounting\Services\FinancialReportService;
use App\Modules\Accounting\Services\PaymentService;
use App\Modules\Employees\Models\Employee;
use App\Modules\Employees\Models\EmployeeSetting;
use App\Modules\Payroll\Models\Payslip;
use App\Support\ModuleMap;
use Tests\AccountingTestCase;
use Tests\Concerns\InteractsWithTenant;

/**
 * Payments reaching the ledger.
 *
 * They did not. Approving a payment created its journal entry through
 * JournalEntryService::create(), which always writes a *draft*, and nothing ever
 * posted it — and the Profit & Loss and the Trial Balance read posted entries
 * only. Every payment a company had ever approved was invisible in both, with
 * nothing to show anything was wrong: the payment said "approved" and carried an
 * entry number.
 */
class PaymentPostingTest extends AccountingTestCase
{
    use InteractsWithTenant;

    private Beneficiary $payee;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs($this->makeUser('Administrator', 'posting@test.local'));
        $this->setCurrentTenant();

        $this->seed(\Database\Seeders\TransactionTypeSeeder::class);

        $this->payee = Beneficiary::create(['name' => 'A supplier', 'is_active' => true]);
    }

    private function payment(string $code, float $amount, array $attributes = []): Payment
    {
        return Payment::create(array_merge([
            'payable_type' => ModuleMap::alias(Beneficiary::class),
            'payable_id' => $this->payee->id,
            'transaction_type_id' => TransactionType::byCode($code)?->id,
            'amount' => $amount,
            'value_date' => '2026-07-15',
            'details' => ucfirst($code).' for July',
            'status' => Payment::STATUS_DRAFT,
        ], $attributes));
    }

    private function balanceOf(string $code): float
    {
        $account = Account::where('code', $code)->firstOrFail();

        return round((float) \App\Modules\Accounting\Models\JournalEntryLine::where('account_id', $account->id)
            ->whereHas('journalEntry', fn ($query) => $query->where('is_posted', true))
            ->sum('debit_amount'), 2);
    }

    public function test_approving_a_payment_posts_its_entry(): void
    {
        $payment = $this->payment('rent', 92000);

        app(PaymentService::class)->approve($payment);

        $entry = $payment->fresh()->journalEntry;

        $this->assertNotNull($entry);
        $this->assertSame(JournalEntry::STATUS_POSTED, $entry->status);
        $this->assertTrue((bool) $entry->is_posted);
    }

    /** The report is the thing the user was looking at, so it is what is asserted. */
    public function test_the_payment_reaches_the_profit_and_loss(): void
    {
        $payment = $this->payment('rent', 92000);

        $before = app(FinancialReportService::class)->profitAndLoss('2026-07-01', '2026-07-31');
        $this->assertSame(0.0, round((float) $before['expenses']['total'], 2));

        app(PaymentService::class)->approve($payment);

        $after = app(FinancialReportService::class)->profitAndLoss('2026-07-01', '2026-07-31');
        $this->assertSame(92000.0, round((float) $after['expenses']['total'], 2));
    }

    public function test_releasing_a_draft_books_it_on_the_way_out(): void
    {
        // Money left the company. It cannot leave the books untouched, and this is
        // how 1.9m of released payments came to have no entry at all.
        $payment = $this->payment('rent', 92000);

        app(PaymentService::class)->release([$payment], 'BATCH-1');

        $payment->refresh();

        $this->assertSame(Payment::STATUS_EXPORTED, $payment->status);
        $this->assertNotNull($payment->journal_entry_id);
        $this->assertTrue((bool) $payment->journalEntry->is_posted);
        $this->assertSame(92000.0, $this->balanceOf('5700'));
    }

    /**
     * The payslip already booked the wage as an expense and credited Salaries
     * Payable. The salary transaction type points at the salary expense account,
     * so booking the payment there too would count the wage twice and leave the
     * liability standing for ever.
     */
    public function test_paying_a_payslip_clears_the_payable_rather_than_the_expense(): void
    {
        $employee = Employee::create([
            'user_id' => $this->makeUser('Employee', 'paid@test.local')->id,
            'employee_id' => 'EMP-1',
            'gender' => 'Male',
            'phone' => '0300-0000000',
        ]);

        EmployeeSetting::create([
            'employee_id' => $employee->id,
            'fiscal_year_id' => $this->fiscalYear->id,
            'start_date' => '2026-07-01',
            'end_date' => '2027-06-30',
            'basic_wage' => 400000,
        ]);

        $payslip = Payslip::create([
            'employee_id' => $employee->id,
            'fiscal_year_id' => $this->fiscalYear->id,
            'month' => 'July',
            'total_working_days' => 22,
            'paid_days' => 22,
        ]);

        $salaryExpenseBefore = $this->balanceOf('5100');

        $payment = $this->payment('salary', (float) $payslip->net_salary, [
            'payslip_id' => $payslip->id,
            'details' => 'Salary July 2026',
        ]);

        app(PaymentService::class)->approve($payment);

        $this->assertSame(
            $salaryExpenseBefore,
            $this->balanceOf('5100'),
            'the wage is not expensed a second time',
        );
        $this->assertSame(
            round((float) $payslip->net_salary, 2),
            $this->balanceOf('2300'),
            'the payable is cleared instead',
        );
    }

    /**
     * It used to mark the payment approved and book nothing, silently — the one
     * outcome that leaves no trace anywhere to notice.
     */
    public function test_a_type_with_no_account_refuses_rather_than_booking_nothing(): void
    {
        $orphan = TransactionType::create(['name' => 'Unmapped', 'code' => 'unmapped', 'is_active' => true]);
        $payment = $this->payment('rent', 5000, ['transaction_type_id' => $orphan->id]);

        $this->expectExceptionMessage("the 'Unmapped' transaction type has no account");

        try {
            app(PaymentService::class)->approve($payment);
        } finally {
            $this->assertSame(Payment::STATUS_DRAFT, $payment->fresh()->status, 'and it stays a draft');
        }
    }

    public function test_approving_twice_does_not_book_twice(): void
    {
        $payment = $this->payment('rent', 92000);

        app(PaymentService::class)->approve($payment);

        $this->assertSame(92000.0, $this->balanceOf('5700'));

        try {
            app(PaymentService::class)->approve($payment->fresh());
        } catch (\InvalidArgumentException) {
            // Already approved, which is the point.
        }

        $this->assertSame(92000.0, $this->balanceOf('5700'));
        $this->assertSame(1, JournalEntry::count());
    }
}
