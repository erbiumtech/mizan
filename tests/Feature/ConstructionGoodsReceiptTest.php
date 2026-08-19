<?php

namespace Tests\Feature;

use App\Modules\Construction\Models\CostCode;
use App\Modules\Construction\Models\Job;
use App\Modules\ConstructionCosting\Filament\Resources\GoodsReceipts\Pages\ListGoodsReceipts;
use App\Modules\ConstructionCosting\Models\Commitment;
use App\Modules\ConstructionCosting\Models\CommitmentLine;
use App\Modules\ConstructionCosting\Models\CommitmentRelief;
use App\Modules\ConstructionCosting\Models\CostEntry;
use App\Modules\ConstructionCosting\Models\CostPeriod;
use App\Modules\ConstructionCosting\Models\GoodsReceipt;
use App\Modules\ConstructionCosting\Models\GoodsReceiptLine;
use App\Modules\ConstructionCosting\Services\CommitmentService;
use App\Modules\ConstructionCosting\Services\CostLedger;
use App\Modules\ConstructionCosting\Services\GoodsReceiptService;
use App\Modules\Core\Models\CompanyModule;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\StockLocation;
use App\Modules\Inventory\Models\StockMovement;
use App\Modules\Inventory\Services\InventoryValuationService;
use InvalidArgumentException;
use Livewire\Livewire;
use RuntimeException;
use Tests\AccountingTestCase;
use Tests\Concerns\InteractsWithTenant;

/**
 * Goods receipts — `docs/construction-management-plan.md` §5, Phase 5c.
 *
 * §5: "A goods receipt does three things: relieves the commitment, raises an accrual cost entry at order rate, and —
 * only when the delivery is into a site store rather than straight to the work face, and only when Inventory is
 * licensed — writes a stock movement (§6)."
 *
 * **All three are built as of Phase 8a**, which gave Inventory `stock_locations`. The store path is still *refused*
 * rather than quietly downgraded to direct when anything it needs is missing — the module, a product on the line, or a
 * store on the job — because accepting a store line without a movement would cost the material as though it had been
 * stocked, and materials-on-site would be wrong with nothing saying so. §18.1 names this class of failure: the
 * healthy-looking figure that hides an absence. Each of the three refusals names which thing is missing.
 *
 * The accrual is the other property worth stating. Between delivery and invoice the job has incurred cost no supplier
 * document proves; booking it as `accrual` rather than `actual` keeps §3.5's two columns apart, which is what stops CPI
 * moving when nothing happened on site.
 */
class ConstructionGoodsReceiptTest extends AccountingTestCase
{
    use InteractsWithTenant;

    private Job $job;

    private CostCode $material;

    private CommitmentService $commitments;

    private GoodsReceiptService $receipts;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs($this->makeUser('Administrator', 'receipts@test.local'));
        $this->setCurrentTenant();

        foreach (['construction', 'construction_costing', 'accounting', 'inventory'] as $module) {
            CompanyModule::updateOrCreate(
                ['company_id' => $this->tenant->getKey(), 'module' => $module],
                ['licensed' => true, 'enabled' => true],
            );
        }
        modules()->flush();

        $this->job = Job::create(['code' => 'J-1', 'name' => 'Tower']);
        $this->material = CostCode::create([
            'code' => '03.100', 'name' => 'Reinforcement', 'cost_type' => CostCode::TYPE_MATERIAL, 'unit' => 't',
        ]);

        $this->commitments = app(CommitmentService::class);
        $this->receipts = app(GoodsReceiptService::class);
    }

    /** An issued order for 40 t at 250,000 — 10,000,000 committed. */
    private function issuedOrder(): Commitment
    {
        $commitment = $this->commitments->create();

        $this->commitments->addLine($commitment, $this->job, $this->material, [
            'description' => 'Rebar, high tensile, 16mm',
            'quantity' => 40,
            'unit_of_measure' => 't',
            'rate' => 250_000,
        ]);

        $this->commitments->approve($commitment->refresh());

        return $this->commitments->issue($commitment->refresh());
    }

    private function orderLine(Commitment $commitment): CommitmentLine
    {
        return $commitment->lines()->orderBy('id')->firstOrFail();
    }

    /** A posted receipt for `$quantity` tonnes against that order. */
    private function postedReceipt(Commitment $commitment, float $quantity = 20): GoodsReceipt
    {
        $receipt = $this->receipts->create($commitment, ['delivery_note_reference' => 'DN-88213']);
        $this->receipts->addLineFor($receipt, $this->orderLine($commitment), $quantity);

        return $this->receipts->post($receipt->refresh());
    }

    // ------------------------------------------------------------------ recording

    public function test_a_receipt_is_numbered_and_records_who_signed_for_it(): void
    {
        $year = now()->year;
        $receipt = $this->receipts->create($this->issuedOrder());

        $this->assertSame("GRN-{$year}-0001", $receipt->number);
        $this->assertSame(auth()->id(), $receipt->received_by);
        $this->assertSame(GoodsReceipt::STATUS_DRAFT, $receipt->status);
        $this->assertSame(now()->toDateString(), $receipt->received_on->toDateString());
    }

    /** The supplier comes off the order, so a receipt against an order knows who delivered. */
    public function test_the_supplier_is_taken_from_the_order(): void
    {
        $order = $this->issuedOrder();
        $order->update(['contact_id' => 4242]);

        $this->assertSame(4242, $this->receipts->create($order->refresh())->contact_id);
    }

    /**
     * Receiving against an order line seeds everything from it, **including the rate**.
     *
     * The accrual has to be at order rate for the invoice comparison to mean anything, and a rate typed a second time
     * is a rate that will differ from the first.
     */
    public function test_a_line_received_against_an_order_takes_its_job_code_and_rate(): void
    {
        $order = $this->issuedOrder();
        $receipt = $this->receipts->create($order);

        $line = $this->receipts->addLineFor($receipt, $this->orderLine($order), 20);

        $this->assertSame($this->job->getKey(), $line->job_id);
        $this->assertSame($this->material->getKey(), $line->cost_code_id);
        $this->assertEquals(250_000, $line->unit_rate);
        $this->assertEquals(5_000_000, $line->amount, '20 t at the order rate');
        $this->assertSame(GoodsReceiptLine::DESTINATION_DIRECT, $line->destination, 'direct to site is the default');
    }

    public function test_a_line_needs_a_quantity(): void
    {
        $order = $this->issuedOrder();
        $receipt = $this->receipts->create($order);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('quantity greater than zero');

        $this->receipts->addLineFor($receipt, $this->orderLine($order), 0);
    }

    public function test_a_posted_receipt_refuses_a_new_line(): void
    {
        $order = $this->issuedOrder();
        $receipt = $this->postedReceipt($order);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('record a second delivery');

        $this->receipts->addLineFor($receipt, $this->orderLine($order), 5);
    }

    public function test_an_empty_receipt_cannot_be_posted(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Nothing was delivered');

        $this->receipts->post($this->receipts->create($this->issuedOrder()));
    }

    // -------------------------------------------------- the two things it does

    /**
     * **The exit condition of this sub-phase.** Posting relieves the order and accrues the cost.
     */
    public function test_posting_relieves_the_order_and_accrues_the_cost(): void
    {
        $order = $this->issuedOrder();

        $this->assertSame(10_000_000.0, $this->commitments->openFor($this->job, $this->material->getKey()));

        $receipt = $this->postedReceipt($order, 20);

        // Relieved: 20 t of 40 delivered, so half the commitment is gone.
        $this->assertSame(5_000_000.0, $this->commitments->openFor($this->job, $this->material->getKey()));
        $relief = $this->orderLine($order)->reliefs()->firstOrFail();
        $this->assertSame(CommitmentRelief::KIND_RECEIPT, $relief->kind);
        $this->assertEquals(5_000_000, $relief->amount);

        // Accrued: the cost is on the job, as an accrual rather than as actual cost.
        $entry = CostEntry::query()->firstOrFail();
        $this->assertSame(CostEntry::KIND_ACCRUAL, $entry->kind);
        $this->assertEquals(5_000_000, $entry->amount);
        $this->assertSame($this->material->getKey(), $entry->cost_code_id);
        $this->assertEquals(20, $entry->quantity);
        $this->assertEquals(250_000, $entry->unit_rate);
        $this->assertSame('DN-88213', $entry->reference, 'the delivery note travels onto the cost entry');

        // And the receipt line remembers the entry it raised, which is what makes posting idempotent.
        $this->assertSame($entry->getKey(), $receipt->lines()->firstOrFail()->cost_entry_id);
    }

    /**
     * The accrual lands in the accrued column, not the actual one.
     *
     * §3.5 keeps them apart because an accrual is an estimate of cost incurred and not yet invoiced: mixing it into
     * actual cost makes CPI move when nothing happened on site.
     */
    public function test_the_cost_shows_as_accrued_rather_than_actual(): void
    {
        $this->postedReceipt($this->issuedOrder(), 20);

        $row = collect(app(CostLedger::class)->fourColumnReport($this->job))->firstWhere('code', '03.100');

        $this->assertSame(0.0, $row['actual'], 'nothing is proven by a supplier document yet');
        $this->assertSame(5_000_000.0, $row['accrued']);
        $this->assertSame(5_000_000.0, $row['committed'], 'and half the order is still on order');
    }

    /** Posting twice does nothing the second time: each line remembers the accrual it raised. */
    public function test_posting_is_idempotent(): void
    {
        $order = $this->issuedOrder();
        $receipt = $this->postedReceipt($order, 20);

        try {
            $this->receipts->post($receipt->refresh());
            $this->fail('A second post should be refused.');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('already posted', $e->getMessage());
        }

        $this->assertSame(1, CostEntry::query()->count(), 'one accrual, not two');
        $this->assertSame(1, $this->orderLine($order)->reliefs()->count());
    }

    /** A delivery nobody ordered still gets costed, and relieves nothing because there is nothing to relieve. */
    public function test_an_unordered_delivery_is_costed_and_relieves_nothing(): void
    {
        $receipt = $this->receipts->create(null, ['delivery_note_reference' => 'DN-90001']);

        $this->receipts->addLine($receipt, $this->job, $this->material, [
            'description' => 'Sundry fixings nobody ordered',
            'quantity' => 5,
            'unit_rate' => 10_000,
        ]);

        $this->receipts->post($receipt->refresh());

        $this->assertNull($receipt->refresh()->commitment_id);
        $this->assertEquals(50_000, CostEntry::query()->firstOrFail()->amount);
        $this->assertSame(0, CommitmentRelief::query()->count());
    }

    /**
     * Over-delivery is recorded rather than refused, and shows as over-relief.
     *
     * A supplier sending a full pack instead of the ordered part of one is ordinary; refusing would leave the material
     * on site and uncosted.
     */
    public function test_more_than_was_ordered_is_recorded_and_reads_as_over_relief(): void
    {
        $order = $this->issuedOrder();

        $this->postedReceipt($order, 45);

        $this->assertTrue($order->refresh()->overRelieved());
        $this->assertSame(0.0, $this->commitments->openFor($this->job, $this->material->getKey()));
        $this->assertEquals(11_250_000, CostEntry::query()->firstOrFail()->amount, '45 t at the order rate');
    }

    /**
     * A closed cost period does not lose the delivery: `CostLedger` puts it in the open one and keeps the real date.
     *
     * The rule belongs to the ledger, and receiving through it rather than writing an entry directly is what makes
     * that true here too.
     */
    public function test_a_delivery_into_a_closed_month_keeps_its_date_and_lands_in_the_open_one(): void
    {
        $order = $this->issuedOrder();
        $receipt = $this->receipts->create($order, ['received_on' => '2026-06-15']);
        $this->receipts->addLineFor($receipt, $this->orderLine($order), 10);

        CostPeriod::forDate('2026-06-15')->update(['status' => CostPeriod::STATUS_CLOSED]);

        $this->receipts->post($receipt->refresh());

        $entry = CostEntry::query()->firstOrFail();

        $this->assertSame('2026-06-15', $entry->incurred_on->toDateString(), 'the real date is kept');
        $this->assertTrue($entry->is_late_for_period, 'and it is flagged as late for its month');
        $this->assertNotSame('2026-06-01', $entry->posting_period->toDateString());
    }

    // ------------------------------------------- the third thing, built in Phase 8a

    /**
     * **A store line with no store on the job is refused, and the message names what is missing.**
     *
     * Phase 5c refused every store line because `stock_locations` did not exist; Phase 8a built it, so the refusal is
     * now specific. It is still a refusal rather than a fallback to direct: accepting it would cost the material as
     * though it had been stocked, and materials-on-site would then be wrong with nothing saying so — §18.1's rule about
     * a healthy figure hiding an absence.
     */
    public function test_a_store_line_is_refused_when_the_job_has_no_store(): void
    {
        $order = $this->issuedOrder();
        $receipt = $this->receipts->create($order);
        $this->receipts->addLineFor($receipt, $this->orderLine($order), 20, [
            'destination' => GoodsReceiptLine::DESTINATION_STORE,
            'product_id' => $this->storedProduct()->getKey(),
        ]);

        try {
            $this->receipts->post($receipt->refresh());
            $this->fail('A store destination should be refused when the job names no store.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('site store', $e->getMessage());
            $this->assertStringContainsString('no store set', $e->getMessage());
        }

        // Nothing happened: no relief, no accrual, no half-posted receipt.
        $this->assertSame(0, CostEntry::query()->count());
        $this->assertSame(0, CommitmentRelief::query()->count());
        $this->assertSame(GoodsReceipt::STATUS_DRAFT, $receipt->refresh()->status);
    }

    /** And a store line naming no product is refused too, because stock is kept per product. */
    public function test_a_store_line_with_no_product_is_refused(): void
    {
        $this->job->update(['stock_location_id' => $this->siteStore()->getKey()]);

        $order = $this->issuedOrder();
        $receipt = $this->receipts->create($order);
        $this->receipts->addLineFor($receipt, $this->orderLine($order), 20, [
            'destination' => GoodsReceiptLine::DESTINATION_STORE,
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('names no product');

        $this->receipts->post($receipt->refresh());
    }

    /**
     * **§6's third path, working.** A store line stocks the material at the job's own location.
     *
     * A `purchase` movement with the whole quantity unconsumed, so the FIFO engine can take it at issue — and no
     * journal entry, because the cost has already reached the job as the accrual and the GL side of
     * goods-received-not-invoiced is §11's posting. Two postings for one delivery is what the division of labour
     * between the document and the movement exists to prevent.
     */
    public function test_a_store_line_stocks_the_material_at_the_jobs_location(): void
    {
        $store = $this->siteStore();
        $this->job->update(['stock_location_id' => $store->getKey()]);
        $product = $this->storedProduct();

        $order = $this->issuedOrder();
        $receipt = $this->receipts->create($order);
        $line = $this->receipts->addLineFor($receipt, $this->orderLine($order), 20, [
            'destination' => GoodsReceiptLine::DESTINATION_STORE,
            'product_id' => $product->getKey(),
        ]);

        $this->receipts->post($receipt->refresh());

        $movement = StockMovement::query()->firstOrFail();

        $this->assertSame($product->getKey(), $movement->product_id);
        $this->assertSame($store->getKey(), $movement->stock_location_id);
        $this->assertSame('purchase', $movement->type);
        $this->assertEquals(20, $movement->quantity);
        $this->assertEquals(20, $movement->remaining_quantity, 'unconsumed, so an issue can take it at FIFO cost');
        $this->assertNull($movement->journal_entry_id, 'the cost reached the job as the accrual; §11 posts the GL side');
        $this->assertSame($line->getKey(), (int) $movement->source_id);

        // And it is on hand *at that location*, which is the question §6 exists to make answerable.
        $this->assertSame(20.0, app(InventoryValuationService::class)->onHand($product, $store));

        // The accrual still happened: stocking the material does not replace costing it.
        $this->assertSame(1, CostEntry::query()->count());
    }

    /** Without Inventory the store path refuses and says to receive direct instead — §18.1's guarded shape. */
    public function test_a_store_line_is_refused_without_the_inventory_module(): void
    {
        $this->job->update(['stock_location_id' => $this->siteStore()->getKey()]);

        CompanyModule::query()
            ->where('company_id', $this->tenant->getKey())
            ->where('module', 'inventory')
            ->update(['licensed' => false, 'enabled' => false]);
        modules()->flush();

        $order = $this->issuedOrder();
        $receipt = $this->receipts->create($order);
        $this->receipts->addLineFor($receipt, $this->orderLine($order), 20, [
            'destination' => GoodsReceiptLine::DESTINATION_STORE,
            'product_id' => 1,
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Inventory module');

        $this->receipts->post($receipt->refresh());
    }

    /** A site store for the fixtures, and a product to put in it. */
    private function siteStore(): StockLocation
    {
        return StockLocation::firstOrCreate(
            ['code' => 'SITE-01'],
            ['name' => 'Tower site store', 'kind' => StockLocation::KIND_SITE],
        );
    }

    private function storedProduct(): Product
    {
        return Product::firstOrCreate(
            ['sku' => 'REBAR-16'],
            ['name' => 'Rebar 16mm', 'unit_price' => 260_000, 'valuation_method' => Product::METHOD_FIFO],
        );
    }

    /** And the direct path — which is the default, and most of what a contractor does — works. */
    public function test_direct_to_site_touches_no_stock_and_is_the_default(): void
    {
        $line = $this->postedReceipt($this->issuedOrder(), 20)->lines()->firstOrFail();

        $this->assertSame(GoodsReceiptLine::DESTINATION_DIRECT, $line->destination);
        $this->assertFalse($line->goesToStore());
    }

    // ------------------------------------------------------------------ reversing

    /** Reversing gives the commitment back and reverses the accrual — both as rows. */
    public function test_reversing_gives_the_commitment_back_and_reverses_the_accrual(): void
    {
        $order = $this->issuedOrder();
        $receipt = $this->postedReceipt($order, 20);

        $this->receipts->reverse($receipt->refresh(), 'The delivery was to the wrong site.');

        $this->assertSame(GoodsReceipt::STATUS_REVERSED, $receipt->refresh()->status);
        $this->assertSame(10_000_000.0, $this->commitments->openFor($this->job, $this->material->getKey()));

        // Two reliefs that net to nothing, and two cost entries that net to nothing — history, not deletion.
        $this->assertSame(2, $this->orderLine($order)->reliefs()->count());
        $this->assertSame(0.0, $this->orderLine($order)->relievedTotal());
        $this->assertSame(2, CostEntry::query()->count());
        $this->assertSame(0.0, app(CostLedger::class)->totalFor($this->job));
    }

    public function test_reversing_needs_a_reason(): void
    {
        $receipt = $this->postedReceipt($this->issuedOrder(), 20);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('needs a reason');

        $this->receipts->reverse($receipt->refresh(), '  ');
    }

    public function test_a_draft_receipt_has_nothing_to_reverse(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('nothing to reverse');

        $this->receipts->reverse($this->receipts->create($this->issuedOrder()), 'Because.');
    }

    public function test_a_reversed_receipt_cannot_be_reposted(): void
    {
        $receipt = $this->postedReceipt($this->issuedOrder(), 20);
        $this->receipts->reverse($receipt->refresh(), 'Wrong site.');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('was reversed');

        $this->receipts->post($receipt->refresh());
    }

    // ------------------------------------------------------------------ the screens

    public function test_the_register_renders_with_the_delivery_note_and_value(): void
    {
        $receipt = $this->postedReceipt($this->issuedOrder(), 20);

        Livewire::test(ListGoodsReceipts::class)
            ->assertSuccessful()
            ->assertSee($receipt->number)
            ->assertSee('DN-88213')
            ->assertSee('5,000,000.00');
    }

    public function test_the_post_action_relieves_and_accrues(): void
    {
        $order = $this->issuedOrder();
        $receipt = $this->receipts->create($order);
        $this->receipts->addLineFor($receipt, $this->orderLine($order), 20);

        Livewire::test(ListGoodsReceipts::class)
            ->callTableAction('post', $receipt->refresh());

        $this->assertSame(GoodsReceipt::STATUS_POSTED, $receipt->refresh()->status);
        $this->assertSame(5_000_000.0, $this->commitments->openFor($this->job, $this->material->getKey()));
    }

    public function test_the_reverse_action_demands_its_reason(): void
    {
        $receipt = $this->postedReceipt($this->issuedOrder(), 20);

        Livewire::test(ListGoodsReceipts::class)
            ->callTableAction('reverse', $receipt->refresh(), ['reason' => 'Delivered to the wrong site.']);

        $this->assertSame(GoodsReceipt::STATUS_REVERSED, $receipt->refresh()->status);
    }
}
