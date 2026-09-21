<?php

namespace Tests\Feature;

use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\JournalEntryLine;
use App\Modules\Invoicing\Models\Contact;
use App\Modules\Invoicing\Models\CustomerCredit;
use App\Modules\Invoicing\Models\Invoice;
use App\Modules\Invoicing\Services\InvoiceService;
use InvalidArgumentException;
use Tests\AccountingTestCase;
use Tests\Concerns\InteractsWithTenant;

/**
 * Money on account — `docs/erpnext-gap-plan.md` §2.2, the deferral that plan left until somebody had the
 * problem: a deposit on fixed-price work, a retainer, or a transfer bigger than the invoices it was for.
 *
 * The property worth holding onto is that **applying a credit is an ordinary settlement**: `recordPayment()`
 * with 2600 in place of the bank, so the receivable, the statuses and the events behave exactly as they do
 * for cash, and no second posting path exists to drift.
 */
class CustomerCreditTest extends AccountingTestCase
{
    use InteractsWithTenant;

    private InvoiceService $service;

    private Contact $customer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs($this->makeUser('Administrator', 'credits@test.local'));
        $this->setCurrentTenant();

        $this->service = app(InvoiceService::class);
        $this->customer = Contact::create(['name' => 'Deposit Client', 'kind' => 'customer']);
    }

    private function issued(float $amount, ?Contact $contact = null): Invoice
    {
        $invoice = Invoice::create([
            'kind' => Invoice::KIND_SALE,
            'contact_id' => ($contact ?? $this->customer)->getKey(),
            'invoice_date' => '2026-08-10',
            'due_date' => '2026-09-09',
            'subtotal' => $amount,
            'tax_amount' => 0,
            'total' => $amount,
        ]);

        $invoice->lines()->create(['description' => 'Work', 'quantity' => 1, 'unit_price' => $amount, 'line_total' => $amount]);

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

    public function test_a_deposit_is_a_liability_until_an_invoice_draws_on_it(): void
    {
        $credit = $this->service->holdOnAccount($this->customer, 200_000, '2026-07-01', 'TT-4471');

        // Debit bank, credit 2600. It is not revenue: nothing has been delivered.
        $this->assertSame(200_000.0, $this->balance('1100'));
        $this->assertSame(-200_000.0, $this->balance('2600'), 'a liability, credit-normal');
        $this->assertSame(0.0, $this->balance('4300'));
        $this->assertSame(200_000.0, $credit->remaining());
    }

    public function test_applying_it_settles_the_invoice_and_moves_no_money(): void
    {
        $credit = $this->service->holdOnAccount($this->customer, 200_000, '2026-07-01');
        $cashAfterDeposit = $this->balance('1100');

        $invoice = $this->issued(120_000);
        $this->service->applyCredit($credit, $invoice, 120_000, '2026-08-20');

        $this->assertSame(Invoice::STATUS_PAID, $invoice->refresh()->status);
        $this->assertSame(0.0, $this->balance('1250'), 'the receivable is cleared');
        $this->assertSame($cashAfterDeposit, $this->balance('1100'), 'and no cash moved: none did');
        $this->assertSame(-80_000.0, $this->balance('2600'), 'the liability is discharged by what was used');
        $this->assertSame(80_000.0, $credit->refresh()->remaining());
    }

    public function test_a_credit_cannot_be_spent_twice_or_on_somebody_elses_invoice(): void
    {
        $credit = $this->service->holdOnAccount($this->customer, 50_000, '2026-07-01');
        $this->service->applyCredit($credit, $this->issued(40_000), 40_000, '2026-08-20');

        try {
            $this->service->applyCredit($credit->refresh(), $this->issued(30_000), 30_000, '2026-08-21');
            $this->fail('Expected the remaining balance to refuse.');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('Only 10,000.00 is left', $e->getMessage());
        }

        $other = Contact::create(['name' => 'Someone Else', 'kind' => 'customer']);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('belongs to a different customer');

        $this->service->applyCredit($credit->refresh(), $this->issued(5_000, $other), 5_000, '2026-08-21');
    }

    public function test_an_over_payment_settles_what_it_can_and_the_rest_is_held(): void
    {
        $invoice = $this->issued(30_000);

        $settled = $this->service->recordBatchReceipt(
            50_000,
            '2026-08-20',
            [$invoice->getKey() => 30_000],
            'TT-9001',
            holdOnAccount: true,
        );

        $this->assertSame(Invoice::STATUS_PAID, $settled[0]->refresh()->status);

        $credit = CustomerCredit::query()->sole();
        $this->assertSame(20_000.0, $credit->remaining());
        $this->assertSame($this->customer->getKey(), $credit->contact_id);
        $this->assertSame('TT-9001', $credit->reference);
        $this->assertSame(-20_000.0, $this->balance('2600'));
    }

    /** Without the flag the refusal stands — and now it says what the way out is. */
    public function test_an_unallocated_remainder_is_still_refused_unless_it_was_asked_for(): void
    {
        $invoice = $this->issued(30_000);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('hold it on account');

        $this->service->recordBatchReceipt(50_000, '2026-08-20', [$invoice->getKey() => 30_000]);
    }

    public function test_allocating_more_than_arrived_is_still_inventing_money(): void
    {
        $invoice = $this->issued(30_000);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('cannot settle more than arrived');

        $this->service->recordBatchReceipt(10_000, '2026-08-20', [$invoice->getKey() => 30_000], null, true);
    }
}
