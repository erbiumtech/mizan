<?php

namespace Tests\Feature;

use App\Modules\Core\Models\CompanyModule;
use App\Modules\Core\Models\FiscalYear;
use App\Modules\Core\Models\User;
use App\Modules\Inventory\Models\Product;
use App\Modules\Invoicing\Filament\Pages\RevenueByDimension;
use App\Modules\Invoicing\Models\Contact;
use App\Modules\Invoicing\Models\Invoice;
use App\Modules\Projects\Models\Project;
use App\Support\Reporting\ReportPaneRenderer;
use App\Support\Reporting\ReportRenderers;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;
use Tests\Concerns\InteractsWithTenant;
use Tests\TestCase;

/**
 * Revenue by customer, project and product — `docs/reports-expansion-plan.md` Phase 3.4.
 *
 * Three claims, and the first is the one that would silently treble a company's revenue if it were ever
 * broken:
 *
 *  - **The three groupings are the same money and must be totalled once.** A sale appears under its customer,
 *    its project and each of its products, so the record row totals the customer grouping alone.
 *  - **A credit note is attributed to the invoice it credits.** The revenue was recognised against that
 *    customer and project, so the reversal belongs there — not against whatever was typed on the credit note,
 *    which in practice carries a customer and no project.
 *  - **Only issued invoices are revenue.** A draft is not, a void one never was, and a purchase is cost.
 *
 * `InvoiceTest` and `InvoiceCreditNoteTest` own invoice arithmetic and the credit-note rules. None of it is
 * restated here.
 */
class RevenueByDimensionReportTest extends TestCase
{
    use InteractsWithTenant;
    use RefreshDatabase;

    /** February of the 2026-2027 fiscal year, so the calendar year differs from the financial one. */
    private const AS_OF = '2027-02-20';

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs(User::factory()->create(['status' => 1]));
        $this->setCurrentTenant();

        foreach (['invoicing', 'projects', 'inventory', 'accounting'] as $module) {
            CompanyModule::updateOrCreate(
                ['company_id' => $this->tenant->getKey(), 'module' => $module],
                ['licensed' => true, 'enabled' => true],
            );
        }
        modules()->flush();

        FiscalYear::firstOrCreate(
            ['name' => '2026-2027'],
            ['start_date' => '2026-07-01', 'end_date' => '2027-06-30', 'is_active' => true],
        );
    }

    // ────────────────────────────────────────────────────────────── fixtures ──

    private function customer(string $name): Contact
    {
        return Contact::create(['name' => $name, 'kind' => Contact::KIND_CUSTOMER]);
    }

    private function project(string $name): Project
    {
        return Project::create(['name' => $name, 'code' => strtoupper(substr(md5($name), 0, 6))]);
    }

    private function product(string $name): Product
    {
        return Product::create(['sku' => strtoupper(substr(md5($name), 0, 6)), 'name' => $name, 'unit' => 'pcs']);
    }

    private function invoice(Contact $customer, float $total, array $attributes = []): Invoice
    {
        return Invoice::create(array_merge([
            'contact_id' => $customer->id,
            'kind' => Invoice::KIND_SALE,
            'status' => Invoice::STATUS_ISSUED,
            'invoice_date' => '2026-08-10',
            'total' => $total,
            'subtotal' => $total,
        ], $attributes));
    }

    private function line(Invoice $invoice, float $total, ?Product $product = null): void
    {
        $invoice->lines()->create([
            'product_id' => $product?->id,
            'description' => $product?->name ?? 'Consultancy',
            'quantity' => 1,
            'unit_price' => $total,
            'line_total' => $total,
        ]);
    }

    /** @return array<string, mixed> */
    private function report(?string $asOf = null): array
    {
        $payload = ReportRenderers::render('RevenueByDimension', $asOf ?? self::AS_OF, false, []);

        $this->assertNotNull($payload, 'no renderer is registered for RevenueByDimension');

        return $payload;
    }

    private function row(array $payload, string $label): array
    {
        foreach ($payload['rows'] as $row) {
            if (str_contains($row[0], $label)) {
                return $row;
            }
        }

        $this->fail("no row for [{$label}] in ".collect($payload['rows'])->pluck(0)->implode(' | '));
    }

    // ────────────────────────────────────── the three groupings are one sum ──

    /**
     * The same money three ways, totalled once.
     *
     * One sale of 100,000 to a customer, on a project, for a product. Every grouping shows 100,000 and the
     * record row shows 100,000 — not 300,000, which is what summing the rows would give.
     */
    public function test_the_three_groupings_are_totalled_once(): void
    {
        $customer = $this->customer('Karachi Textiles');
        $project = $this->project('Warehouse');
        $product = $this->product('Widget');

        $invoice = $this->invoice($customer, 100_000, ['project_id' => $project->id]);
        $this->line($invoice, 100_000, $product);

        $payload = $this->report();

        $this->assertSame('100,000', $this->row($payload, 'By customer · Karachi Textiles')[4]);
        $this->assertSame('100,000', $this->row($payload, 'By project · Warehouse')[4]);
        $this->assertSame('100,000', $this->row($payload, 'By product · Widget')[4]);

        $this->assertSame(100_000.0, $payload['tiles'][0]['value'], 'net revenue is the money once');
        $this->assertSame('100,000', $payload['footer'][4]);
        $this->assertStringContainsString('THREE GROUPINGS OF THE SAME MONEY, TOTALLED ONCE', $payload['note']);
    }

    /** Groupings appear in reading order: customer, then project, then product. */
    public function test_the_groupings_are_in_reading_order(): void
    {
        $invoice = $this->invoice($this->customer('Karachi Textiles'), 50_000, ['project_id' => $this->project('Warehouse')->id]);
        $this->line($invoice, 50_000, $this->product('Widget'));

        $labels = collect($this->report()['rows'])->pluck(0)->all();

        $this->assertStringContainsString('By customer', $labels[0]);
        $this->assertStringContainsString('By project', $labels[1]);
        $this->assertStringContainsString('By product', $labels[2]);
    }

    /** And biggest net first inside a grouping. */
    public function test_rows_are_ordered_by_net_within_a_grouping(): void
    {
        $this->invoice($this->customer('Small'), 10_000);
        $this->invoice($this->customer('Big'), 90_000);

        $customers = collect($this->report()['rows'])
            ->filter(fn (array $row): bool => str_starts_with($row[0], 'By customer'))
            ->pluck(0)
            ->values()
            ->all();

        $this->assertStringContainsString('Big', $customers[0]);
        $this->assertStringContainsString('Small', $customers[1]);
    }

    // ──────────────────────────────────────────────── credit notes ──

    /**
     * A credit note follows the invoice it credits, not its own columns.
     *
     * The invoice carries a project; the credit note carries only the customer, which is what happens in
     * practice. Attributed by its own columns the reversal would land under *No project* and the project
     * would keep revenue that had been given back.
     */
    public function test_a_credit_note_is_attributed_to_the_invoice_it_credits(): void
    {
        $customer = $this->customer('Karachi Textiles');
        $project = $this->project('Warehouse');

        $invoice = $this->invoice($customer, 100_000, ['project_id' => $project->id]);
        $this->invoice($customer, 30_000, [
            'kind' => Invoice::KIND_CREDIT_NOTE,
            'credits_invoice_id' => $invoice->id,
            'invoice_date' => '2026-09-01',
            // Deliberately no project, as a real credit note usually has.
            'project_id' => null,
        ]);

        $payload = $this->report();

        $this->assertSame('30,000', $this->row($payload, 'By project · Warehouse')[3], 'the credit lands on the project');
        $this->assertSame('70,000', $this->row($payload, 'By project · Warehouse')[4]);
        $this->assertSame(70_000.0, $payload['tiles'][0]['value']);
        $this->assertSame(30_000.0, $payload['tiles'][1]['value']);
    }

    /** A credit note naming no invoice is attributed by its own columns, because there is nothing better. */
    public function test_a_credit_note_with_no_invoice_uses_its_own_columns(): void
    {
        $customer = $this->customer('Karachi Textiles');
        $this->invoice($customer, 100_000);
        $this->invoice($customer, 25_000, [
            'kind' => Invoice::KIND_CREDIT_NOTE,
            'credits_invoice_id' => null,
            'invoice_date' => '2026-09-01',
        ]);

        $payload = $this->report();

        $this->assertSame('25,000', $this->row($payload, 'By customer · Karachi Textiles')[3]);
        $this->assertSame(75_000.0, $payload['tiles'][0]['value']);
    }

    /** Nothing credited reads as a dash and the note says so. */
    public function test_nothing_credited_reads_as_a_dash(): void
    {
        $this->invoice($this->customer('Karachi Textiles'), 100_000);

        $payload = $this->report();

        $this->assertSame('—', $this->row($payload, 'By customer')[3]);
        $this->assertStringContainsString('NOTHING CREDITED BACK', $payload['note']);
    }

    // ──────────────────────────────────────────── what counts as revenue ──

    /** A draft is not revenue. */
    public function test_a_draft_invoice_is_not_revenue(): void
    {
        $customer = $this->customer('Karachi Textiles');
        $this->invoice($customer, 100_000);
        $this->invoice($customer, 40_000, ['status' => Invoice::STATUS_DRAFT]);

        $this->assertSame(100_000.0, $this->report()['tiles'][0]['value']);
    }

    /** Nor is a void one. */
    public function test_a_void_invoice_is_not_revenue(): void
    {
        $customer = $this->customer('Karachi Textiles');
        $this->invoice($customer, 100_000);
        $this->invoice($customer, 40_000, ['status' => Invoice::STATUS_VOID]);

        $this->assertSame(100_000.0, $this->report()['tiles'][0]['value']);
    }

    /** A purchase is cost, and appears nowhere. */
    public function test_a_purchase_is_not_revenue(): void
    {
        $customer = $this->customer('Karachi Textiles');
        $this->invoice($customer, 100_000);
        $this->invoice($customer, 60_000, ['kind' => Invoice::KIND_PURCHASE]);

        $this->assertSame(100_000.0, $this->report()['tiles'][0]['value']);
    }

    /** A paid invoice is still revenue — it is more certainly revenue than an issued one. */
    public function test_a_paid_invoice_is_revenue(): void
    {
        $this->invoice($this->customer('Karachi Textiles'), 100_000, ['status' => Invoice::STATUS_PAID]);

        $this->assertSame(100_000.0, $this->report()['tiles'][0]['value']);
    }

    // ─────────────────────────────────────────────── the absent dimensions ──

    /** Invoicing against no project is a row, and the note states the amount. */
    public function test_invoicing_with_no_project_is_named(): void
    {
        $this->invoice($this->customer('Karachi Textiles'), 100_000, ['project_id' => null]);

        $payload = $this->report();

        $this->assertSame('100,000', $this->row($payload, 'By project · No project')[4]);
        $this->assertStringContainsString('100,000 INVOICED AGAINST NO PROJECT', $payload['note']);
    }

    /**
     * A line with no product is a row, not a gap.
     *
     * Services and one-offs are typed straight onto invoices, and dropping those lines would make the product
     * grouping quietly fail to add up to the customer grouping with nothing on screen to say why.
     */
    public function test_a_line_with_no_product_is_named(): void
    {
        $invoice = $this->invoice($this->customer('Karachi Textiles'), 100_000);
        $this->line($invoice, 60_000, $this->product('Widget'));
        $this->line($invoice, 40_000);

        $payload = $this->report();

        $this->assertSame('60,000', $this->row($payload, 'By product · Widget')[4]);
        $this->assertSame('40,000', $this->row($payload, 'By product · Not a product')[4]);
    }

    // ─────────────────────────────────────────────────────── the period ──

    /**
     * The financial year, not the calendar year.
     *
     * Read in February, a calendar-year window would start on 1 January and drop the first seven months of
     * the company's invoicing.
     */
    public function test_the_period_is_the_financial_year(): void
    {
        $customer = $this->customer('Karachi Textiles');
        $this->invoice($customer, 100_000, ['invoice_date' => '2026-08-10']);
        $this->invoice($customer, 55_000, ['invoice_date' => '2026-06-10']);

        $payload = $this->report();

        $this->assertStringContainsString('2026-07-01', $payload['subtitle']);
        $this->assertSame(100_000.0, $payload['tiles'][0]['value']);
    }

    /** Nothing invoiced is a sentence, not an empty grid. */
    public function test_it_says_when_nothing_was_invoiced(): void
    {
        $payload = $this->report();

        $this->assertSame([], $payload['rows']);
        $this->assertSame('NOTHING WAS INVOICED IN THIS PERIOD', $payload['note']);
    }

    // ──────────────────────────────────────────── the page and the pane ──

    /** Two screens, one payload. */
    public function test_the_report_states_the_same_thing_on_its_page_as_in_the_pane(): void
    {
        Gate::before(fn () => true);
        $this->invoice($this->customer('Karachi Textiles'), 100_000);

        $onThePage = Livewire::test(RevenueByDimension::class, ['asOf' => self::AS_OF])
            ->assertSuccessful()
            ->instance()
            ->statement();

        $this->assertSame($onThePage, app(ReportPaneRenderer::class)->for('RevenueByDimension', self::AS_OF, false, []));
    }

    /** And on `ReportView`. */
    public function test_the_report_is_gated_on_report_view(): void
    {
        $this->assertFalse(RevenueByDimension::canAccess());
    }
}
