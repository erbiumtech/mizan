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

    private function yearOfJuly(): string
    {
        return app(\App\Services\SalaryBankExportService::class)->yearForMonth('July', $this->fiscalYear);
    }
}
