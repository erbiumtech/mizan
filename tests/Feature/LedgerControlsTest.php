<?php

namespace Tests\Feature;

use App\Health\LedgerControlsCheck;
use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\JournalEntry;
use App\Modules\Accounting\Services\JournalEntryService;
use App\Modules\Core\Models\CompanyModule;
use App\Modules\Invoicing\Filament\Pages\RecordReceipt;
use App\Modules\Invoicing\Models\Contact;
use App\Modules\Invoicing\Models\Invoice;
use App\Modules\Invoicing\Models\InvoiceLine;
use App\Modules\Invoicing\Services\ControlReconciliation;
use App\Modules\Invoicing\Services\InvoiceService;
use App\Support\LedgerControls;
use Illuminate\Support\Carbon;
use InvalidArgumentException;
use Livewire\Livewire;
use Spatie\Health\Enums\Status;
use Tests\AccountingTestCase;
use Tests\Concerns\InteractsWithTenant;

/**
 * The two figures that were allowed to disagree — `docs/erpnext-gap-plan.md` Phase 2.
 *
 * Ageing iterates `Invoice` rows and the trial balance reads the ledger, so a journal entry posted straight
 * at Receivables moves one and not the other. ERPNext does not have the problem because its journal *line*
 * carries a party; rebuilding ageing that way was refused, because it means a party on every line for a
 * report that already works.
 *
 * **So the defect being fixed is the silence, not the divergence.** A write-off against a control account is
 * a legitimate thing to post. What was wrong is that afterwards two reports stated different figures and
 * nothing said so. These tests are about the saying.
 *
 * The second half is the receipt screen, and its interesting assertion is a refusal: the allocations must
 * add up, because there is nowhere in this schema to hold money that is not against an invoice.
 */
class LedgerControlsTest extends AccountingTestCase
{
    use InteractsWithTenant;

    private const AS_OF = '2027-02-19';

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(self::AS_OF.' 09:00:00');

        $this->actingAs($this->makeUser('Administrator', 'controls@test.local'));
        $this->setCurrentTenant();

        foreach (array_keys(config('modules', [])) as $module) {
            CompanyModule::updateOrCreate(
                ['company_id' => $this->tenant->getKey(), 'module' => $module],
                ['licensed' => true, 'enabled' => true],
            );
        }

        modules()->flush();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    // ───────────────────────────────────────────────── the two figures ──

    /** With only invoices posted, the ledger and the documents say the same thing. */
    public function test_the_control_account_agrees_with_the_invoices(): void
    {
        $this->issuedInvoice('INV-1', 30_000);
        $this->issuedInvoice('INV-2', 12_500);

        $receivables = collect(LedgerControls::compare())->firstWhere('label', 'Receivables');

        $this->assertSame(42_500.0, $receivables['ledger']);
        $this->assertSame(42_500.0, $receivables['documents']);
        $this->assertSame(0.0, $receivables['difference']);
    }

    /** A settlement moves both sides together, which is the property that makes the check meaningful. */
    public function test_a_settlement_moves_both_sides(): void
    {
        $invoice = $this->issuedInvoice('INV-3', 20_000);

        app(InvoiceService::class)->recordPayment($invoice, 8_000, '2027-02-10');

        $receivables = collect(LedgerControls::compare())->firstWhere('label', 'Receivables');

        $this->assertSame(12_000.0, $receivables['ledger']);
        $this->assertSame(12_000.0, $receivables['documents']);
    }

    /**
     * The case the whole phase exists for: a journal entry straight at the control account.
     *
     * A write-off, a contra, an opening balance — all legitimate, all invisible to Aged Receivables, and
     * until now all silent. The figures diverge by exactly the entry.
     */
    public function test_a_journal_entry_against_receivables_diverges_and_is_reported(): void
    {
        $this->issuedInvoice('INV-4', 15_000);

        // Writing 5,000 off the customer's balance without touching an invoice.
        $this->manualEntry('5100', ControlReconciliation::RECEIVABLES, 5_000, 'Bad debt written off');

        $receivables = collect(LedgerControls::compare())->firstWhere('label', 'Receivables');

        $this->assertSame(10_000.0, $receivables['ledger'], 'the ledger moved');
        $this->assertSame(15_000.0, $receivables['documents'], 'the invoices did not');
        $this->assertSame(-5_000.0, $receivables['difference']);

        // And the check says so rather than leaving it to somebody to notice.
        $result = (new LedgerControlsCheck)->run();

        $this->assertSame(Status::failed(), $result->status);
        $this->assertStringContainsString('receivables', mb_strtolower($result->notificationMessage));
        $this->assertStringContainsString('5,000', $result->notificationMessage);
    }

    /** Agreement is reported as agreement, with the companies counted. */
    public function test_the_check_passes_when_the_books_agree(): void
    {
        $this->issuedInvoice('INV-5', 9_000);

        $result = (new LedgerControlsCheck)->run();

        $this->assertSame(Status::ok(), $result->status);
        $this->assertStringContainsString('agree', $result->notificationMessage);
    }

    /**
     * Rounding is not a divergence.
     *
     * Both sides round to two places, so a company with many invoices differs by pennies through arithmetic
     * alone. A check that fires on that gets muted, and a muted check is worse than no check.
     */
    public function test_a_rounding_difference_is_within_tolerance(): void
    {
        $this->issuedInvoice('INV-6', 4_000);
        $this->manualEntry('5100', ControlReconciliation::RECEIVABLES, 0.40, 'Rounding');

        $this->assertSame(Status::ok(), (new LedgerControlsCheck)->run()->status);

        // And a tighter tolerance does see it, which is what proves the tolerance is doing the work rather
        // than the comparison being blind.
        $this->assertSame(Status::failed(), (new LedgerControlsCheck)->tolerance(0.01)->run()->status);
    }

    /** Payables is the same fact on the other side of the sheet. */
    public function test_payables_is_checked_too(): void
    {
        $this->assertSame(['Receivables', 'Payables'], LedgerControls::labels());
    }

    // ─────────────────────────────────────────────── the batch receipt ──

    /** One transfer settles three invoices, and every one of them goes through the tested path. */
    public function test_one_receipt_settles_several_invoices(): void
    {
        $contact = Contact::create(['name' => 'Acme', 'kind' => Contact::KIND_CUSTOMER]);

        $first = $this->issuedInvoice('INV-7', 5_000, $contact);
        $second = $this->issuedInvoice('INV-8', 3_000, $contact);
        $third = $this->issuedInvoice('INV-9', 2_000, $contact);

        app(InvoiceService::class)->recordBatchReceipt(10_000, '2027-02-15', [
            $first->getKey() => 5_000,
            $second->getKey() => 3_000,
            $third->getKey() => 2_000,
        ], 'FT-99881');

        foreach ([$first, $second, $third] as $invoice) {
            $this->assertSame(Invoice::STATUS_PAID, $invoice->refresh()->status);
        }

        // The reference is what ties the three settlements back to the one transfer that paid them. In the
        // memo rather than a column, because a customer receipt writes no row of its own — the ceiling the
        // plan names.
        $this->assertSame(
            3,
            JournalEntry::query()->where('memo', 'like', '%FT-99881%')->count(),
        );

        // And the control still agrees, which is the point of doing it through `recordPayment()`.
        $receivables = collect(LedgerControls::compare())->firstWhere('label', 'Receivables');
        $this->assertSame($receivables['ledger'], $receivables['documents']);
    }

    /**
     * A receipt that does not add up is refused, and the message says why.
     *
     * Both directions, because both have the same cause: the difference has nowhere to go. Under-allocating
     * leaves money on account and over-allocating invents it, and this schema models neither.
     */
    public function test_a_receipt_that_does_not_add_up_is_refused(): void
    {
        $invoice = $this->issuedInvoice('INV-10', 5_000);

        foreach ([6_000.0, 4_000.0] as $received) {
            try {
                app(InvoiceService::class)->recordBatchReceipt($received, '2027-02-15', [
                    $invoice->getKey() => 5_000,
                ]);

                $this->fail("a receipt of {$received} against 5,000 of invoices was accepted");
            } catch (InvalidArgumentException $e) {
                $this->assertStringContainsString('nowhere to hold money', $e->getMessage());
            }
        }

        $this->assertSame(Invoice::STATUS_ISSUED, $invoice->refresh()->status, 'nothing was settled');
    }

    /** All of it or none of it: a failure part-way leaves no invoice settled. */
    public function test_a_batch_that_fails_settles_nothing(): void
    {
        $contact = Contact::create(['name' => 'Globex', 'kind' => Contact::KIND_CUSTOMER]);

        $good = $this->issuedInvoice('INV-11', 1_000, $contact);
        $paid = $this->issuedInvoice('INV-12', 1_000, $contact);

        // Settle the second one first, so the batch below hits a closed invoice half way through.
        app(InvoiceService::class)->recordPayment($paid, 1_000, '2027-02-12');

        try {
            app(InvoiceService::class)->recordBatchReceipt(2_000, '2027-02-15', [
                $good->getKey() => 1_000,
                $paid->getKey() => 1_000,
            ]);

            $this->fail('settling a paid invoice was accepted');
        } catch (InvalidArgumentException) {
            // expected
        }

        $this->assertSame(
            Invoice::STATUS_ISSUED,
            $good->refresh()->status,
            'the first invoice was settled although the batch failed — half a receipt is worse than none',
        );
    }

    /** The screen fills the boxes oldest first, and says so when the money runs out. */
    public function test_the_screen_allocates_oldest_first(): void
    {
        $contact = Contact::create(['name' => 'Initech', 'kind' => Contact::KIND_CUSTOMER]);

        $older = $this->issuedInvoice('INV-13', 4_000, $contact, '2027-01-05');
        $newer = $this->issuedInvoice('INV-14', 4_000, $contact, '2027-02-05');

        Livewire::test(RecordReceipt::class)
            ->set('contactId', $contact->getKey())
            ->set('amount', '5000')
            ->call('allocateOldestFirst')
            ->assertSet("allocations.{$older->getKey()}", '4000')
            ->assertSet("allocations.{$newer->getKey()}", '1000');
    }

    /** And it records through the service, leaving the invoices settled. */
    public function test_the_screen_records_the_receipt(): void
    {
        $contact = Contact::create(['name' => 'Umbrella', 'kind' => Contact::KIND_CUSTOMER]);
        $invoice = $this->issuedInvoice('INV-15', 2_500, $contact);

        Livewire::test(RecordReceipt::class)
            ->set('contactId', $contact->getKey())
            ->set('amount', '2500')
            ->set('reference', 'FT-1234')
            ->call('allocateOldestFirst')
            ->call('record')
            ->assertNotified();

        $this->assertSame(Invoice::STATUS_PAID, $invoice->refresh()->status);
    }

    // ───────────────────────────────────────────────────────── fixtures ──

    private function issuedInvoice(string $number, float $amount, ?Contact $contact = null, string $date = '2027-02-01'): Invoice
    {
        $contact ??= Contact::create(['name' => 'Customer '.$number, 'kind' => Contact::KIND_CUSTOMER]);

        $invoice = Invoice::create([
            'invoice_number' => $number,
            'kind' => Invoice::KIND_SALE,
            'status' => Invoice::STATUS_DRAFT,
            'contact_id' => $contact->getKey(),
            'invoice_date' => $date,
            'due_date' => Carbon::parse($date)->addMonth()->toDateString(),
            'subtotal' => $amount,
            'total' => $amount,
        ]);

        InvoiceLine::create([
            'invoice_id' => $invoice->id,
            'description' => 'Consultancy',
            'quantity' => 1,
            'unit_price' => $amount,
            'line_total' => $amount,
        ]);

        return app(InvoiceService::class)->issue($invoice->refresh());
    }

    private function manualEntry(string $debit, string $credit, float $amount, string $memo): JournalEntry
    {
        $entries = app(JournalEntryService::class);

        $entry = $entries->create([
            'entry_date' => '2027-02-05',
            'entry_type' => 'general',
            'memo' => $memo,
        ], [
            ['account_id' => Account::where('code', $debit)->firstOrFail()->id, 'debit_amount' => $amount],
            ['account_id' => Account::where('code', $credit)->firstOrFail()->id, 'credit_amount' => $amount],
        ]);

        $entry->update(['status' => JournalEntry::STATUS_APPROVED, 'approved_at' => now()]);

        return $entries->post($entry);
    }
}
