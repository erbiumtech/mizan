<?php

namespace Tests\Feature;

use App\Modules\Accounting\Models\Account;
use App\Modules\Invoicing\Models\Contact;
use App\Modules\Invoicing\Models\Invoice;
use App\Modules\Invoicing\Services\InvoiceService;
use Tests\AccountingTestCase;
use Tests\Concerns\InteractsWithTenant;

/**
 * A negative line on a purchase invoice.
 *
 * `saleEntryLines()` flips the leg for a negative line and says why: booking it as a negative credit
 * "would be dropped by `postSystemEntry`'s filter and leave the entry short by that amount".
 * `purchaseEntryLines()` did not do the same, so the identical shape on a supplier bill was unpostable.
 *
 * **Nothing in the application writes one today**, which is why this had never been hit. The first thing that
 * will is a subcontract payment certificate, whose retention line is negative by construction — see
 * `docs/construction-management-plan.md` §10.4, which calls this out as the one prerequisite in another
 * module and puts it in Phase 0 rather than in the phase that discovers it.
 *
 * Written before the fix, and it corrected the plan. §10.4 and the Risks section describe the imbalance as
 * "absorbed by the balancing fixer into an unrelated leg rather than failing". It was not: the bill threw
 * `Entry is not balanced: debits 1000000.00 != credits 900000.00`. `absorbRounding()` — the fixer — runs
 * inside `translateDocument()`, which is *before* `postSystemEntry()` drops the line, so it sees a balanced
 * set and does nothing; and it only runs for a foreign-currency invoice at all. So the bug was a loud refusal
 * on both paths, which is a better bug than the one documented, and the fix is the same either way.
 *
 * The FX case still earns its own test below, because `absorbRounding()` has no cap on what it will move onto
 * one line — if the ordering ever changes so the fixer runs after the filter, that is where a silent wrong
 * number would appear.
 */
class PurchaseInvoiceNegativeLineTest extends AccountingTestCase
{
    use InteractsWithTenant;

    private InvoiceService $service;

    private Contact $supplier;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs($this->makeUser('Administrator', 'negline@test.local'));
        $this->setCurrentTenant();

        $this->service = app(InvoiceService::class);

        $this->supplier = Contact::create([
            'name' => 'Groundworks Ltd',
            'kind' => Contact::KIND_SUPPLIER,
        ]);
    }

    private function expenseAccount(): int
    {
        return Account::where('code', '5100')->firstOrFail()->id;
    }

    /**
     * A retention-holding account: what the negative line on a subcontract certificate credits.
     *
     * Created here rather than borrowed from the shipped chart, and specifically **not** 2400: that is the
     * accounts-payable control account the purchase entry already credits, so using it would put the retention
     * and the payable on one account and make every assertion below ambiguous about which leg it found. §4 of
     * the construction plan adds the real one via `ConstructionAccounts`.
     */
    private function retentionAccount(): int
    {
        return Account::firstOrCreate(
            ['code' => '2250'],
            ['name' => 'Retention Payable', 'type' => 'liability'],
        )->id;
    }

    /** Accounts payable — the control leg, which must carry the net. */
    private function payableAccount(): int
    {
        return Account::where('code', '2400')->firstOrFail()->id;
    }

    /**
     * A supplier bill shaped like a subcontract certificate: gross work, less retention.
     *
     * @param  array<string, mixed>  $attributes
     */
    private function bill(float $gross, float $retention, array $attributes = []): Invoice
    {
        $bill = Invoice::create(array_merge([
            'kind' => Invoice::KIND_PURCHASE,
            'contact_id' => $this->supplier->id,
            'invoice_date' => '2026-08-10',
        ], $attributes));

        $bill->lines()->create([
            'description' => 'Work executed to 31 July',
            'quantity' => 1,
            'unit_price' => $gross,
            'line_total' => $gross,
            'account_id' => $this->expenseAccount(),
        ]);

        $bill->lines()->create([
            'description' => 'Less retention @ 10%',
            'quantity' => 1,
            'unit_price' => -$retention,
            'line_total' => -$retention,
            'account_id' => $this->retentionAccount(),
        ]);

        $net = round($gross - $retention, 2);

        $bill->forceFill(['subtotal' => $net, 'tax_amount' => 0, 'total' => $net])->save();

        return $bill;
    }

    /**
     * The bill posts, and the negative line is on the ledger as a credit.
     *
     * Both halves matter. That it posts at all is the bug; that the negative line survives as its own leg is
     * what makes the retention traceable — netting it into the expense would leave nothing to release against
     * later.
     */
    public function test_a_negative_line_posts_as_a_credit_leg(): void
    {
        $bill = $this->bill(gross: 1_000_000, retention: 100_000);

        $this->service->issue($bill);

        $lines = $bill->refresh()->journalEntry->lines;

        // The negative line is present, on the credit side, at its absolute value.
        $retention = $lines->firstWhere('account_id', $this->retentionAccount());

        $this->assertNotNull($retention, 'the negative line was dropped from the entry');
        $this->assertEqualsWithDelta(100_000.0, (float) $retention->credit_amount, 0.01);
        $this->assertEqualsWithDelta(0.0, (float) $retention->debit_amount, 0.01);

        // The expense is the gross, not the net: the retention is held, not discounted.
        $expense = $lines->firstWhere('account_id', $this->expenseAccount());
        $this->assertEqualsWithDelta(1_000_000.0, (float) $expense->debit_amount, 0.01);
    }

    /** And the entry balances, which is the thing that was impossible before. */
    public function test_the_entry_balances(): void
    {
        $bill = $this->bill(gross: 1_000_000, retention: 100_000);

        $this->service->issue($bill);

        $lines = $bill->refresh()->journalEntry->lines;

        $this->assertEqualsWithDelta(
            (float) $lines->sum('debit_amount'),
            (float) $lines->sum('credit_amount'),
            0.01,
            'debits and credits disagree',
        );

        // 1,000,000 debit expense against 100,000 credit retention and 900,000 credit payable.
        $this->assertEqualsWithDelta(1_000_000.0, (float) $lines->sum('debit_amount'), 0.01);
    }

    /**
     * The payable is the net, because that is what will actually be paid.
     *
     * Retention is withheld from the payment and owed later, so A/P carries the net while the expense carries
     * the gross — the asymmetry the negative leg exists to express.
     */
    public function test_the_payable_is_the_net_of_the_retention(): void
    {
        $bill = $this->bill(gross: 1_000_000, retention: 100_000);

        $this->service->issue($bill);

        $lines = $bill->refresh()->journalEntry->lines;

        $payable = $lines->firstWhere('account_id', $this->payableAccount());

        $this->assertNotNull($payable, 'no payable leg was posted');
        $this->assertEqualsWithDelta(900_000.0, (float) $payable->credit_amount, 0.01, 'A/P is not the net');
        $this->assertEqualsWithDelta(900_000.0, (float) $bill->total, 0.01, 'the bill total is not the net');

        // Expense gross, retention held, payable net — the three legs, and the whole point of the asymmetry.
        $this->assertEqualsWithDelta(
            (float) $lines->firstWhere('account_id', $this->expenseAccount())->debit_amount,
            (float) $payable->credit_amount + (float) $lines->firstWhere('account_id', $this->retentionAccount())->credit_amount,
            0.01,
            'gross does not equal net plus retention',
        );
    }

    /**
     * The same shape on a foreign-currency bill.
     *
     * Worth its own case because the FX path runs `absorbRounding()`, which has no cap on what it will move
     * onto one line — so if a negative line were dropped here, the difference would land silently on an
     * expense rather than failing. This asserts it does not.
     */
    public function test_a_negative_line_survives_the_foreign_currency_path(): void
    {
        $bill = $this->bill(gross: 1000, retention: 100, attributes: [
            'currency_code' => 'USD',
            'exchange_rate' => 280,
        ]);

        $this->service->issue($bill);

        $lines = $bill->refresh()->journalEntry->lines;

        $this->assertEqualsWithDelta(
            (float) $lines->sum('debit_amount'),
            (float) $lines->sum('credit_amount'),
            0.01,
            'the translated entry does not balance',
        );

        $retention = $lines->firstWhere('account_id', $this->retentionAccount());
        $this->assertNotNull($retention, 'the negative line was dropped on the FX path');
    }
}
