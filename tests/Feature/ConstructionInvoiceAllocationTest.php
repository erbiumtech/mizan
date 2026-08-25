<?php

namespace Tests\Feature;

use App\Modules\Construction\Models\CostCode;
use App\Modules\Construction\Models\Job;
use App\Modules\ConstructionCosting\Filament\Pages\InvoiceAllocationQueue;
use App\Modules\ConstructionCosting\Models\Commitment;
use App\Modules\ConstructionCosting\Models\CommitmentLine;
use App\Modules\ConstructionCosting\Models\CommitmentRelief;
use App\Modules\ConstructionCosting\Models\CostEntry;
use App\Modules\ConstructionCosting\Models\InvoiceAllocation;
use App\Modules\ConstructionCosting\Services\CommitmentService;
use App\Modules\ConstructionCosting\Services\CostLedger;
use App\Modules\ConstructionCosting\Services\GoodsReceiptService;
use App\Modules\ConstructionCosting\Services\InvoiceAllocationService;
use App\Modules\Core\Models\CompanyModule;
use App\Modules\Invoicing\Models\Contact;
use App\Modules\Invoicing\Models\Invoice;
use Filament\Actions\Testing\TestAction;
use InvalidArgumentException;
use Livewire\Livewire;
use RuntimeException;
use Tests\AccountingTestCase;
use Tests\Concerns\InteractsWithTenant;

/**
 * Attributing a supplier invoice to jobs and cost codes — `docs/construction-management-plan.md` §5, Phase 5d.
 *
 * **§5 calls the failure this answers the single most likely silent one in the module**: a purchase invoice posted with
 * no allocation leaves the general ledger perfectly correct and the job under-costed, so every margin flatters and no
 * report shows an error. The answer is structural rather than procedural — the queue, which this file tests as a
 * screen, and §4.2's always-rendered reconciliation section, which Phase 11 builds.
 *
 * Four properties carry the file:
 *
 *  - **One invoice line splits across jobs and codes**, which is the entire reason allocations are a table. Columns
 *    would force a 1:1 and the workaround — splitting the invoice line — makes the document this application prints
 *    disagree with the one the supplier sent.
 *  - **The invoice relieves only the unreceived balance**, so a receipt and an invoice for the same goods relieve once
 *    between them.
 *  - **The cost mirrors the ledger** rather than pending for it: the purchase invoice is what reaches the GL, and §4's
 *    chase-list must not carry an entry that is already there.
 *  - **The receipt's accrual is left standing**, per §4.5, and this file asserts that deliberately rather than
 *    treating it as an oversight.
 */
class ConstructionInvoiceAllocationTest extends AccountingTestCase
{
    use InteractsWithTenant;

    private Job $job;

    private Job $annexe;

    private CostCode $material;

    private CostCode $labour;

    private InvoiceAllocationService $allocations;

    private CommitmentService $commitments;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs($this->makeUser('Administrator', 'allocations@test.local'));
        $this->setCurrentTenant();

        foreach (['construction', 'construction_costing', 'accounting', 'invoicing'] as $module) {
            CompanyModule::updateOrCreate(
                ['company_id' => $this->tenant->getKey(), 'module' => $module],
                ['licensed' => true, 'enabled' => true],
            );
        }
        modules()->flush();

        $this->job = Job::create(['code' => 'J-1', 'name' => 'Tower']);
        $this->annexe = Job::create(['code' => 'J-2', 'name' => 'Annexe']);
        $this->material = CostCode::create([
            'code' => '03.100', 'name' => 'Reinforcement', 'cost_type' => CostCode::TYPE_MATERIAL, 'unit' => 't',
        ]);
        $this->labour = CostCode::create([
            'code' => '02.100', 'name' => 'Steel fixing', 'cost_type' => CostCode::TYPE_LABOUR, 'unit' => 't',
        ]);

        $this->allocations = app(InvoiceAllocationService::class);
        $this->commitments = app(CommitmentService::class);
    }

    /** A supplier bill for 3,000,000 net on one narrative line. */
    private function bill(float $net = 3_000_000, string $kind = Invoice::KIND_PURCHASE): Invoice
    {
        $supplier = Contact::firstOrCreate(['name' => 'Steel Supplier Ltd'], ['type' => 'supplier']);

        $invoice = Invoice::create([
            'kind' => $kind,
            'status' => Invoice::STATUS_DRAFT,
            'contact_id' => $supplier->getKey(),
            'invoice_date' => '2026-08-20',
            'subtotal' => $net,
            'tax_amount' => 0,
            'total' => $net,
        ]);

        $invoice->lines()->create([
            'description' => 'Rebar, high tensile, 16mm — 12 t',
            'quantity' => 12,
            'unit_price' => $net / 12,
            'line_total' => $net,
        ]);

        return $invoice->refresh();
    }

    /** An issued order for 40 t at 250,000 against the tower. */
    private function issuedOrder(): Commitment
    {
        $commitment = $this->commitments->create();

        $this->commitments->addLine($commitment, $this->job, $this->material, [
            'description' => 'Rebar, 40 t',
            'quantity' => 40,
            'rate' => 250_000,
        ]);

        $this->commitments->approve($commitment->refresh());

        return $this->commitments->issue($commitment->refresh());
    }

    private function orderLine(Commitment $commitment): CommitmentLine
    {
        return $commitment->lines()->orderBy('id')->firstOrFail();
    }

    // ------------------------------------------------------------------ allocating

    /**
     * **The exit condition of this sub-phase.** One invoice line, three allocations, two jobs.
     *
     * The whole reason allocations are their own table: columns on `invoice_lines` would force a 1:1, and splitting the
     * invoice line to work around it makes the printed document disagree with what the supplier sent.
     */
    public function test_one_invoice_line_splits_across_jobs_and_cost_codes(): void
    {
        $invoice = $this->bill(3_000_000);
        $line = $invoice->lines->first();

        $this->allocations->allocate($invoice, $this->job, $this->material, 1_500_000, ['invoice_line_id' => $line->getKey()]);
        $this->allocations->allocate($invoice, $this->job, $this->labour, 1_000_000, ['invoice_line_id' => $line->getKey()]);
        $this->allocations->allocate($invoice, $this->annexe, $this->material, 500_000, ['invoice_line_id' => $line->getKey()]);

        $this->assertSame(3, InvoiceAllocation::query()->count());
        $this->assertSame(0.0, $this->allocations->unallocated($invoice->refresh()));
        $this->assertTrue($this->allocations->isFullyAllocated($invoice));

        // And the cost landed where it was told to, on two jobs and two codes.
        $this->assertSame(2_500_000.0, app(CostLedger::class)->totalFor($this->job));
        $this->assertSame(500_000.0, app(CostLedger::class)->totalFor($this->annexe));

        // The supplier's own line is untouched — it still says what the supplier sent.
        $this->assertSame(1, $invoice->refresh()->lines->count());
        $this->assertEquals(3_000_000, $invoice->lines->first()->line_total);
    }

    /**
     * The cost entry **mirrors** the ledger rather than pending for it.
     *
     * The purchase invoice is what reaches the general ledger; this is the same money seen from the job's side. Marked
     * `pending` it would sit on §4's chase-list forever, which is the list of cost that *should* have posted and has
     * not.
     */
    public function test_the_cost_entry_mirrors_the_ledger(): void
    {
        $invoice = $this->bill(1_000_000);
        $allocation = $this->allocations->allocate($invoice, $this->job, $this->material, 1_000_000);

        $entry = CostEntry::query()->firstOrFail();

        $this->assertSame(CostEntry::KIND_ACTUAL, $entry->kind);
        $this->assertSame(CostEntry::GL_MIRRORED, $entry->gl_treatment);
        $this->assertSame($invoice->invoice_number, $entry->reference);
        $this->assertSame('2026-08-20', $entry->incurred_on->toDateString(), "the invoice's own date");
        $this->assertSame($entry->getKey(), $allocation->refresh()->cost_entry_id, 'traceable both ways');
    }

    /** Tax is not job cost: the unallocated figure is the net, so a gross allocation cannot balance to zero. */
    public function test_the_unallocated_figure_is_net_of_tax(): void
    {
        $invoice = $this->bill(1_000_000);
        $invoice->update(['tax_amount' => 170_000, 'total' => 1_170_000]);

        $this->assertSame(1_000_000.0, $this->allocations->unallocated($invoice->refresh()));

        $this->allocations->allocate($invoice, $this->job, $this->material, 1_000_000);

        $this->assertTrue($this->allocations->isFullyAllocated($invoice->refresh()));
    }

    /** A credit note allocates negative, and job cost falls by it. */
    public function test_a_credit_note_allocates_negative(): void
    {
        $credit = $this->bill(-250_000);

        $this->allocations->allocate($credit, $this->job, $this->material, -250_000);

        $this->assertSame(-250_000.0, app(CostLedger::class)->totalFor($this->job));
        $this->assertTrue(InvoiceAllocation::query()->firstOrFail()->isCredit());
    }

    /**
     * **Over-allocation is refused**, and it is the refusal that protects a figure rather than a convention.
     *
     * Allocating 120 of a 100 invoice puts cost on a job that no supplier ever charged, and the general ledger would
     * not disagree — it never sees the allocation at all.
     */
    public function test_over_allocation_is_refused(): void
    {
        $invoice = $this->bill(1_000_000);
        $this->allocations->allocate($invoice, $this->job, $this->material, 800_000);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('never charged');

        $this->allocations->allocate($invoice->refresh(), $this->job, $this->labour, 300_000);
    }

    public function test_a_heading_code_cannot_take_an_allocation(): void
    {
        $heading = CostCode::create(['code' => '03', 'name' => 'Concrete', 'cost_type' => CostCode::TYPE_MATERIAL]);
        $this->material->update(['parent_id' => $heading->getKey()]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('is a heading');

        $this->allocations->allocate($this->bill(), $this->job, $heading->refresh(), 100_000);
    }

    /** A sales invoice carries revenue, and §10 certifies that rather than allocating it. */
    public function test_a_sales_invoice_cannot_be_allocated(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Only a purchase invoice');

        $this->allocations->allocate($this->bill(1_000_000, Invoice::KIND_SALE), $this->job, $this->material, 1_000_000);
    }

    public function test_allocating_nothing_is_refused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('tells nobody anything');

        $this->allocations->allocate($this->bill(), $this->job, $this->material, 0);
    }

    // ------------------------------------------------------- relief and the accrual

    /**
     * **§5's double-relief rule, from the invoice side.**
     *
     * The receipt relieved 20 t when the goods arrived; the invoice for the whole 40 t may relieve only the 20 t that
     * never arrived. Between them the order is relieved once.
     */
    public function test_an_invoice_relieves_only_what_the_delivery_did_not(): void
    {
        $order = $this->issuedOrder();
        $orderLine = $this->orderLine($order);

        // 20 t delivered and posted: 5,000,000 relieved.
        $receipts = app(GoodsReceiptService::class);
        $receipt = $receipts->create($order);
        $receipts->addLineFor($receipt, $orderLine, 20);
        $receipts->post($receipt->refresh());

        $this->assertSame(5_000_000.0, $this->commitments->openFor($this->job, $this->material->getKey()));

        // The supplier invoices the whole order.
        $invoice = $this->bill(10_000_000);
        $this->allocations->allocate($invoice, $this->job, $this->material, 10_000_000, [
            'commitment_line_id' => $orderLine->getKey(),
        ]);

        $orderLine->refresh();

        $this->assertSame(5_000_000.0, $orderLine->receivedTotal(), 'what the delivery relieved');
        $this->assertSame(5_000_000.0, $orderLine->invoicedTotal(), 'and the invoice relieved only the rest');
        $this->assertSame(0.0, $orderLine->openAmount(), 'once between them, not twice');
    }

    /** An invoice for goods already fully received relieves nothing at all, which is correct rather than a fault. */
    public function test_an_invoice_for_delivered_goods_relieves_nothing(): void
    {
        $order = $this->issuedOrder();
        $orderLine = $this->orderLine($order);

        $receipts = app(GoodsReceiptService::class);
        $receipt = $receipts->create($order);
        $receipts->addLineFor($receipt, $orderLine, 40);
        $receipts->post($receipt->refresh());

        $invoice = $this->bill(10_000_000);
        $this->allocations->allocate($invoice, $this->job, $this->material, 10_000_000, [
            'commitment_line_id' => $orderLine->getKey(),
        ]);

        $this->assertSame(0.0, $orderLine->refresh()->invoicedTotal());
        $this->assertSame(
            1,
            $orderLine->reliefs()->where('kind', CommitmentRelief::KIND_RECEIPT)->count(),
            'one relief, from the receipt',
        );
    }

    /**
     * **The receipt's accrual is left standing, deliberately** — §4.5.
     *
     * Accruals auto-reverse at the opening of the next period rather than being matched off against the eventual
     * invoice, "because matching an accrual line-by-line to a later invoice is the same heuristic that fails for
     * commitment relief, and an accrual that fails to match sits on the balance sheet forever with nobody able to say
     * what it is for".
     *
     * The consequence inside one period — the accrual and the invoice both standing — is named in §4.5 as the reason
     * the reversal belongs to period *open*. This test exists so that behaviour is a decision on the record rather
     * than something a later reader takes for a bug.
     */
    public function test_the_delivery_accrual_is_not_matched_off_against_the_invoice(): void
    {
        $order = $this->issuedOrder();
        $orderLine = $this->orderLine($order);

        $receipts = app(GoodsReceiptService::class);
        $receipt = $receipts->create($order);
        $receipts->addLineFor($receipt, $orderLine, 20);
        $receipts->post($receipt->refresh());

        $invoice = $this->bill(5_000_000);
        $this->allocations->allocate($invoice, $this->job, $this->material, 5_000_000, [
            'commitment_line_id' => $orderLine->getKey(),
        ]);

        $row = collect(app(CostLedger::class)->fourColumnReport($this->job))->firstWhere('code', '03.100');

        $this->assertSame(5_000_000.0, $row['accrued'], 'the accrual stands until the period-open reversal');
        $this->assertSame(5_000_000.0, $row['actual'], 'and the invoice is actual cost');
        $this->assertSame(
            2,
            CostEntry::query()->count(),
            'two entries inside one period, which §4.5 accepts and Phase 11 reverses at period open',
        );
    }

    // ------------------------------------------------------------------ withdrawing

    /** Withdrawing reverses the cost rather than deleting it, and gives the commitment back. */
    public function test_withdrawing_reverses_the_cost_and_returns_the_commitment(): void
    {
        $order = $this->issuedOrder();
        $orderLine = $this->orderLine($order);
        $invoice = $this->bill(10_000_000);

        $allocation = $this->allocations->allocate($invoice, $this->job, $this->material, 10_000_000, [
            'commitment_line_id' => $orderLine->getKey(),
        ]);

        $this->assertSame(0.0, $this->commitments->openFor($this->job, $this->material->getKey()));

        $this->allocations->deallocate($allocation, 'Coded to the wrong job.');

        $this->assertSame(0, InvoiceAllocation::query()->count(), 'the attribution goes');
        $this->assertSame(2, CostEntry::query()->count(), 'the cost and its reversal both stay');
        $this->assertSame(0.0, app(CostLedger::class)->totalFor($this->job), 'netting to nothing');
        $this->assertSame(10_000_000.0, $this->commitments->openFor($this->job, $this->material->getKey()));

        // And the invoice is back on the queue, which is the point.
        $this->assertFalse($this->allocations->isFullyAllocated($invoice->refresh()));
    }

    // ------------------------------------------------------------------ the queue

    public function test_the_queue_lists_unallocated_purchase_invoices_oldest_first(): void
    {
        $older = $this->bill(1_000_000);
        $older->update(['invoice_date' => '2026-07-01']);

        $newer = $this->bill(2_000_000);
        $newer->update(['invoice_date' => '2026-08-20']);

        $allocated = $this->bill(500_000);
        $this->allocations->allocate($allocated, $this->job, $this->material, 500_000);

        $queue = $this->allocations->awaitingAllocation();

        $this->assertSame(
            [$older->invoice_number, $newer->invoice_number],
            $queue->pluck('invoice_number')->all(),
            'oldest first, and the fully allocated one has left',
        );
        $this->assertSame(3_000_000.0, $this->allocations->awaitingTotal());
    }

    /** A part-allocated invoice stays on the queue for the remainder. */
    public function test_a_part_allocated_invoice_stays_on_the_queue(): void
    {
        $invoice = $this->bill(1_000_000);
        $this->allocations->allocate($invoice, $this->job, $this->material, 400_000);

        $this->assertSame(600_000.0, $this->allocations->unallocated($invoice->refresh()));
        $this->assertCount(1, $this->allocations->awaitingAllocation());
    }

    /** Sales invoices are not on it: there is nothing to attribute. */
    public function test_sales_invoices_are_not_on_the_queue(): void
    {
        $this->bill(1_000_000, Invoice::KIND_SALE);

        $this->assertCount(0, $this->allocations->awaitingAllocation());
    }

    // ------------------------------------------------------------------ the screen

    public function test_the_queue_screen_shows_the_invoice_and_what_is_unallocated(): void
    {
        $invoice = $this->bill(3_000_000);

        Livewire::test(InvoiceAllocationQueue::class)
            ->assertSuccessful()
            ->assertSee($invoice->invoice_number)
            ->assertSee('3,000,000.00')
            ->assertSee('Still unallocated');
    }

    /**
     * **Two invoices, two allocations — because one of each proves nothing here.**
     *
     * The test above renders the same screen and cannot catch a missing eager load, and the reason is not that it
     * forgot to look: `Builder::hydrate()` only stamps `preventsLazyLoading` onto the models it builds when the query
     * returned *more than one row*. A queue holding a single invoice is a queue where every lazy read is legal, so the
     * supplier name in the section heading and the order number in the allocations table were both one query per row
     * in front of a guard that had been switched off by the size of the fixture.
     *
     * So the fixture is the assertion. Two invoices to arm the guard over the queue itself, and two allocations on one
     * of them to arm it over `allocationsOn()` — which reaches two relations deeper, through the commitment line to
     * the order it belongs to. Part-allocated deliberately: fully allocated, the invoice leaves the queue and takes
     * the allocations table with it.
     */
    public function test_the_queue_screen_eager_loads_what_it_renders(): void
    {
        $order = $this->issuedOrder();
        $orderLine = $this->orderLine($order);

        // 5,000,000 of 10,000,000 allocated, on two rows: one against the order, one without.
        $invoice = $this->bill(10_000_000);
        $this->allocations->allocate($invoice, $this->job, $this->material, 4_000_000, [
            'commitment_line_id' => $orderLine->getKey(),
        ]);
        $this->allocations->allocate($invoice, $this->annexe, $this->labour, 1_000_000);

        // A second, untouched invoice: this is what arms the guard over the queue query.
        $this->bill(3_000_000);

        Livewire::test(InvoiceAllocationQueue::class)
            ->assertSuccessful()
            // The heading's supplier name — `$invoice->contact`, and nothing else on the page shows it.
            ->assertSee('Steel Supplier Ltd')
            // The allocations table, reached through `commitmentLine.commitment`, and its unordered row.
            ->assertSee($order->number)
            ->assertSee('Unordered')
            ->assertSee('5,000,000.00');
    }

    /**
     * **Empty is a result, not a blank page.**
     *
     * §4.2 asks for the unallocated section to be rendered even when empty, and the same reasoning applies to the
     * queue: a screen that looks broken when it is finished is a screen people stop opening.
     */
    public function test_an_empty_queue_says_so_in_words(): void
    {
        Livewire::test(InvoiceAllocationQueue::class)
            ->assertSuccessful()
            ->assertSee('Nothing awaiting allocation')
            ->assertSee('under-costed');
    }

    public function test_the_screen_allocates(): void
    {
        $invoice = $this->bill(1_000_000);

        /*
         * Mounted first so the repeater's own item key can be read back.
         *
         * A Filament repeater keys its items by a generated uuid, and `minItems(1)` means one empty row exists the
         * moment the modal opens. Passing a row under a key of our own leaves that empty row in place — it fails
         * validation while our data is ignored, which reads as "the action did not save" rather than as a test
         * filling the wrong field. So the generated key is what gets filled.
         */
        $screen = Livewire::test(InvoiceAllocationQueue::class)
            ->mountAction(TestAction::make('allocate')->arguments(['invoice' => $invoice->getKey()]));

        $rowKey = array_key_first($screen->get('mountedActions.0.data.rows'));

        $screen->setActionData(['rows' => [
            $rowKey => [
                'job_id' => $this->job->getKey(),
                'cost_code_id' => $this->material->getKey(),
                'amount' => 1_000_000,
            ],
        ]])
            ->callMountedAction()
            ->assertHasNoActionErrors();

        $this->assertTrue($this->allocations->isFullyAllocated($invoice->refresh()));
        $this->assertSame(1_000_000.0, app(CostLedger::class)->totalFor($this->job));
    }

    /** Without Invoicing there is nothing to allocate, and the service says so rather than half-working. */
    public function test_without_invoicing_allocation_is_unavailable(): void
    {
        $invoice = $this->bill(1_000_000);

        CompanyModule::where('company_id', $this->tenant->getKey())
            ->where('module', 'invoicing')
            ->update(['licensed' => false, 'enabled' => false]);
        modules()->flush();

        $this->assertFalse($this->allocations->isAvailable());
        $this->assertCount(0, $this->allocations->awaitingAllocation());
        $this->assertFalse(InvoiceAllocationQueue::canAccess(), 'and the screen is gone');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('needs the Invoicing module');

        $this->allocations->allocate($invoice, $this->job, $this->material, 1_000_000);
    }
}
