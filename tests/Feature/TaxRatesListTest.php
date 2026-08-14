<?php

namespace Tests\Feature;

use App\Modules\Accounting\Models\Account;
use App\Modules\Invoicing\Filament\Resources\TaxRates\TaxRateResource;
use App\Modules\Invoicing\Models\Contact;
use App\Modules\Invoicing\Models\Invoice;
use App\Modules\Invoicing\Models\TaxRate;
use Illuminate\Support\Facades\DB;
use Tests\AccountingTestCase;
use Tests\Concerns\InteractsWithTenant;

/**
 * The tax-rates list, rendered with rates on it.
 *
 * Two of this table's columns were per-row. "Posts to" read the `account` relation, which is a query a row
 * and — with lazy loading disabled outside production — a violation that stops the page rendering at all.
 * "Charged to date" ran `lines()->sum('tax_amount')`, one aggregate per row.
 *
 * Both are now loaded with the page, and the second one is the reason this file asserts a *figure* as well
 * as a query count: swapping a per-row sum for `withSum` changes how the number is obtained, and a
 * regression there would be a wrong tax figure on a screen used for filing rather than an error anybody
 * would notice.
 *
 * Rendered with rows, because that is the only state in which either defect exists — see
 * PayrollRunsListTest for the same lesson learned the same way.
 */
class TaxRatesListTest extends AccountingTestCase
{
    use InteractsWithTenant;

    private Contact $client;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs($this->makeUser('Administrator', 'rates@test.local'));
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

    /**
     * An invoice line at the given rate, carrying the tax it charged.
     *
     * `tax_amount` is set on the line directly, as this application's own tax tests do. Where the figure
     * comes from is not what this file is about — it is about `withSum` reading the same column the
     * per-row sum read.
     */
    private function charge(TaxRate $rate, float $amount, float $tax): void
    {
        $invoice = Invoice::create([
            'kind' => Invoice::KIND_SALE,
            'contact_id' => $this->client->id,
            'invoice_date' => '2026-05-01',
            'fiscal_year_id' => $this->fiscalYear->id,
            'subtotal' => $amount,
            'tax_amount' => $tax,
            'total' => $amount + $tax,
        ]);

        $invoice->lines()->create([
            'description' => 'Consulting',
            'quantity' => 1,
            'unit_price' => $amount,
            'line_total' => $amount,
            'account_id' => Account::where('code', '4100')->firstOrFail()->id,
            'tax_rate_id' => $rate->getKey(),
            'tax_amount' => $tax,
        ]);
    }

    /** @return array<int, string> */
    private function renderList(): array
    {
        $queries = [];

        DB::listen(function ($query) use (&$queries): void {
            $queries[] = $query->sql;
        });

        $this->get(TaxRateResource::getUrl('index'))->assertOk();

        return $queries;
    }

    public function test_the_list_renders_with_rates_on_it(): void
    {
        $account = Account::where('code', '2100')->first();

        $this->rate(18, ['account_id' => $account?->getKey()]);
        $this->rate(5);

        $response = $this->get(TaxRateResource::getUrl('index'))->assertOk();

        $response->assertSee('GST 18%');
        $response->assertSee('GST 5%');

        // "Posts to" is the column that reads the account relation. A rate with one shows it; a rate
        // without falls back to the default code, which is what the column has always said.
        if ($account !== null) {
            $response->assertSee($account->code);
        }

        $response->assertSee(TaxRate::DEFAULT_ACCOUNT_CODE);
    }

    /**
     * The charged figure survived the change in how it is computed.
     *
     * `withSum('lines', 'tax_amount')` has to produce exactly what `lines()->sum('tax_amount')` did —
     * including summing across every invoice, which is what the column claimed and still claims.
     */
    public function test_the_charged_figure_is_the_tax_actually_charged(): void
    {
        $rate = $this->rate(10);

        $this->charge($rate, 1000, 100);
        $this->charge($rate, 500, 50);

        $expected = (float) $rate->lines()->sum('tax_amount');

        $this->assertGreaterThan(0, $expected, 'the fixture charged no tax, so this proves nothing');

        $loaded = TaxRate::query()->withSum('lines', 'tax_amount')->find($rate->getKey());

        $this->assertSame(
            $expected,
            (float) $loaded->lines_sum_tax_amount,
            'the aggregate on the page disagrees with the sum it replaced',
        );

        $this->get(TaxRateResource::getUrl('index'))
            ->assertOk()
            ->assertSee(number_format($expected, 2));
    }

    /** A rate nothing has been charged at reads as nought rather than as blank or an error. */
    public function test_a_rate_with_nothing_charged_reads_as_zero(): void
    {
        $this->rate(3);

        $this->get(TaxRateResource::getUrl('index'))->assertOk()->assertSee('0.00');
    }

    /** The page's cost does not grow with the number of rates on it. */
    public function test_the_list_does_not_pay_per_row(): void
    {
        $this->rate(18);

        $this->renderList();
        $few = $this->renderList();

        foreach ([1, 5, 12, 16, 21] as $percent) {
            $this->rate($percent);
        }

        $many = $this->renderList();
        $growth = count($many) - count($few);

        $this->assertLessThanOrEqual(
            2,
            $growth,
            "five more rates cost {$growth} more queries:\n\n".implode("\n", array_diff($many, $few)),
        );
    }
}
