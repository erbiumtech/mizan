<?php

namespace Tests\Feature;

use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\JournalEntryLine;
use App\Modules\Accounting\Services\FinancialReportService;
use App\Modules\Invoicing\Models\Contact;
use App\Modules\Invoicing\Models\Invoice;
use App\Modules\Invoicing\Models\TaxRate;
use App\Modules\Invoicing\Services\InvoiceService;
use Tests\AccountingTestCase;
use Tests\Concerns\InteractsWithTenant;

/**
 * Tax as a rate rather than a number somebody types.
 *
 * `invoices.tax_amount` was a free decimal tied to no rate: a tax return cannot say
 * what was charged at 18% when only the result was ever recorded, and every tax
 * posted to one hard-coded account, so two taxes could not be told apart in the
 * ledger.
 *
 * The plan named this the highest-risk item in the document, for one reason: it
 * changes how invoices post, and invoices raised before it must keep their totals.
 * Both of its stated conditions are tested here.
 */
class TaxRateTest extends AccountingTestCase
{
    use InteractsWithTenant;

    private Contact $client;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs($this->makeUser('Administrator', 'tax@test.local'));
        $this->setCurrentTenant();

        $this->client = Contact::create([
            'name' => 'Erbium AG',
            'kind' => Contact::KIND_BOTH,
            'is_active' => true,
        ]);
    }

    private function rate(float $percent, array $attributes = []): TaxRate
    {
        return TaxRate::create(array_merge([
            'name' => 'GST '.$percent.'%',
            'rate' => $percent,
        ], $attributes));
    }

    /** @param array<int, array{amount: float, rate?: TaxRate|null}> $lines */
    private function invoice(array $lines, bool $inclusive = false, string $kind = Invoice::KIND_SALE): Invoice
    {
        $invoice = Invoice::create([
            'kind' => $kind,
            'contact_id' => $this->client->id,
            'invoice_date' => '2026-08-01',
            'fiscal_year_id' => $this->fiscalYear->id,
            'tax_inclusive' => $inclusive,
            'subtotal' => 0,
            'tax_amount' => 0,
            'total' => 0,
        ]);

        $account = Account::where('code', $kind === Invoice::KIND_SALE ? '4100' : '5700')->firstOrFail();

        foreach ($lines as $line) {
            $invoice->lines()->create([
                'description' => 'Services',
                'quantity' => 1,
                'unit_price' => $line['amount'],
                'line_total' => $line['amount'],
                'account_id' => $account->id,
                'tax_rate_id' => ($line['rate'] ?? null)?->id,
            ]);
        }

        return $invoice->refresh();
    }

    private function balanceOf(string $code): float
    {
        $account = Account::where('code', $code)->firstOrFail();

        $query = JournalEntryLine::where('account_id', $account->id)
            ->whereHas('journalEntry', fn ($q) => $q->where('is_posted', true));

        return round((float) $query->sum('debit_amount') - (float) (clone $query)->sum('credit_amount'), 2);
    }

    // ---- The rate itself ---------------------------------------------------

    public function test_a_rate_is_a_percentage_not_a_fraction(): void
    {
        // 18.0000 is 18%, because that is how it is legislated, quoted and printed.
        $rate = $this->rate(18);

        $this->assertSame(0.18, $rate->fraction());
        $this->assertSame(1800.0, $rate->taxOn(10000));
    }

    /**
     * 118 at 18% is 18 of tax, not 21.24. The difference between these two is the
     * whole of the inclusive/exclusive question, and getting it wrong overstates the
     * tax and understates the revenue.
     */
    public function test_tax_within_an_inclusive_amount_is_not_tax_on_it(): void
    {
        $rate = $this->rate(18);

        $this->assertSame(18.0, $rate->taxWithin(118));
        $this->assertSame(21.24, $rate->taxOn(118));
    }

    public function test_only_one_rate_is_the_default(): void
    {
        $first = $this->rate(18, ['is_default' => true]);
        $second = $this->rate(5, ['is_default' => true]);

        $this->assertSame(1, TaxRate::where('is_default', true)->count());
        $this->assertTrue($second->fresh()->is_default);
        $this->assertFalse($first->fresh()->is_default);
    }

    /**
     * Enforced on the model, not only in the policy: Administrators and super admins
     * pass every policy check, so a restriction that has to hold for everyone cannot
     * live in one.
     */
    public function test_a_rate_that_has_been_charged_cannot_be_deleted(): void
    {
        $rate = $this->rate(18);
        $invoice = $this->invoice([['amount' => 10000, 'rate' => $rate]]);

        app(InvoiceService::class)->issue($invoice);

        $this->assertTrue(auth()->user()->isAdministrator(), 'who would otherwise pass any policy');

        try {
            $rate->refresh()->delete();
            $this->fail('a charged rate was deleted');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('Switch it off instead', $e->getMessage());
        }

        $this->assertDatabaseHas('tax_rates', ['id' => $rate->id]);

        // One that has charged nothing goes.
        $unused = $this->rate(5);
        $unused->delete();
        $this->assertDatabaseMissing('tax_rates', ['id' => $unused->id]);
    }

    // ---- The plan's two conditions -----------------------------------------

    /**
     * The first: an invoice raised before rates existed keeps its total.
     *
     * Its lines carry no rate and its tax_amount was typed. Recomputing tax from
     * rates it does not have would zero it and restate the invoice.
     */
    public function test_an_invoice_with_no_rates_keeps_the_tax_that_was_typed(): void
    {
        $invoice = $this->invoice([['amount' => 10000]]);
        $invoice->update(['subtotal' => 10000, 'tax_amount' => 1500, 'total' => 11500]);

        app(InvoiceService::class)->issue($invoice->refresh());

        $invoice->refresh();

        $this->assertSame('1500.00', $invoice->tax_amount);
        $this->assertSame('11500.00', $invoice->total);
        $this->assertSame(1500.0, -$this->balanceOf('2150'), 'and it still posts to the shipped tax account');
        $this->assertSame(11500.0, $this->balanceOf('1250'), 'receivable is the gross');
    }

    /** The second: a rated line proves out in the trial balance. */
    public function test_a_15_percent_line_proves_out_in_the_trial_balance(): void
    {
        $invoice = $this->invoice([['amount' => 10000, 'rate' => $this->rate(15)]]);

        app(InvoiceService::class)->issue($invoice);

        $invoice->refresh();

        $this->assertSame('10000.00', $invoice->subtotal);
        $this->assertSame('1500.00', $invoice->tax_amount);
        $this->assertSame('11500.00', $invoice->total);

        $this->assertSame(11500.0, $this->balanceOf('1250'), 'receivable');
        $this->assertSame(-10000.0, $this->balanceOf('4100'), 'revenue, net of tax');
        $this->assertSame(-1500.0, $this->balanceOf('2150'), 'tax owed to the authority');

        $trial = app(FinancialReportService::class)->trialBalance('2026-08-31');
        $this->assertTrue($trial['balanced']);
    }

    // ---- Inclusive and exclusive -------------------------------------------

    public function test_an_exclusive_invoice_adds_the_tax_on_top(): void
    {
        $invoice = $this->invoice([['amount' => 10000, 'rate' => $this->rate(18)]]);

        app(InvoiceService::class)->issue($invoice);

        $invoice->refresh();

        $this->assertSame('10000.00', $invoice->subtotal);
        $this->assertSame('1800.00', $invoice->tax_amount);
        $this->assertSame('11800.00', $invoice->total);
    }

    public function test_an_inclusive_invoice_takes_the_tax_out_of_the_price(): void
    {
        // The client pays 11,800 either way; what differs is how much of it is
        // revenue, and the ledger must not book tax as income.
        $invoice = $this->invoice([['amount' => 11800, 'rate' => $this->rate(18)]], inclusive: true);

        app(InvoiceService::class)->issue($invoice);

        $invoice->refresh();

        $this->assertSame('10000.00', $invoice->subtotal);
        $this->assertSame('1800.00', $invoice->tax_amount);
        $this->assertSame('11800.00', $invoice->total);

        $this->assertSame(-10000.0, $this->balanceOf('4100'), 'revenue is net');
        $this->assertSame(-1800.0, $this->balanceOf('2150'));
        $this->assertSame(11800.0, $this->balanceOf('1250'));
    }

    // ---- More than one rate ------------------------------------------------

    public function test_two_taxes_post_to_their_own_accounts(): void
    {
        // Two authorities, two liabilities. A single 2150 balance could be filed
        // against neither, which is why the tax is grouped by the rate's account.
        $provincial = Account::create([
            'code' => '2160',
            'name' => 'Provincial Levy Payable',
            'type' => 'liability',
        ]);

        $invoice = $this->invoice([
            ['amount' => 10000, 'rate' => $this->rate(18)],
            ['amount' => 5000, 'rate' => $this->rate(5, ['account_id' => $provincial->id])],
        ]);

        app(InvoiceService::class)->issue($invoice);

        $this->assertSame('2050.00', $invoice->refresh()->tax_amount, '1,800 plus 250');
        $this->assertSame(-1800.0, $this->balanceOf('2150'));
        $this->assertSame(-250.0, $this->balanceOf('2160'));
    }

    public function test_an_untaxed_line_beside_a_taxed_one_carries_no_tax(): void
    {
        $invoice = $this->invoice([
            ['amount' => 10000, 'rate' => $this->rate(18)],
            ['amount' => 5000],
        ]);

        app(InvoiceService::class)->issue($invoice);
        $invoice->refresh();

        $this->assertSame('15000.00', $invoice->subtotal);
        $this->assertSame('1800.00', $invoice->tax_amount, 'only the rated line is taxed');
        $this->assertSame('16800.00', $invoice->total);
    }

    // ---- Purchases ---------------------------------------------------------

    public function test_input_tax_on_a_bill_is_not_part_of_the_cost(): void
    {
        // The tax is recoverable, so it belongs on the tax account rather than in
        // the cost of the thing bought.
        $invoice = $this->invoice(
            [['amount' => 10000, 'rate' => $this->rate(18)]],
            kind: Invoice::KIND_PURCHASE,
        );

        app(InvoiceService::class)->issue($invoice);

        $this->assertSame(10000.0, $this->balanceOf('5700'), 'the expense is net');
        $this->assertSame(1800.0, $this->balanceOf('2150'), 'input tax, debited');
        $this->assertSame(-11800.0, $this->balanceOf('2400'), 'payable is the gross');
    }

    // ---- History ------------------------------------------------------------

    /**
     * Changing a rate must not rewrite what an issued invoice charged, which is why
     * the line's tax is stored rather than recomputed on read.
     */
    public function test_changing_a_rate_leaves_issued_invoices_alone(): void
    {
        $rate = $this->rate(18);
        $invoice = $this->invoice([['amount' => 10000, 'rate' => $rate]]);

        app(InvoiceService::class)->issue($invoice);

        $rate->update(['rate' => 25]);

        $invoice->refresh();

        $this->assertSame('1800.00', $invoice->tax_amount);
        $this->assertSame('1800.00', $invoice->lines()->first()->tax_amount);
        $this->assertSame(-1800.0, $this->balanceOf('2150'));
    }

    public function test_a_zero_rate_is_recorded_and_charges_nothing(): void
    {
        // Zero-rated is not the same as untaxed: the return has to show that the
        // sale was made at 0%, not that no tax question arose.
        $zero = $this->rate(0, ['name' => 'Zero-rated export']);
        $invoice = $this->invoice([['amount' => 10000, 'rate' => $zero]]);

        app(InvoiceService::class)->issue($invoice);
        $invoice->refresh();

        $this->assertSame('0.00', $invoice->tax_amount);
        $this->assertSame('10000.00', $invoice->total);
        $this->assertSame($zero->id, $invoice->lines()->first()->tax_rate_id, 'the rate is on the record');
        $this->assertSame(0.0, $this->balanceOf('2150'), 'and nothing posts to tax');
    }

    // ---- Re-running the seeder ----------------------------------------------

    public function test_the_seeder_gives_a_new_company_the_rates_it_starts_from(): void
    {
        $this->seed(\Database\Seeders\TaxRateSeeder::class);

        $this->assertSame(18.0, (float) TaxRate::where('name', 'GST 18%')->value('rate'));
        $this->assertSame(0.0, (float) TaxRate::where('name', 'Zero-rated')->value('rate'));
    }

    /**
     * Re-running the seeder is how a company picks up a rate added to the code. Which
     * rate its invoices charge by default is a decision about that company, so the
     * seeder must not put 18% back onto every line of one that turned it off — an
     * export client charged sales tax by a seeder is not a mistake anybody looks for.
     */
    public function test_re_running_it_does_not_re_apply_a_default_that_was_turned_off(): void
    {
        $this->seed(\Database\Seeders\TaxRateSeeder::class);

        TaxRate::where('is_default', true)->update(['is_default' => false]);

        $this->seed(\Database\Seeders\TaxRateSeeder::class);

        $this->assertSame(0, TaxRate::where('is_default', true)->count());
    }

    public function test_re_running_it_does_not_re_enable_a_rate_that_was_switched_off(): void
    {
        $this->seed(\Database\Seeders\TaxRateSeeder::class);

        TaxRate::where('name', 'GST 18%')->update(['is_active' => false]);

        $this->seed(\Database\Seeders\TaxRateSeeder::class);

        $this->assertFalse((bool) TaxRate::where('name', 'GST 18%')->value('is_active'));
    }

    public function test_re_running_it_does_correct_the_rate_itself(): void
    {
        // The percentage is a fact about the tax, not a decision about the company, so
        // that much is re-asserted.
        $this->seed(\Database\Seeders\TaxRateSeeder::class);

        TaxRate::where('name', 'GST 18%')->update(['rate' => 5]);

        $this->seed(\Database\Seeders\TaxRateSeeder::class);

        $this->assertSame(18.0, (float) TaxRate::where('name', 'GST 18%')->value('rate'));
    }

    public function test_it_does_not_duplicate_them(): void
    {
        $this->seed(\Database\Seeders\TaxRateSeeder::class);
        $this->seed(\Database\Seeders\TaxRateSeeder::class);

        $this->assertSame(2, TaxRate::whereIn('name', ['GST 18%', 'Zero-rated'])->count());
    }
}
