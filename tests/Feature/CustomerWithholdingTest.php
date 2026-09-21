<?php

namespace Tests\Feature;

use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\JournalEntry;
use App\Modules\Accounting\Models\JournalEntryLine;
use App\Modules\Invoicing\Models\Contact;
use App\Modules\Invoicing\Models\Invoice;
use App\Modules\Invoicing\Models\InvoiceEvent;
use App\Modules\Invoicing\Services\InvoiceService;
use App\Modules\Invoicing\Support\InvoicingReports;
use InvalidArgumentException;
use Tests\AccountingTestCase;
use Tests\Concerns\InteractsWithTenant;

/**
 * A customer pays short and hands over a tax deduction certificate — the customer's side of §153.
 *
 * The invoice is settled in full, the bank shows less, and the difference is an asset the company claims on
 * its own return. Before this it was an invoice that never closed, or a journal per certificate.
 */
class CustomerWithholdingTest extends AccountingTestCase
{
    use InteractsWithTenant;

    private InvoiceService $service;

    private Contact $customer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs($this->makeUser('Administrator', 'receipts@test.local'));
        $this->setCurrentTenant();

        $this->service = app(InvoiceService::class);
        $this->customer = Contact::create(['name' => 'Corporate Client', 'kind' => 'customer']);
    }

    private function issued(float $amount): Invoice
    {
        $invoice = Invoice::create([
            'kind' => Invoice::KIND_SALE,
            'contact_id' => $this->customer->getKey(),
            'invoice_date' => '2026-08-10',
            'due_date' => '2026-09-09',
            'subtotal' => $amount,
            'tax_amount' => 0,
            'total' => $amount,
        ]);

        $invoice->lines()->create([
            'description' => 'Development',
            'quantity' => 1,
            'unit_price' => $amount,
            'line_total' => $amount,
        ]);

        return $this->service->issue($invoice);
    }

    private function balance(string $code): float
    {
        $account = Account::where('code', $code)->firstOrFail();

        $sums = JournalEntryLine::where('account_id', $account->id)
            ->whereHas('journalEntry', fn ($q) => $q->where('is_posted', true))
            ->selectRaw('COALESCE(SUM(debit_amount),0) as d, COALESCE(SUM(credit_amount),0) as c')
            ->first();

        return round((float) $sums->d - (float) $sums->c, 2);
    }

    public function test_the_invoice_is_settled_by_the_money_and_the_certificate_together(): void
    {
        $invoice = $this->issued(100_000);
        $cashBefore = $this->balance('1100');

        $this->service->recordPayment($invoice, 100_000, '2026-08-20', null, null, null, 8_000, 'CPR-2026-0815');

        $invoice->refresh();

        $this->assertSame(Invoice::STATUS_PAID, $invoice->status);
        $this->assertSame(0.0, $invoice->outstanding());

        // The receivable is cleared in full; only 92,000 of it reached the bank.
        $this->assertSame(0.0, $this->balance('1250'));
        $this->assertSame(92_000.0, round($this->balance('1100') - $cashBefore, 2));
        $this->assertSame(8_000.0, $this->balance('1260'), 'the withheld tax is an asset until the return absorbs it');

        // The invoice's own history says why the bank shows less, and names the certificate.
        $event = $invoice->events()->where('event', InvoiceEvent::TAX_WITHHELD)->first();

        $this->assertNotNull($event);
        $this->assertSame(8_000.0, (float) $event->amount);
        $this->assertStringContainsString('CPR-2026-0815', $event->description);
    }

    public function test_an_ordinary_receipt_posts_exactly_what_it_always_did(): void
    {
        $invoice = $this->issued(50_000);

        $this->service->recordPayment($invoice, 50_000, '2026-08-20');

        $this->assertSame(0.0, $this->balance('1260'));
        $this->assertSame(0, $invoice->events()->where('event', InvoiceEvent::TAX_WITHHELD)->count());

        // Two lines: bank and receivable. No zero-amount tax line survives into the entry.
        $receipt = JournalEntry::forSource(Invoice::class, $invoice->getKey())
            ->where('memo', 'like', 'Payment against%')
            ->firstOrFail();

        $this->assertSame(2, $receipt->lines()->count());
    }

    public function test_the_tax_cannot_exceed_the_settlement(): void
    {
        $invoice = $this->issued(10_000);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('cannot have withheld');

        $this->service->recordPayment($invoice, 5_000, '2026-08-20', null, null, null, 6_000);
    }

    public function test_it_is_a_sale_side_fact(): void
    {
        $supplier = Contact::create(['name' => 'Vendor', 'kind' => 'supplier']);
        $expense = Account::where('code', '5900')->firstOrFail();

        $bill = Invoice::create([
            'kind' => Invoice::KIND_PURCHASE,
            'contact_id' => $supplier->getKey(),
            'invoice_date' => '2026-08-10',
            'subtotal' => 10_000,
            'tax_amount' => 0,
            'total' => 10_000,
        ]);
        $bill->lines()->create(['description' => 'Hosting', 'quantity' => 1, 'unit_price' => 10_000, 'line_total' => 10_000, 'account_id' => $expense->id]);
        $this->service->issue($bill);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('recorded on a sale');

        $this->service->recordPayment($bill, 10_000, '2026-08-20', null, null, null, 800);
    }

    /** The return is checked against this list, and the list is the ledger. */
    public function test_the_report_lists_each_certificate_from_the_ledger(): void
    {
        $first = $this->issued(100_000);
        $second = $this->issued(20_000);

        $this->service->recordPayment($first, 100_000, '2026-08-20', null, null, null, 8_000, 'CPR-1');
        $this->service->recordPayment($second, 20_000, '2026-08-25', null, null, null, 1_600);

        $report = app(InvoicingReports::class)->taxWithheldByCustomers('2026-08-31');

        $this->assertCount(2, $report['rows']);
        $this->assertSame(['20 Aug 2026', 'Corporate Client', $first->invoice_number, 'CPR-1', '8,000'], $report['rows'][0]);
        $this->assertSame('no certificate', $report['rows'][1][3]);
        $this->assertSame(9_600.0, $report['tiles'][0]['value']);
    }
}
