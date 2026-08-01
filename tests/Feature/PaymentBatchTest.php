<?php

namespace Tests\Feature;

use App\Filament\Pages\BankPaymentFile;
use App\Filament\Pages\SalaryBankFile;
use App\Models\Beneficiary;
use App\Models\Employee;
use App\Models\Payment;
use App\Models\Payslip;
use App\Models\TransactionType;
use App\Services\PaymentService;
use Database\Seeders\TransactionTypeSeeder;
use Filament\Actions\Testing\TestAction;
use Livewire\Livewire;
use Tests\AccountingTestCase;
use Tests\Concerns\InteractsWithTenant;

/**
 * A month's salaries do not all leave at once: they go as employees accept their
 * payslips, so the file is built in batches.
 *
 * Two properties matter more than the rest, because getting either wrong moves
 * money twice or not at all — nothing already released may appear in a later
 * batch, and nothing unaccepted may be released at all.
 */
class PaymentBatchTest extends AccountingTestCase
{
    use InteractsWithTenant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(TransactionTypeSeeder::class);

        $this->actingAs($this->makeUser('Administrator', 'batches@test.local'));
        $this->setCurrentTenant();
    }

    private function employee(string $email): Employee
    {
        return Employee::create([
            'user_id' => $this->makeUser('Employee', $email)->id,
            'employee_id' => 'EMP-'.strtoupper(substr(md5($email), 0, 5)),
            'gender' => 'Male',
            'phone' => '0300-0000000',
            'bank_account_no' => '0102030405',
            'iban_no' => 'PK24HABB0000001123456702',
        ]);
    }

    private function payslip(Employee $employee, string $review, string $month = 'July'): Payslip
    {
        $payslip = Payslip::create([
            'employee_id' => $employee->id,
            'fiscal_year_id' => $this->fiscalYear->id,
            'month' => $month,
            'total_working_days' => 22,
            'paid_days' => 22,
            'basic_wage' => 100000,
            'net_salary' => 90000,
        ]);

        // Set straight on the column: recordEmployeeReview() refuses a second
        // review, and these fixtures need a state, not the workflow.
        \Illuminate\Support\Facades\DB::table('payslips')
            ->where('id', $payslip->id)
            ->update(['employee_review' => $review]);

        return $payslip->fresh();
    }

    private function salaryFile(string $month = 'July'): \Livewire\Features\SupportTesting\Testable
    {
        return Livewire::test(SalaryBankFile::class)
            ->fillForm(['fiscal_year_id' => $this->fiscalYear->id, 'month' => $month]);
    }

    // --- the acceptance gate -------------------------------------------------

    public function test_only_an_accepted_payslip_can_be_released(): void
    {
        $accepted = $this->payslip($this->employee('accepted@test.local'), Payslip::REVIEW_ACCEPTED);
        $this->payslip($this->employee('pending@test.local'), Payslip::REVIEW_PENDING);
        $this->payslip($this->employee('rejected@test.local'), Payslip::REVIEW_REJECTED);

        $page = $this->salaryFile()->instance();

        $releasable = collect($page->getReleasablePayments());

        $this->assertCount(1, $releasable);
        $this->assertSame($accepted->id, $releasable->first()['payslip_id']);

        // The other two stay on screen with a reason rather than vanishing.
        $held = collect($page->getPayments())->where('releasable', false);

        $this->assertCount(2, $held);
        $this->assertNotEmpty($held->every(fn (array $row): bool => filled($row['blocked_reason'])));
    }

    public function test_a_rejection_says_so_and_carries_the_reason(): void
    {
        $employee = $this->employee('why@test.local');
        $payslip = $this->payslip($employee, Payslip::REVIEW_REJECTED);

        \Illuminate\Support\Facades\DB::table('payslips')
            ->where('id', $payslip->id)
            ->update(['employee_rejection_reason' => 'Overtime missing']);

        $row = collect($this->salaryFile()->instance()->getPayments())->firstWhere('payslip_id', $payslip->id);

        $this->assertStringContainsString('rejected', strtolower($row['blocked_reason']));
        $this->assertStringContainsString('Overtime missing', $row['blocked_reason']);
    }

    // --- batching ------------------------------------------------------------

    public function test_a_released_salary_does_not_appear_in_the_next_batch(): void
    {
        // The property the whole feature exists for.
        $first = $this->payslip($this->employee('first@test.local'), Payslip::REVIEW_ACCEPTED);
        $second = $this->payslip($this->employee('second@test.local'), Payslip::REVIEW_PENDING);

        $this->salaryFile()->callAction(TestAction::make('csv'));

        $payment = Payment::where('payslip_id', $first->id)->firstOrFail();

        $this->assertSame(Payment::STATUS_EXPORTED, $payment->status);
        $this->assertNotNull($payment->released_at);
        $this->assertSame('SAL-'.$this->yearOfJuly().'-07-B1', $payment->batch_reference);

        // The second employee accepts afterwards; only they are in batch two.
        \Illuminate\Support\Facades\DB::table('payslips')
            ->where('id', $second->id)
            ->update(['employee_review' => Payslip::REVIEW_ACCEPTED]);

        $releasable = collect($this->salaryFile()->instance()->getReleasablePayments());

        $this->assertCount(1, $releasable);
        $this->assertSame($second->id, $releasable->first()['payslip_id']);
    }

    public function test_batches_are_numbered_in_sequence(): void
    {
        $this->payslip($this->employee('b1@test.local'), Payslip::REVIEW_ACCEPTED);
        $this->salaryFile()->callAction(TestAction::make('csv'));

        $later = $this->payslip($this->employee('b2@test.local'), Payslip::REVIEW_ACCEPTED);
        $this->salaryFile()->callAction(TestAction::make('csv'));

        $this->assertSame(
            'SAL-'.$this->yearOfJuly().'-07-B2',
            Payment::where('payslip_id', $later->id)->value('batch_reference'),
        );
    }

    public function test_the_file_contains_exactly_what_was_released(): void
    {
        // The set in the CSV and the set marked released have to be the same, or
        // the bank pays somebody the books think is still waiting.
        $accepted = $this->payslip($this->employee('in-file@test.local'), Payslip::REVIEW_ACCEPTED);
        $pending = $this->payslip($this->employee('not-in-file@test.local'), Payslip::REVIEW_PENDING);

        $csv = app(\App\Services\SalaryBankExportService::class)->export(
            'July',
            $this->fiscalYear,
            null,
            [$accepted->id],
        );

        $rows = collect(explode("\n", trim($csv)))->filter(fn (string $l) => str_starts_with($l, 'P,'));

        $this->assertCount(1, $rows, 'one payment row, for the accepted payslip only');
        $this->assertStringNotContainsString($pending->employee->employee_id, $csv);
    }

    public function test_nothing_is_released_when_no_payslip_has_been_accepted(): void
    {
        $this->payslip($this->employee('none@test.local'), Payslip::REVIEW_PENDING);

        $this->assertSame([], $this->salaryFile()->instance()->getReleasablePayments());

        // And the download is not offered at all.
        $this->salaryFile()->assertActionHidden(TestAction::make('csv'));
    }

    // --- the Bank Payment File ------------------------------------------------

    public function test_the_payment_file_filters_by_month(): void
    {
        // It used to use the month only to generate salary payments and then list
        // every unreleased payment there had ever been, whatever month was chosen.
        $employee = $this->employee('july@test.local');
        $this->payslip($employee, Payslip::REVIEW_ACCEPTED, 'July');
        $this->payslip($employee, Payslip::REVIEW_ACCEPTED, 'August');

        $july = Livewire::test(BankPaymentFile::class)
            ->fillForm(['fiscal_year_id' => $this->fiscalYear->id, 'month' => 'July', 'type' => 'salary'])
            ->instance()
            ->getRows();

        $this->assertCount(1, $july);
        $this->assertSame('July', $july->first()->payslip->month);
    }

    public function test_a_non_salary_payment_is_filtered_by_its_value_date(): void
    {
        // It has no payslip, so the month can only mean the date it is paid on.
        $beneficiary = Beneficiary::create([
            'name' => 'Skyline Internet',
            'account_no' => '5544332211',
            'payment_type' => 'IBFT',
        ]);

        $year = $this->yearOfJuly();

        foreach (["{$year}-07-15" => 'July bill', "{$year}-08-15" => 'August bill'] as $date => $details) {
            Payment::create([
                'payable_type' => Beneficiary::class,
                'payable_id' => $beneficiary->id,
                'transaction_type_id' => TransactionType::query()->value('id'),
                'amount' => 5000,
                'details' => $details,
                'value_date' => $date,
                'status' => Payment::STATUS_DRAFT,
            ]);
        }

        $rows = Livewire::test(BankPaymentFile::class)
            ->fillForm(['fiscal_year_id' => $this->fiscalYear->id, 'month' => 'July', 'type' => null])
            ->instance()
            ->getRows();

        $this->assertTrue($rows->contains(fn (Payment $p): bool => $p->details === 'July bill'));
        $this->assertFalse($rows->contains(fn (Payment $p): bool => $p->details === 'August bill'));
    }

    // --- the service ----------------------------------------------------------

    public function test_release_skips_anything_not_releasable(): void
    {
        $accepted = $this->payslip($this->employee('ok@test.local'), Payslip::REVIEW_ACCEPTED);
        $pending = $this->payslip($this->employee('no@test.local'), Payslip::REVIEW_PENDING);

        app(PaymentService::class)->generateSalaryPayments('July', $this->fiscalYear);

        $payments = Payment::whereIn('payslip_id', [$accepted->id, $pending->id])->get();

        $released = app(PaymentService::class)->release($payments, 'SAL-TEST-B1');

        $this->assertCount(1, $released);
        $this->assertSame(Payment::STATUS_DRAFT, Payment::where('payslip_id', $pending->id)->value('status'));
    }

    /**
     * The bug behind the reported symptom, and the reason a released salary kept
     * coming back: generateSalaryPayments() runs on every view of either bank-file
     * page, and its updateOrCreate carried 'status' => draft — so the next page
     * load un-exported everything that had just been sent.
     */
    public function test_opening_the_page_again_does_not_un_release_anything(): void
    {
        $payslip = $this->payslip($this->employee('sticks@test.local'), Payslip::REVIEW_ACCEPTED);

        $this->salaryFile()->callAction(TestAction::make('csv'));

        $reference = Payment::where('payslip_id', $payslip->id)->value('batch_reference');
        $this->assertNotNull($reference);

        // Every one of these re-runs the generator.
        $this->salaryFile()->instance()->getPayments();
        Livewire::test(BankPaymentFile::class)
            ->fillForm(['fiscal_year_id' => $this->fiscalYear->id, 'month' => 'July'])
            ->instance()
            ->getRows();

        $payment = Payment::where('payslip_id', $payslip->id)->firstOrFail();

        $this->assertSame(Payment::STATUS_EXPORTED, $payment->status, 'still released');
        $this->assertSame($reference, $payment->batch_reference, 'still in the same batch');
    }

    /**
     * A released payment records what was actually sent, so the generator leaves
     * its figures alone — restating the amount afterwards would make the row
     * disagree with the file the bank received.
     */
    public function test_a_released_payment_is_not_restated_when_the_payslip_changes(): void
    {
        $payslip = $this->payslip($this->employee('frozen@test.local'), Payslip::REVIEW_ACCEPTED);

        $this->salaryFile()->callAction(TestAction::make('csv'));

        $sent = (float) Payment::where('payslip_id', $payslip->id)->value('amount');

        \Illuminate\Support\Facades\DB::table('payslips')
            ->where('id', $payslip->id)
            ->update(['net_salary' => $sent + 5000]);

        $this->salaryFile()->instance()->getPayments();

        $this->assertSame(
            $sent,
            (float) Payment::where('payslip_id', $payslip->id)->value('amount'),
            'the released row still says what went to the bank'
        );
    }

    // --- voiding a batch ------------------------------------------------------

    public function test_voiding_a_batch_puts_its_payments_back_in_the_pool(): void
    {
        // The case it exists for: the bank rejects the file, or the wrong month
        // goes out. Before this, the only way back was a database edit.
        $payslip = $this->payslip($this->employee('void-me@test.local'), Payslip::REVIEW_ACCEPTED);

        $this->salaryFile()->callAction(TestAction::make('csv'));

        $reference = Payment::where('payslip_id', $payslip->id)->value('batch_reference');

        $restored = app(PaymentService::class)->voidBatch($reference);

        $this->assertCount(1, $restored);

        $payment = Payment::where('payslip_id', $payslip->id)->firstOrFail();

        $this->assertSame(Payment::STATUS_DRAFT, $payment->status);
        $this->assertNull($payment->batch_reference);
        $this->assertNull($payment->released_at);

        // And it is offered to the next batch again.
        $releasable = collect($this->salaryFile()->instance()->getReleasablePayments());
        $this->assertSame([$payslip->id], $releasable->pluck('payslip_id')->all());
    }

    public function test_an_approved_payment_goes_back_to_approved_not_draft(): void
    {
        // Reconstructed from journal_entry_id, which approve() is the only thing
        // that sets — so this is exact rather than a guess.
        $beneficiary = Beneficiary::create([
            'name' => 'Skyline Internet',
            'account_no' => '5544332211',
            'payment_type' => 'IBFT',
        ]);

        $payment = Payment::create([
            'payable_type' => Beneficiary::class,
            'payable_id' => $beneficiary->id,
            'transaction_type_id' => TransactionType::byCode('utilities')?->id ?? TransactionType::query()->value('id'),
            'amount' => 5000,
            'details' => 'Internet',
            'value_date' => now()->toDateString(),
            'status' => Payment::STATUS_DRAFT,
        ]);

        app(PaymentService::class)->approve($payment);
        $this->assertSame(Payment::STATUS_APPROVED, $payment->fresh()->status);

        app(PaymentService::class)->release([$payment->fresh()], 'PMT-TEST-B1');
        app(PaymentService::class)->voidBatch('PMT-TEST-B1');

        $this->assertSame(Payment::STATUS_APPROVED, $payment->fresh()->status);
    }

    public function test_a_batch_containing_a_paid_payment_cannot_be_voided(): void
    {
        // Paid means the money moved; putting the row back would queue it to move
        // a second time.
        $payslip = $this->payslip($this->employee('paid@test.local'), Payslip::REVIEW_ACCEPTED);

        $this->salaryFile()->callAction(TestAction::make('csv'));

        $payment = Payment::where('payslip_id', $payslip->id)->firstOrFail();
        $reference = $payment->batch_reference;
        $payment->update(['status' => Payment::STATUS_PAID]);

        try {
            app(PaymentService::class)->voidBatch($reference);
            $this->fail('Expected a partly paid batch to be refused.');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('already marked paid', $e->getMessage());
        }

        $this->assertSame(Payment::STATUS_PAID, $payment->fresh()->status, 'left alone');
        $this->assertSame($reference, $payment->fresh()->batch_reference);
    }

    public function test_voiding_an_unknown_batch_is_refused(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        app(PaymentService::class)->voidBatch('SAL-1999-01-B9');
    }

    public function test_the_void_is_recorded(): void
    {
        $this->payslip($this->employee('logged@test.local'), Payslip::REVIEW_ACCEPTED);
        $this->salaryFile()->callAction(TestAction::make('csv'));

        $reference = Payment::whereNotNull('batch_reference')->value('batch_reference');
        app(PaymentService::class)->voidBatch($reference);

        $this->assertDatabaseHas('activity_log', [
            'log_name' => 'Payment',
            'description' => "Voided payment batch {$reference} — 1 payment(s) returned to the next batch",
        ]);
    }

    public function test_only_released_batches_are_offered_for_voiding(): void
    {
        $this->payslip($this->employee('offered@test.local'), Payslip::REVIEW_ACCEPTED);

        $this->assertTrue(app(PaymentService::class)->voidableBatches()->isEmpty(), 'nothing released yet');

        $this->salaryFile()->callAction(TestAction::make('csv'));

        $batches = app(PaymentService::class)->voidableBatches();

        $this->assertCount(1, $batches);
        $this->assertStringContainsString('1 payment(s)', $batches->first());

        app(PaymentService::class)->voidBatch($batches->keys()->first());

        $this->assertTrue(app(PaymentService::class)->voidableBatches()->isEmpty(), 'and gone once voided');
    }

    public function test_a_voided_batch_number_is_not_reused(): void
    {
        // The reference is numbered from what is stored, so voiding B1 frees the
        // number — the next release is B1 again, and no two live batches collide.
        $first = $this->payslip($this->employee('n1@test.local'), Payslip::REVIEW_ACCEPTED);
        $this->salaryFile()->callAction(TestAction::make('csv'));

        $reference = Payment::where('payslip_id', $first->id)->value('batch_reference');
        app(PaymentService::class)->voidBatch($reference);

        $this->salaryFile()->callAction(TestAction::make('csv'));

        $this->assertSame($reference, Payment::where('payslip_id', $first->id)->value('batch_reference'));
    }

    private function yearOfJuly(): string
    {
        return app(\App\Services\SalaryBankExportService::class)->yearForMonth('July', $this->fiscalYear);
    }
}
