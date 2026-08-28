<?php

namespace Tests\Feature;

use App\Modules\Accounting\Models\Account;
use App\Modules\Core\Services\CsvImportService;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Services\InventoryValuationService;
use App\Modules\Inventory\Support\OpeningStockCsvImporter;
use App\Modules\Invoicing\Models\Contact;
use App\Modules\Invoicing\Models\Invoice;
use App\Modules\Invoicing\Services\ControlReconciliation;
use App\Modules\Invoicing\Services\InvoiceService;
use App\Support\LedgerControls;
use Illuminate\Support\Carbon;
use Tests\AccountingTestCase;
use Tests\Concerns\InteractsWithTenant;

/**
 * The documents behind the opening balances — `docs/erpnext-gap-plan.md` Phase 5.
 *
 * `OpeningBalanceCsvImporter` brings in the trial balance, which carries the receivables *total* and the
 * inventory *value*. Neither of those is a document: ageing iterated `Invoice` rows and found none, so a
 * company with 4m outstanding was shown as owed nothing, and the valuation engine had a shelf it thought was
 * empty while 1300 said otherwise.
 *
 * The two assertions worth reading:
 *
 *  - **`test_the_imported_invoices_agree_with_the_receivables_they_came_from`** — the property that makes
 *    this import correct rather than merely convenient. The invoices post nothing, because the trial balance
 *    already did; what proves they were entered right is Phase 2's control check going quiet.
 *  - **`test_opening_stock_is_a_lot_the_valuation_engine_can_consume`** — the shelf and the ledger are two
 *    different stores of the same fact, and the import fills the one the trial balance cannot reach.
 */
class OpeningDocumentImportTest extends AccountingTestCase
{
    use InteractsWithTenant;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-08-13 10:00:00');

        $this->actingAs($this->makeUser('Administrator', 'opening@test.local'));
        $this->setCurrentTenant();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    // ─────────────────────────────────────────────── opening invoices ──

    /** An imported invoice is an issued document with a balance, and it posts nothing. */
    public function test_an_opening_invoice_is_issued_and_unposted(): void
    {
        Contact::create(['name' => 'Acme Traders', 'kind' => 'customer']);

        $written = $this->import(self::INVOICES, <<<'CSV'
        invoice_number,contact_name,kind,invoice_date,due_date,outstanding
        INV-OLD-1,Acme Traders,sale,2026-05-14,2026-06-13,250000.00
        CSV);

        $this->assertSame(1, $written);

        $invoice = Invoice::where('invoice_number', 'INV-OLD-1')->firstOrFail();

        $this->assertSame(Invoice::KIND_SALE, $invoice->kind);
        $this->assertSame(Invoice::STATUS_ISSUED, $invoice->status);
        $this->assertNull($invoice->journal_entry_id);
        $this->assertSame(250_000.0, $invoice->outstanding());
        $this->assertSame('2026-06-13', $invoice->due_date->toDateString());
        $this->assertCount(1, $invoice->lines);
    }

    /** It ages by its own date, which is the reason each row carries one. */
    public function test_an_opening_invoice_ages_by_its_own_date(): void
    {
        Contact::create(['name' => 'Acme Traders', 'kind' => 'customer']);

        $this->import(self::INVOICES, <<<'CSV'
        invoice_number,contact_name,kind,invoice_date,due_date,outstanding
        INV-OLD-1,Acme Traders,sale,2026-01-10,2026-02-09,100000.00
        INV-OLD-2,Acme Traders,sale,2026-07-20,2026-08-19,50000.00
        CSV);

        $ageing = app(InvoiceService::class)->outstandingReceivables('2026-08-13');

        $this->assertSame(150_000.0, round((float) $ageing['total'], 2));
        // One in the oldest bucket, one still current — which a single "as at" date could not express.
        $this->assertSame(100_000.0, round((float) $ageing['buckets']['90+'], 2));
        $this->assertSame(50_000.0, round((float) $ageing['buckets']['current'], 2));
    }

    /**
     * And the control check is what says they were entered right.
     *
     * The trial balance import puts the receivables total in 1250; these invoices supply the same total on
     * the documents side. A company whose invoices do not add up to what it imported hears about it from
     * `LedgerControlsCheck` rather than at the year end — which is the whole reason this import posts nothing.
     */
    public function test_the_imported_invoices_agree_with_the_receivables_they_came_from(): void
    {
        ControlReconciliation::register();
        Contact::create(['name' => 'Acme Traders', 'kind' => 'customer']);

        // The opening trial balance: 300,000 of receivables, balanced by Opening Balance Equity.
        $this->import('opening_balances', <<<'CSV'
        account_code,debit,credit
        1250,300000.00,
        CSV, '2026-06-30');

        $this->import(self::INVOICES, <<<'CSV'
        invoice_number,contact_name,kind,invoice_date,due_date,outstanding
        INV-OLD-1,Acme Traders,sale,2026-05-14,2026-06-13,250000.00
        INV-OLD-2,Acme Traders,sale,2026-06-02,2026-07-02,50000.00
        CSV);

        $receivables = collect(LedgerControls::compare())->firstWhere('label', 'Receivables');

        $this->assertSame(300_000.0, $receivables['ledger']);
        $this->assertSame(300_000.0, $receivables['documents']);
        $this->assertSame(0.0, $receivables['difference']);
    }

    /** A bill imports the same way, onto the payables side. */
    public function test_an_opening_bill_lands_in_payables(): void
    {
        Contact::create(['name' => 'A supplier', 'kind' => 'supplier']);

        $this->import(self::INVOICES, <<<'CSV'
        invoice_number,contact_name,kind,invoice_date,due_date,outstanding
        BILL-OLD-1,A supplier,purchase,2026-05-14,2026-06-13,80000.00
        CSV);

        $this->assertSame(
            80_000.0,
            round((float) app(InvoiceService::class)->outstandingPayables('2026-08-13')['total'], 2),
        );
    }

    /** Re-running the file corrects the figure rather than doubling it. */
    public function test_re_running_the_file_corrects_rather_than_duplicates(): void
    {
        Contact::create(['name' => 'Acme Traders', 'kind' => 'customer']);

        $this->import(self::INVOICES, <<<'CSV'
        invoice_number,contact_name,kind,invoice_date,due_date,outstanding
        INV-OLD-1,Acme Traders,sale,2026-05-14,2026-06-13,250000.00
        CSV);

        $this->import(self::INVOICES, <<<'CSV'
        invoice_number,contact_name,kind,invoice_date,due_date,outstanding
        INV-OLD-1,Acme Traders,sale,2026-05-14,2026-06-13,190000.00
        CSV);

        $this->assertSame(1, Invoice::where('invoice_number', 'INV-OLD-1')->count());
        $this->assertSame(190_000.0, Invoice::where('invoice_number', 'INV-OLD-1')->firstOrFail()->outstanding());
        $this->assertSame(1, Invoice::where('invoice_number', 'INV-OLD-1')->firstOrFail()->lines()->count());
    }

    /**
     * An invoice that has actually posted here is never overwritten by an import.
     *
     * The one guard that matters on a re-runnable import: a file with a number that collides with a real
     * document must not rewrite a row that is in the ledger.
     */
    public function test_a_posted_invoice_is_never_overwritten(): void
    {
        $contact = Contact::create(['name' => 'Acme Traders', 'kind' => 'customer']);

        $invoice = Invoice::create([
            'kind' => Invoice::KIND_SALE,
            'contact_id' => $contact->getKey(),
            'invoice_date' => '2026-08-01',
            'due_date' => '2026-08-31',
        ]);
        $invoice->lines()->create(['description' => 'Real work', 'quantity' => 1, 'unit_price' => 500, 'line_total' => 500]);
        $invoice->forceFill(['subtotal' => 500, 'tax_amount' => 0, 'total' => 500])->save();
        app(InvoiceService::class)->issue($invoice);

        $preview = $this->preview(self::INVOICES, <<<CSV
        invoice_number,contact_name,kind,invoice_date,due_date,outstanding
        {$invoice->invoice_number},Acme Traders,sale,2026-05-14,2026-06-13,250000.00
        CSV);

        $this->assertSame(1, $preview['skipped']);
        $this->assertStringContainsString('already posted', $this->firstProblem($preview));
        $this->assertSame(500.0, (float) $invoice->refresh()->total);
    }

    /** A row naming a contact nobody has imported is skipped, and says what to do. */
    public function test_a_row_with_an_unknown_contact_is_skipped(): void
    {
        $preview = $this->preview(self::INVOICES, <<<'CSV'
        invoice_number,contact_name,kind,invoice_date,due_date,outstanding
        INV-OLD-1,Nobody Ltd,sale,2026-05-14,2026-06-13,250000.00
        CSV);

        $this->assertSame(1, $preview['skipped']);
        $this->assertStringContainsString('import your contacts first', $this->firstProblem($preview));
    }

    /** As is one with nothing left to pay: that is history, not a balance. */
    public function test_a_row_with_nothing_outstanding_is_skipped(): void
    {
        Contact::create(['name' => 'Acme Traders', 'kind' => 'customer']);

        $preview = $this->preview(self::INVOICES, <<<'CSV'
        invoice_number,contact_name,kind,invoice_date,due_date,outstanding
        INV-OLD-1,Acme Traders,sale,2026-05-14,2026-06-13,0
        CSV);

        $this->assertSame(1, $preview['skipped']);
        $this->assertStringContainsString('nothing outstanding', $this->firstProblem($preview));
    }

    // ──────────────────────────────────────────────────── opening stock ──

    /**
     * Opening stock is a lot, so the next sale has a cost to take.
     *
     * Before this, a company that imported its trial balance had inventory value in 1300 and no lots: the
     * valuation engine took cost from nothing, and every sale's COGS was zero.
     */
    public function test_opening_stock_is_a_lot_the_valuation_engine_can_consume(): void
    {
        $product = Product::create(['sku' => 'WIDGET-01', 'name' => 'A widget', 'unit' => 'pcs', 'is_active' => true]);

        $written = $this->import(self::STOCK, <<<'CSV'
        sku,quantity,unit_cost,location
        WIDGET-01,120,450.00,
        CSV, '2026-06-30');

        $this->assertSame(1, $written);

        $movement = $product->movements()->firstOrFail();

        $this->assertSame('120.00', (string) $movement->quantity);
        $this->assertSame('450.00', (string) $movement->unit_cost);
        $this->assertSame('120.00', (string) $movement->remaining_quantity);
        $this->assertSame('2026-06-30', $movement->movement_date->toDateString());
        $this->assertSame(OpeningStockCsvImporter::REFERENCE, $movement->reference);

        // Nothing posted: the value is already in 1300 from the trial balance.
        $this->assertNull($movement->journal_entry_id);

        // And the engine can cost a sale out of it.
        $this->assertSame(4_500.0, round(app(InventoryValuationService::class)->costOfSale($product->refresh(), 10), 2));
    }

    /** Re-running replaces the opening lot rather than adding a second one. */
    public function test_re_running_the_stock_file_replaces_the_opening_lot(): void
    {
        $product = Product::create(['sku' => 'WIDGET-01', 'name' => 'A widget', 'unit' => 'pcs', 'is_active' => true]);

        $this->import(self::STOCK, "sku,quantity,unit_cost,location\nWIDGET-01,120,450.00,", '2026-06-30');
        $this->import(self::STOCK, "sku,quantity,unit_cost,location\nWIDGET-01,90,450.00,", '2026-06-30');

        $this->assertSame(1, $product->movements()->count());
        $this->assertSame('90.00', (string) $product->movements()->firstOrFail()->quantity);
    }

    /**
     * And it never touches a movement a real receipt or sale created.
     *
     * The replacement above is scoped to this importer's own reference and to unposted rows, because an
     * import correcting the opening position must not be able to delete the history since.
     */
    public function test_re_running_leaves_real_movements_alone(): void
    {
        $product = Product::create(['sku' => 'WIDGET-01', 'name' => 'A widget', 'unit' => 'pcs', 'is_active' => true]);

        $this->import(self::STOCK, "sku,quantity,unit_cost,location\nWIDGET-01,120,450.00,", '2026-06-30');

        // A genuine receipt afterwards, which posts.
        app(\App\Modules\Inventory\Services\InventoryService::class)
            ->purchase($product, 30, 500, '2026-07-15', 'PO-1');

        $this->import(self::STOCK, "sku,quantity,unit_cost,location\nWIDGET-01,110,450.00,", '2026-06-30');

        $this->assertSame(2, $product->movements()->count());
        $this->assertSame(1, $product->movements()->whereNotNull('journal_entry_id')->count());
        $this->assertSame(
            '110.00',
            (string) $product->movements()->where('reference', OpeningStockCsvImporter::REFERENCE)->firstOrFail()->quantity,
        );
    }

    /** A row naming a product nobody has created is skipped, and says what to do. */
    public function test_a_stock_row_with_an_unknown_sku_is_skipped(): void
    {
        $preview = $this->preview(self::STOCK, "sku,quantity,unit_cost,location\nNOPE-01,10,100.00,");

        $this->assertSame(1, $preview['skipped']);
        $this->assertStringContainsString('import or create your products first', $this->firstProblem($preview));
    }

    /** A blank cost is skipped, because stock valued at nothing takes every future sale's cost from nothing. */
    public function test_a_stock_row_with_no_cost_is_skipped(): void
    {
        Product::create(['sku' => 'WIDGET-01', 'name' => 'A widget', 'unit' => 'pcs', 'is_active' => true]);

        $preview = $this->preview(self::STOCK, "sku,quantity,unit_cost,location\nWIDGET-01,10,,");

        $this->assertSame(1, $preview['skipped']);
        $this->assertStringContainsString('values the shelf at nothing', $this->firstProblem($preview));
    }

    // ───────────────────────────────────────────────────── fixtures ──

    private const INVOICES = 'opening_invoices';

    private const STOCK = 'opening_stock';

    private function import(string $type, string $csv, ?string $date = null): int
    {
        return (int) app(CsvImportService::class)->import($this->dedent($csv), $type, $date)['imported'];
    }

    /** @return array<string, mixed> */
    private function preview(string $type, string $csv): array
    {
        return app(CsvImportService::class)->preview($this->dedent($csv), $type);
    }

    /** What the preview said was wrong with the first skipped row. */
    private function firstProblem(array $preview): string
    {
        foreach ($preview['rows'] as $row) {
            if ($row['_problem'] !== null) {
                return (string) $row['_problem'];
            }
        }

        return '';
    }

    /** Heredocs in a method body are indented; the reader is not. */
    private function dedent(string $csv): string
    {
        return implode("\n", array_map('trim', explode("\n", trim($csv))));
    }

    private function accountId(string $code): int
    {
        return Account::where('code', $code)->firstOrFail()->id;
    }
}
