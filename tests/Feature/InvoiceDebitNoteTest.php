<?php

namespace Tests\Feature;

use App\Modules\Accounting\Models\Account;
use App\Modules\Invoicing\Models\Contact;
use App\Modules\Invoicing\Models\Invoice;
use App\Modules\Invoicing\Models\InvoiceEvent;
use App\Modules\Invoicing\Models\TaxRate;
use App\Modules\Invoicing\Services\ControlReconciliation;
use App\Modules\Invoicing\Services\InvoiceService;
use App\Support\LedgerControls;
use Illuminate\Support\Carbon;
use InvalidArgumentException;
use Tests\AccountingTestCase;

/**
 * Debit notes — `docs/erpnext-gap-plan.md` Phase 5, and the purchase mirror of `InvoiceCreditNoteTest`.
 *
 * A supplier bill could be voided while nothing had happened to it and corrected in no other way. The
 * application's own refusal said a bill "is corrected by the supplier, who issues the credit note to you",
 * which is true of the *tax* and was never true of the books: what the company owed still had to come down,
 * and the only route was a journal entry typed at Accounts Payable — which is exactly the posting Phase 2's
 * control check exists to complain about.
 *
 * Three of these are the ones worth reading:
 *
 *  - **`test_a_full_debit_note_nets_the_bill_to_zero`** — the property that makes it a correction rather
 *    than a second transaction about the same money.
 *  - **`test_the_payables_control_still_agrees_after_a_debit_note`** — the reason no new report was needed.
 *    Ageing, the payables total and the health check all read one query, so teaching that query about the
 *    new kind was the whole integration.
 *  - **`test_a_debit_note_moves_no_stock`** — deliberate, and the argument is in `issue()`: reversing the
 *    quantity would consume lots at a cost these goods never came in at.
 */
class InvoiceDebitNoteTest extends AccountingTestCase
{
    private InvoiceService $service;

    private Contact $supplier;

    protected function setUp(): void
    {
        parent::setUp();

        // Frozen for the reason InvoiceCreditNoteTest documents: fixtures built from `now()` and asserted
        // against `now()` must land on the same day, and the full suite is long enough to straddle midnight.
        Carbon::setTestNow('2026-08-13 10:00:00');

        $this->service = app(InvoiceService::class);
        $this->supplier = Contact::create(['name' => 'A supplier', 'kind' => 'supplier']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    // ─────────────────────────────────────────────────── the reversal ──

    /**
     * A full debit note nets the bill to zero — the payable, the cost and the input tax.
     *
     * Asserted as three movements rather than one total, because a correction that nets overall while
     * putting the tax on the wrong side is exactly the failure that survives a "does it balance" check.
     */
    public function test_a_full_debit_note_nets_the_bill_to_zero(): void
    {
        $rate = TaxRate::create(['name' => 'GST 18', 'code' => 'GST18', 'rate' => 18, 'is_active' => true]);
        $bill = $this->issuedBill([[1_000.0, $rate->id]]);

        $note = $this->service->debitNote($bill, 'Goods returned — supplier CN-77');
        $this->service->issue($note);

        foreach (['2400', '5700', '2150'] as $code) {
            $this->assertSame(
                0.0,
                round($this->movement($bill, $code) + $this->movement($note->refresh(), $code), 2),
                "Account {$code} does not net to zero across the bill and its debit note.",
            );
        }

        // And each leg is the reverse rather than merely equal in total.
        $this->assertSame(1_180.0, $this->movement($note, '2400'));
        $this->assertSame(-1_000.0, $this->movement($note, '5700'));
        $this->assertSame(-180.0, $this->movement($note, '2150'));
    }

    /** Its own number series, so a supplier asking which document reduced their bill gets an answer. */
    public function test_a_debit_note_gets_its_own_number_series(): void
    {
        $note = $this->service->debitNote($this->issuedBill(), 'Billed twice');

        $this->assertStringStartsWith('DN-2026-', $note->invoice_number);
        $this->assertSame(Invoice::KIND_DEBIT_NOTE, $note->kind);
        $this->assertTrue($note->isDebitNote());
        $this->assertTrue($note->isAdjustment());
    }

    /** A draft, and nothing posted — the same second act a credit note and an invoice both require. */
    public function test_a_debit_note_is_raised_as_a_draft(): void
    {
        $note = $this->service->debitNote($this->issuedBill(), 'Quantity overstated');

        $this->assertSame(Invoice::STATUS_DRAFT, $note->status);
        $this->assertNull($note->journal_entry_id);
        $this->assertSame(0.0, $this->controlBalanceChangeAfterRaising());
    }

    /** The bill's own history says it was corrected, which is where somebody reading it will look. */
    public function test_the_bill_records_that_it_was_debited(): void
    {
        $bill = $this->issuedBill();

        $note = $this->service->debitNote($bill, 'Goods returned');

        $event = $bill->events()->where('event', InvoiceEvent::DEBITED)->firstOrFail();

        $this->assertStringContainsString($note->invoice_number, $event->description);
        $this->assertStringContainsString('Goods returned', $event->description);
        $this->assertSame('1000.00', (string) $event->amount);
    }

    /** Inherited tax, never re-derived — the whole value of a correction is that it equals what it corrects. */
    public function test_the_note_inherits_the_bills_tax_rather_than_todays_rate(): void
    {
        $rate = TaxRate::create(['name' => 'GST 18', 'code' => 'GST18', 'rate' => 18, 'is_active' => true]);
        $bill = $this->issuedBill([[1_000.0, $rate->id]]);

        // The rate is edited between the bill and the note, which is the case that breaks a re-derivation.
        $rate->update(['rate' => 25]);

        $note = $this->service->debitNote($bill, 'Returned');
        $this->service->issue($note);

        $this->assertSame('180.00', (string) $note->refresh()->tax_amount);
        $this->assertSame(-180.0, $this->movement($note, '2150'));
    }

    // ──────────────────────────────────────────────────── the guards ──

    /** A customer invoice is not debited, and the refusal names the action that does apply. */
    public function test_a_sale_cannot_be_debited(): void
    {
        $invoice = Invoice::create([
            'kind' => Invoice::KIND_SALE,
            'contact_id' => $this->supplier->id,
            'invoice_date' => '2026-08-10',
            'subtotal' => 100,
            'total' => 100,
        ]);
        $invoice->lines()->create(['description' => 'x', 'quantity' => 1, 'unit_price' => 100, 'line_total' => 100]);
        $this->service->issue($invoice);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('corrected with a credit note');

        $this->service->debitNote($invoice->refresh(), 'Wrong direction');
    }

    /** And the reverse refusal now points at the Debit action rather than at the supplier. */
    public function test_crediting_a_bill_points_at_the_debit_note(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('corrected with a debit note');

        $this->service->creditNote($this->issuedBill(), 'Wrong direction');
    }

    /** A draft bill has posted nothing, so there is nothing to reverse. */
    public function test_a_draft_bill_cannot_be_debited(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('still a draft');

        $this->service->debitNote($this->bill(), 'Too early');
    }

    /** A reason is required: it is what the supplier is told, and what the figures cannot show. */
    public function test_a_debit_note_needs_a_reason(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('needs a reason');

        $this->service->debitNote($this->issuedBill(), '   ');
    }

    /**
     * Two partial notes cannot together exceed the bill.
     *
     * The ceiling comes through `creditableAmount()` — the same relation and the same guard the credit note
     * uses, which is what reusing `credits_invoice_id` bought.
     */
    public function test_two_partial_notes_cannot_exceed_the_bill(): void
    {
        $bill = $this->issuedBill([[1_000.0]]);

        $this->service->debitNote($bill, 'Half returned', [
            ['description' => 'Half', 'quantity' => 1, 'unit_price' => 600, 'account_id' => $this->accountId('5700')],
        ]);

        $this->assertSame(400.0, $bill->refresh()->creditableAmount());

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('only 400 of bill');

        $this->service->debitNote($bill, 'And the rest', [
            ['description' => 'Rest', 'quantity' => 1, 'unit_price' => 500, 'account_id' => $this->accountId('5700')],
        ]);
    }

    /** Nobody pays one. It is settled by paying the supplier less. */
    public function test_a_debit_note_is_not_paid(): void
    {
        $note = $this->service->debitNote($this->issuedBill(), 'Returned');
        $this->service->issue($note);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('is not paid');

        $this->service->recordPayment($note->refresh(), 100, '2026-08-14');
    }

    // ──────────────────────────────────────────── what reads it after ──

    /**
     * Payables ageing subtracts it, and the control check therefore still agrees.
     *
     * This is the integration: `aging()` is the one query behind Aged Payables, `outstandingPayables()` and
     * `LedgerControls`' Payables pair. A debit note left out of it would post to the ledger and be invisible
     * to all three — the exact divergence Phase 2 built the check for.
     */
    public function test_the_payables_control_still_agrees_after_a_debit_note(): void
    {
        ControlReconciliation::register();

        $bill = $this->issuedBill([[1_000.0]]);
        $note = $this->service->debitNote($bill, 'Returned');
        $this->service->issue($note);

        $payables = collect(LedgerControls::compare())->firstWhere('label', 'Payables');

        $this->assertSame(0.0, $payables['ledger']);
        $this->assertSame(0.0, $payables['documents']);
        $this->assertSame(0.0, $payables['difference']);
    }

    /** A partial note leaves exactly what is still owed, on both sides of the comparison. */
    public function test_a_partial_debit_note_leaves_what_is_still_owed(): void
    {
        ControlReconciliation::register();

        $bill = $this->issuedBill([[1_000.0]]);
        $note = $this->service->debitNote($bill, 'Part returned', [
            ['description' => 'Part', 'quantity' => 1, 'unit_price' => 250, 'account_id' => $this->accountId('5700')],
        ]);
        $this->service->issue($note);

        $payables = collect(LedgerControls::compare())->firstWhere('label', 'Payables');

        $this->assertSame(750.0, $payables['ledger']);
        $this->assertSame(750.0, $payables['documents']);
    }

    /**
     * It moves no stock, and the value is reversed anyway.
     *
     * Deliberate on both counts — see `debitNoteEntryLines()`. Reversing the quantity would consume lots at
     * the valuation engine's current cost, which after any other receipt is not what these goods cost.
     */
    public function test_a_debit_note_moves_no_stock(): void
    {
        $product = \App\Modules\Inventory\Models\Product::create([
            'sku' => 'DN-1',
            'name' => 'A part',
            'unit' => 'each',
            'is_active' => true,
        ]);

        $bill = $this->bill([[500.0]]);
        $bill->lines()->delete();
        $bill->lines()->create([
            'product_id' => $product->id,
            'description' => 'A part',
            'quantity' => 5,
            'unit_price' => 100,
            'line_total' => 500,
        ]);
        $bill->forceFill(['subtotal' => 500, 'tax_amount' => 0, 'total' => 500])->save();
        $this->service->issue($bill);

        $received = $product->movements()->count();

        $note = $this->service->debitNote($bill->refresh(), 'Returned to supplier');
        $this->service->issue($note);

        $this->assertSame($received, $product->refresh()->movements()->count());
        // The value came back off inventory even though the quantity did not.
        $this->assertSame(-500.0, $this->movement($note->refresh(), '1300'));
    }

    // ───────────────────────────────────────────────────── fixtures ──

    /**
     * @param  array<int, array{0: float, 1?: int|null}>  $lines  [amount, tax rate id]
     */
    private function bill(array $lines = [[1_000.0]]): Invoice
    {
        $bill = Invoice::create([
            'kind' => Invoice::KIND_PURCHASE,
            'contact_id' => $this->supplier->id,
            'invoice_date' => '2026-08-10',
            'due_date' => '2026-09-09',
        ]);

        foreach ($lines as $index => $line) {
            $bill->lines()->create([
                'description' => 'Materials '.($index + 1),
                'quantity' => 1,
                'unit_price' => $line[0],
                'line_total' => $line[0],
                'tax_rate_id' => $line[1] ?? null,
                // A non-product purchase line needs an account, which is the service's own rule.
                'account_id' => $this->accountId('5700'),
            ]);
        }

        $this->service->applyTaxes($bill);
        $bill->refresh();

        if ((float) $bill->total === 0.0) {
            $sum = round((float) $bill->lines()->sum('line_total'), 2);
            $bill->forceFill(['subtotal' => $sum, 'tax_amount' => 0, 'total' => $sum])->save();
        }

        return $bill->refresh();
    }

    /**
     * @param  array<int, array{0: float, 1?: int|null}>  $lines
     */
    private function issuedBill(array $lines = [[1_000.0]]): Invoice
    {
        $bill = $this->bill($lines);
        $this->service->issue($bill);

        return $bill->refresh();
    }

    /** Net movement on an account across a document's entry: debits less credits. */
    private function movement(Invoice $invoice, string $code): float
    {
        $accountId = $this->accountId($code);
        $lines = $invoice->journalEntry->lines()->where('account_id', $accountId);

        return round((float) $lines->sum('debit_amount') - (float) $lines->sum('credit_amount'), 2);
    }

    /** What raising an unissued note did to the ledger, which must be nothing. */
    private function controlBalanceChangeAfterRaising(): float
    {
        $bill = $this->issuedBill();
        $before = app(ControlReconciliation::class)->controlBalance(ControlReconciliation::PAYABLES);

        $this->service->debitNote($bill, 'Draft only');

        return round(app(ControlReconciliation::class)->controlBalance(ControlReconciliation::PAYABLES) - $before, 2);
    }

    private function accountId(string $code): int
    {
        return Account::where('code', $code)->firstOrFail()->id;
    }
}
