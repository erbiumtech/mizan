<?php

namespace Tests\Feature;

use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\ExchangeRate;
use App\Modules\Accounting\Models\JournalEntryLine;
use App\Modules\Accounting\Services\CurrencyRevaluationService;
use App\Modules\Accounting\Services\FinancialReportService;
use App\Modules\Invoicing\Models\Contact;
use App\Modules\Invoicing\Models\Invoice;
use App\Modules\Invoicing\Models\TaxRate;
use App\Modules\Invoicing\Services\InvoiceService;
use Tests\AccountingTestCase;
use Tests\Concerns\InteractsWithTenant;

/**
 * An invoice billed in one currency, posted in another.
 *
 * The invoice's own amounts stay in its currency: that is what the client is billed, what
 * the PDF says and what they will pay. The ledger is in the base currency, translated at
 * the rate stored on the invoice — so that rate is not a convenience, it is the fact tying
 * the document to the books.
 *
 * The difference between what the receivable was booked at and what actually arrived is
 * a gain or a loss the company really made, and it is recognised when the money lands.
 * That is the whole of "realised": no estimate, no reporting date, just the two rates.
 */
class ForeignCurrencyInvoiceTest extends AccountingTestCase
{
    use InteractsWithTenant;

    private Contact $client;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs($this->makeUser('Administrator', 'fx-invoice@test.local'));
        $this->setCurrentTenant();

        $this->seed(\Database\Seeders\CurrencySeeder::class);

        $this->client = Contact::create([
            'name' => 'Erbium AG',
            'kind' => Contact::KIND_CUSTOMER,
            'is_active' => true,
        ]);
    }

    private function invoices(): InvoiceService
    {
        return app(InvoiceService::class);
    }

    private function rate(float $rate, string $on): ExchangeRate
    {
        return ExchangeRate::create(['currency_code' => 'EUR', 'effective_on' => $on, 'rate' => $rate]);
    }

    private function accountId(string $code): int
    {
        return Account::where('code', $code)->firstOrFail()->id;
    }

    /** An invoice for services, in euros unless told otherwise. */
    private function invoice(float $amount, array $attributes = [], ?int $taxRateId = null, float $tax = 0): Invoice
    {
        $invoice = Invoice::create(array_merge([
            'kind' => Invoice::KIND_SALE,
            'currency_code' => 'EUR',
            'contact_id' => $this->client->id,
            'invoice_date' => '2026-07-15',
            'due_date' => '2026-08-14',
            'subtotal' => $amount,
            'tax_amount' => $tax,
            'total' => round($amount + $tax, 2),
        ], $attributes));

        $invoice->lines()->create([
            'description' => 'Development retainer',
            'quantity' => 1,
            'unit_price' => $amount,
            'line_total' => $amount,
            'tax_amount' => $tax,
            'tax_rate_id' => $taxRateId,
            'account_id' => $this->accountId('4100'),
        ]);

        return $invoice->refresh();
    }

    private function balance(string $code): float
    {
        $lines = JournalEntryLine::where('account_id', $this->accountId($code))
            ->whereHas('journalEntry', fn ($q) => $q->where('is_posted', true));

        return round((float) (clone $lines)->sum('debit_amount') - (float) $lines->sum('credit_amount'), 2);
    }

    private function foreignBalance(string $code): float
    {
        $lines = JournalEntryLine::where('account_id', $this->accountId($code))
            ->whereHas('journalEntry', fn ($q) => $q->where('is_posted', true));

        return round(
            (float) (clone $lines)->sum('foreign_debit_amount') - (float) $lines->sum('foreign_credit_amount'),
            2,
        );
    }

    // ---- Issuing -------------------------------------------------------------

    public function test_the_invoice_keeps_its_own_currency_and_the_ledger_gets_rupees(): void
    {
        $this->rate(300, '2026-07-01');

        $invoice = $this->invoices()->issue($this->invoice(1000));

        $this->assertSame('1000.00', $invoice->total, 'what the client is billed');
        $this->assertSame(300000.0, $this->balance('1250'), 'what the books carry it at');
        $this->assertSame(-300000.0, $this->balance('4100'), 'revenue, credited');
    }

    public function test_the_rate_it_was_issued_at_is_written_down(): void
    {
        // Rather than looked up whenever it is needed: a rate recorded later for that day
        // must not silently restate an invoice that has already been issued.
        $this->rate(300, '2026-07-01');

        $invoice = $this->invoices()->issue($this->invoice(1000));

        $this->assertSame(300.0, $invoice->rate());

        $this->rate(320, '2026-07-15');

        $this->assertSame(300.0, $invoice->fresh()->rate(), 'unchanged by a later rate for the same day');
        $this->assertSame(300000.0, $this->balance('1250'));
    }

    public function test_an_agreed_rate_on_the_invoice_wins(): void
    {
        // A rate agreed with the client is a term of the deal, not an estimate of one.
        $this->rate(300, '2026-07-01');

        $invoice = $this->invoices()->issue($this->invoice(1000, ['exchange_rate' => 304]));

        $this->assertSame(304.0, $invoice->rate());
        $this->assertSame(304000.0, $this->balance('1250'));
    }

    public function test_the_receivable_records_the_euros_as_well(): void
    {
        // So that "what is still owed in euros" is answerable from the ledger, not only
        // from the documents.
        $this->rate(300, '2026-07-01');

        $this->invoices()->issue($this->invoice(1000));

        $this->assertSame(1000.0, $this->foreignBalance('1250'));
    }

    public function test_tax_is_translated_with_the_invoice(): void
    {
        $this->rate(300, '2026-07-01');
        $rate = TaxRate::create(['name' => 'GST 18%', 'rate' => 18]);

        $this->invoices()->issue($this->invoice(1000, taxRateId: $rate->id, tax: 180));

        $this->assertSame(354000.0, $this->balance('1250'), '1,180 euros at 300');
        $this->assertSame(-54000.0, $this->balance('2150'), 'the tax, in rupees');
    }

    public function test_it_refuses_to_issue_without_a_rate(): void
    {
        // Rather than guessing one, which is how a book goes quietly wrong.
        $this->expectExceptionMessage('No exchange rate for EUR');

        $this->invoices()->issue($this->invoice(1000));
    }

    public function test_the_books_balance_at_an_awkward_rate(): void
    {
        // round(a × r) + round(b × r) is not round((a + b) × r). Somebody has to hold the
        // cent, and the entry has to balance regardless.
        $this->rate(287.3333, '2026-07-01');
        $taxRate = TaxRate::create(['name' => 'GST 17.5%', 'rate' => 17.5]);

        $invoice = $this->invoices()->issue($this->invoice(333.33, taxRateId: $taxRate->id, tax: 58.33));

        $this->assertTrue(app(FinancialReportService::class)->trialBalance('2026-07-31')['balanced']);
        $this->assertSame(
            $invoice->baseTotal(),
            $this->balance('1250'),
            'and the receivable is exactly what the invoice is worth',
        );
    }

    // ---- Settlement ----------------------------------------------------------

    public function test_a_rise_in_the_rate_is_a_realised_gain(): void
    {
        $this->rate(300, '2026-07-01');
        $invoice = $this->invoices()->issue($this->invoice(1000));

        $this->rate(304, '2026-08-10');
        $this->invoices()->recordPayment($invoice, 1000, '2026-08-10');

        $this->assertSame(304000.0, $this->balance('1100'), 'what actually arrived');
        $this->assertSame(0.0, $this->balance('1250'), 'the receivable is cleared');
        $this->assertSame(-4000.0, $this->balance(CurrencyRevaluationService::REALISED_ACCOUNT_CODE), 'a gain, credited');
    }

    public function test_a_fall_in_the_rate_is_a_realised_loss(): void
    {
        $this->rate(304, '2026-07-01');
        $invoice = $this->invoices()->issue($this->invoice(1000));

        $this->rate(290, '2026-08-10');
        $this->invoices()->recordPayment($invoice, 1000, '2026-08-10');

        $this->assertSame(290000.0, $this->balance('1100'));
        $this->assertSame(0.0, $this->balance('1250'));
        $this->assertSame(14000.0, $this->balance(CurrencyRevaluationService::REALISED_ACCOUNT_CODE), 'a loss, debited');
    }

    public function test_no_movement_in_the_rate_writes_no_exchange_line(): void
    {
        $this->rate(300, '2026-07-01');
        $invoice = $this->invoices()->issue($this->invoice(1000));

        $this->invoices()->recordPayment($invoice, 1000, '2026-08-10');

        $this->assertSame(0.0, $this->balance(CurrencyRevaluationService::REALISED_ACCOUNT_CODE));
        $this->assertSame(Invoice::STATUS_PAID, $invoice->fresh()->status);
    }

    /** A bank advice saying what landed is a fact; the rate table is an estimate of it. */
    public function test_the_rate_the_bank_actually_gave_can_be_supplied(): void
    {
        $this->rate(300, '2026-07-01');
        $invoice = $this->invoices()->issue($this->invoice(1000));

        $this->rate(304, '2026-08-10');
        $this->invoices()->recordPayment($invoice, 1000, '2026-08-10', rate: 301.5);

        $this->assertSame(301500.0, $this->balance('1100'));
        $this->assertSame(-1500.0, $this->balance(CurrencyRevaluationService::REALISED_ACCOUNT_CODE));
    }

    public function test_the_euros_come_off_the_receivable(): void
    {
        $this->rate(300, '2026-07-01');
        $invoice = $this->invoices()->issue($this->invoice(1000));

        $this->rate(304, '2026-08-10');
        $this->invoices()->recordPayment($invoice, 1000, '2026-08-10');

        $this->assertSame(0.0, $this->foreignBalance('1250'), 'nothing is owed in euros either');
    }

    public function test_euros_paid_into_a_euro_account_are_euros_in_it(): void
    {
        $eurBank = Account::create([
            'code' => '1105', 'name' => 'EUR Bank', 'type' => 'asset', 'currency_code' => 'EUR',
        ]);

        $this->rate(300, '2026-07-01');
        $invoice = $this->invoices()->issue($this->invoice(1000));

        $this->rate(304, '2026-08-10');
        $this->invoices()->recordPayment($invoice, 1000, '2026-08-10', cashAccountId: $eurBank->id);

        $this->assertSame(1000.0, $this->foreignBalance('1105'));
        $this->assertSame(304000.0, $this->balance('1105'));
    }

    public function test_euros_paid_into_a_rupee_account_are_not_recorded_as_euros(): void
    {
        // The bank converted them. Writing euros into a rupee account would claim it
        // holds a currency it does not, and the next revaluation would act on that.
        $this->rate(300, '2026-07-01');
        $invoice = $this->invoices()->issue($this->invoice(1000));

        $this->rate(304, '2026-08-10');
        $this->invoices()->recordPayment($invoice, 1000, '2026-08-10');

        $this->assertSame(0.0, $this->foreignBalance('1100'));
        $this->assertSame(304000.0, $this->balance('1100'));
    }

    public function test_part_payments_each_realise_their_own_difference(): void
    {
        $this->rate(300, '2026-07-01');
        $invoice = $this->invoices()->issue($this->invoice(1000));

        $this->rate(304, '2026-08-10');
        $this->invoices()->recordPayment($invoice, 400, '2026-08-10');

        $this->rate(290, '2026-09-10');
        $this->invoices()->recordPayment($invoice->fresh(), 600, '2026-09-10');

        $this->assertSame(Invoice::STATUS_PAID, $invoice->fresh()->status);
        $this->assertSame(0.0, $this->balance('1250'), 'and nothing is left behind in it');
        $this->assertSame(
            4400.0,
            $this->balance(CurrencyRevaluationService::REALISED_ACCOUNT_CODE),
            '1,600 gained in August and 6,000 lost in September',
        );
    }

    /**
     * The residue this guards against: reliefs rounded one payment at a time need not add
     * up to the invoice's base total, and an invoice marked paid in full with a cent still
     * against it is the kind of leftover nobody finds.
     */
    public function test_thirds_of_an_invoice_still_clear_the_receivable_exactly(): void
    {
        $this->rate(287.3333, '2026-07-01');
        $invoice = $this->invoices()->issue($this->invoice(1000));

        foreach ([333.33, 333.33, 333.34] as $instalment) {
            $this->invoices()->recordPayment($invoice->fresh(), $instalment, '2026-08-10');
        }

        $this->assertSame(Invoice::STATUS_PAID, $invoice->fresh()->status);
        $this->assertSame(0.0, $this->balance('1250'));
        $this->assertSame(0.0, $this->foreignBalance('1250'));
    }

    public function test_the_history_says_what_the_rate_cost_or_gained(): void
    {
        $this->rate(300, '2026-07-01');
        $invoice = $this->invoices()->issue($this->invoice(1000));

        $this->rate(304, '2026-08-10');
        $this->invoices()->recordPayment($invoice, 1000, '2026-08-10');

        $this->assertStringContainsString('gain of 4,000.00', $invoice->events()->first()->description);
    }

    public function test_the_books_still_balance_through_settlement(): void
    {
        $this->rate(300, '2026-07-01');
        $invoice = $this->invoices()->issue($this->invoice(1000));

        $this->rate(304, '2026-08-10');
        $this->invoices()->recordPayment($invoice, 1000, '2026-08-10');

        $reports = app(FinancialReportService::class);
        $this->assertTrue($reports->trialBalance('2026-08-31')['balanced']);
        $this->assertTrue($reports->balanceSheet('2026-08-31')['balanced']);
    }

    // ---- Bills ---------------------------------------------------------------

    public function test_a_bill_in_euros_works_the_same_way_round(): void
    {
        $this->rate(300, '2026-07-01');

        $bill = $this->invoice(1000, ['kind' => Invoice::KIND_PURCHASE]);
        $this->invoices()->issue($bill);

        $this->assertSame(-300000.0, $this->balance('2400'), 'the payable, credited');

        $this->rate(304, '2026-08-10');
        $this->invoices()->recordPayment($bill->fresh(), 1000, '2026-08-10');

        $this->assertSame(0.0, $this->balance('2400'));
        $this->assertSame(-304000.0, $this->balance('1100'), 'what actually left');
        $this->assertSame(
            4000.0,
            $this->balance(CurrencyRevaluationService::REALISED_ACCOUNT_CODE),
            'a loss: the rupee fell and the euro bill cost more',
        );
    }

    // ---- Reports -------------------------------------------------------------

    public function test_aged_receivables_adds_up_in_one_currency(): void
    {
        // A total across invoices in a mixture of currencies is also a number, which is
        // exactly how this goes wrong unnoticed.
        $this->rate(300, '2026-07-01');
        $this->invoices()->issue($this->invoice(1000));

        $rupees = $this->invoice(50000, ['currency_code' => null, 'exchange_rate' => null]);
        $this->invoices()->issue($rupees);

        $aged = $this->invoices()->outstandingReceivables('2026-07-31');

        $this->assertSame(350000.0, $aged['total'], '300,000 translated plus 50,000');
        $this->assertSame(
            ['EUR', 'PKR'],
            collect($aged['invoices'])->pluck('currency_code')->sort()->values()->all(),
        );
        $this->assertSame(1000.0, collect($aged['invoices'])->firstWhere('currency_code', 'EUR')['outstanding']);
    }

    /** Nothing about an invoice in the company's own currency changes. */
    public function test_a_base_currency_invoice_posts_exactly_as_before(): void
    {
        $invoice = $this->invoices()->issue($this->invoice(50000, ['currency_code' => null]));

        $this->assertSame(50000.0, $this->balance('1250'));
        $this->assertNull($invoice->fresh()->exchange_rate);
        $this->assertNull($invoice->journalEntry->lines()->first()->currency_code);

        $this->invoices()->recordPayment($invoice, 50000, '2026-08-10');

        $this->assertSame(0.0, $this->balance('1250'));
        $this->assertSame(0.0, $this->balance(CurrencyRevaluationService::REALISED_ACCOUNT_CODE));
    }
}
