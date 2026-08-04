<?php

namespace Tests\Feature;

use App\Modules\Accounting\Models\Account;
use App\Modules\Invoicing\Models\Contact;
use App\Modules\Invoicing\Models\Invoice;
use App\Modules\Invoicing\Models\InvoiceEvent;
use App\Modules\Invoicing\Services\InvoiceService;
use Tests\AccountingTestCase;
use Tests\Concerns\InteractsWithTenant;

/**
 * A document's life, as opposed to its column changes.
 *
 * The activity log records what changed from what to what. "When was this issued,
 * when did we last print it, when did they pay, and how much is still out?" is the
 * first conversation about a late invoice, and none of it was answerable from a diff
 * of columns.
 *
 * What is recorded is only what the application witnesses. There is no "viewed": it
 * needs a client portal or a tracked email and this has neither by decision, so a
 * column for it would never fill.
 */
class InvoiceHistoryTest extends AccountingTestCase
{
    use InteractsWithTenant;

    private Contact $client;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs($this->makeUser('Administrator', 'history@test.local'));
        $this->setCurrentTenant();

        $this->client = Contact::create([
            'name' => 'Erbium AG',
            'kind' => Contact::KIND_CUSTOMER,
            'is_active' => true,
        ]);
    }

    private function invoice(float $amount = 10000): Invoice
    {
        $invoice = Invoice::create([
            'kind' => Invoice::KIND_SALE,
            'contact_id' => $this->client->id,
            'invoice_date' => '2026-08-01',
            'fiscal_year_id' => $this->fiscalYear->id,
            'subtotal' => $amount,
            'tax_amount' => 0,
            'total' => $amount,
        ]);

        $invoice->lines()->create([
            'description' => 'Services',
            'quantity' => 1,
            'unit_price' => $amount,
            'line_total' => $amount,
            'account_id' => Account::where('code', '4100')->firstOrFail()->id,
        ]);

        return $invoice->refresh();
    }

    /**
     * Oldest first, for reading a life in order. reorder(), not orderBy(): the
     * relation is already newest-first and a second ordering would just be appended
     * behind the first.
     */
    private function events(Invoice $invoice): array
    {
        return $invoice->events()->reorder('id')->pluck('event')->all();
    }

    public function test_raising_an_invoice_is_the_first_event(): void
    {
        $invoice = $this->invoice();

        $this->assertSame([InvoiceEvent::CREATED], $this->events($invoice));
        $this->assertStringContainsString('draft', $invoice->events()->first()->description);
    }

    public function test_a_bill_says_bill_rather_than_invoice(): void
    {
        $bill = Invoice::create([
            'kind' => Invoice::KIND_PURCHASE,
            'contact_id' => $this->client->id,
            'invoice_date' => '2026-08-01',
            'subtotal' => 100, 'tax_amount' => 0, 'total' => 100,
        ]);

        $this->assertStringContainsString('Bill raised', $bill->events()->first()->description);
    }

    public function test_issuing_records_the_entry_it_posted(): void
    {
        $invoice = $this->invoice();

        app(InvoiceService::class)->issue($invoice);

        $issued = $invoice->events()->where('event', InvoiceEvent::ISSUED)->firstOrFail();

        $this->assertSame(10000.0, (float) $issued->amount);
        $this->assertStringContainsString('JE-', $issued->description, 'the journal entry it became');
    }

    public function test_a_part_payment_says_what_is_still_outstanding(): void
    {
        // The number the conversation is actually about.
        $invoice = $this->invoice();
        $invoices = app(InvoiceService::class);

        $invoices->issue($invoice);
        $invoices->recordPayment($invoice, 4000, '2026-08-10');

        $payment = $invoice->events()->where('event', InvoiceEvent::PAYMENT)->firstOrFail();

        $this->assertSame(4000.0, (float) $payment->amount);
        $this->assertStringContainsString('6,000.00 still outstanding', $payment->description);
    }

    public function test_the_last_payment_says_paid_in_full(): void
    {
        $invoice = $this->invoice();
        $invoices = app(InvoiceService::class);

        $invoices->issue($invoice);
        $invoices->recordPayment($invoice, 4000, '2026-08-10');
        $invoices->recordPayment($invoice, 6000, '2026-08-20');

        $this->assertStringContainsString('Paid in full', $invoice->events()->first()->description);
        $this->assertSame(
            [InvoiceEvent::CREATED, InvoiceEvent::ISSUED, InvoiceEvent::PAYMENT, InvoiceEvent::PAYMENT],
            $this->events($invoice),
        );
    }

    public function test_voiding_is_recorded(): void
    {
        $invoice = $this->invoice();
        $invoices = app(InvoiceService::class);

        $invoices->issue($invoice);
        $invoices->void($invoice, auth()->user());

        $this->assertContains(InvoiceEvent::VOIDED, $this->events($invoice));
    }

    /**
     * Producing the PDF is the closest thing to "sent" this application witnesses,
     * and it is recorded as what it is rather than as a claim about the client.
     */
    public function test_producing_the_pdf_is_recorded_as_printed(): void
    {
        $invoice = $this->invoice();
        app(InvoiceService::class)->issue($invoice);

        $this->get(route('invoice.pdf', [
            'company' => $this->tenant->slug,
            'invoice' => $invoice->id,
        ]))->assertOk();

        $printed = $invoice->events()->where('event', InvoiceEvent::PRINTED)->firstOrFail();

        $this->assertSame('PDF produced', $printed->description);
        $this->assertNull($printed->amount);
    }

    public function test_an_event_names_whoever_caused_it(): void
    {
        $invoice = $this->invoice();

        app(InvoiceService::class)->issue($invoice);

        $this->assertSame(
            auth()->id(),
            $invoice->events()->where('event', InvoiceEvent::ISSUED)->first()->caused_by,
        );
    }

    public function test_the_history_reads_newest_first(): void
    {
        $invoice = $this->invoice();
        $invoices = app(InvoiceService::class);

        $invoices->issue($invoice);
        $invoices->recordPayment($invoice, 10000, '2026-08-10');

        $this->assertSame(InvoiceEvent::PAYMENT, $invoice->events()->first()->event);
    }

    public function test_deleting_an_invoice_takes_its_history_with_it(): void
    {
        // The history of a document that does not exist is not history.
        $invoice = $this->invoice();
        $id = $invoice->id;

        $invoice->delete();

        $this->assertSame(0, InvoiceEvent::where('invoice_id', $id)->count());
    }
}
