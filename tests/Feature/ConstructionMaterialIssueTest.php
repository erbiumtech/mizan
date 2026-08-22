<?php

namespace Tests\Feature;

use App\Modules\Construction\Models\CostCode;
use App\Modules\Construction\Models\Job;
use App\Modules\ConstructionCosting\Filament\Resources\MaterialIssues\Pages\ListMaterialIssues;
use App\Modules\ConstructionCosting\Models\Commitment;
use App\Modules\ConstructionCosting\Models\CostEntry;
use App\Modules\ConstructionCosting\Models\GoodsReceiptLine;
use App\Modules\ConstructionCosting\Models\MaterialIssue;
use App\Modules\ConstructionCosting\Models\MaterialIssueLine;
use App\Modules\ConstructionCosting\Services\CommitmentService;
use App\Modules\ConstructionCosting\Services\CostLedger;
use App\Modules\ConstructionCosting\Services\GoodsReceiptService;
use App\Modules\ConstructionCosting\Services\MaterialIssueService;
use App\Modules\Core\Models\CompanyModule;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\StockLocation;
use App\Modules\Inventory\Models\StockMovement;
use App\Modules\Inventory\Services\InventoryValuationService;
use App\Support\ModuleMap;
use Filament\Actions\Testing\TestAction;
use InvalidArgumentException;
use Livewire\Livewire;
use RuntimeException;
use Tests\AccountingTestCase;
use Tests\Concerns\InteractsWithTenant;

/**
 * Material out of a site store — §6, Phase 8b.
 *
 * **The invariant this file exists to hold: posting an issue does not change the job's total cost.** §6 makes materials
 * on site "delivered, costed, not yet consumed", so the *receipt* is what costs the material. An issue reclassifies —
 * out of the code the material was received against, on to the code it was used on, as `reclass` entries that sum to
 * zero. Booking new cost here would charge every stocked delivery twice, and both figures would look like material cost
 * on the same job with nothing to disagree with either.
 *
 * Five more properties:
 *
 *  - **The reclass follows the FIFO lots**, because a lot names the goods receipt it arrived on and that receipt names
 *    the code. That chain is the whole mechanism.
 *  - **Where the code is unchanged, nothing is written.** Two rows that net to zero on one code are noise on a report
 *    people have to read.
 *  - **A reclass across cost types is refused**, which keeps the pair inside one ledger account and is what lets it be
 *    `memo` honestly.
 *  - **Wastage is its own `waste` movement**, so it can be totalled by type rather than by remembering a column.
 *  - **A return goes back at the cost it left at**, or a return becomes a way of revaluing stock without buying
 *    anything.
 */
class ConstructionMaterialIssueTest extends AccountingTestCase
{
    use InteractsWithTenant;

    private Job $job;

    private StockLocation $store;

    private Product $rebar;

    /** The code the material is received against. */
    private CostCode $supply;

    /** The code it is used on. */
    private CostCode $fixing;

    private MaterialIssueService $issues;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs($this->makeUser('Administrator', 'issues@test.local'));
        $this->setCurrentTenant();

        foreach (['construction', 'construction_costing', 'accounting', 'inventory'] as $module) {
            CompanyModule::updateOrCreate(
                ['company_id' => $this->tenant->getKey(), 'module' => $module],
                ['licensed' => true, 'enabled' => true],
            );
        }
        modules()->flush();

        $this->store = StockLocation::create([
            'code' => 'SITE-01', 'name' => 'Tower site store', 'kind' => StockLocation::KIND_SITE,
        ]);

        $this->job = Job::create([
            'code' => 'J-1', 'name' => 'Tower', 'stock_location_id' => $this->store->getKey(),
        ]);

        $this->supply = CostCode::create([
            'code' => '03.100', 'name' => 'Reinforcement supply',
            'cost_type' => CostCode::TYPE_MATERIAL, 'unit' => 't',
        ]);
        $this->fixing = CostCode::create([
            'code' => '03.200', 'name' => 'Reinforcement to slabs',
            'cost_type' => CostCode::TYPE_MATERIAL, 'unit' => 't',
        ]);

        $this->rebar = Product::create([
            'sku' => 'REBAR-16', 'name' => 'Rebar 16mm',
            'unit_price' => 300_000, 'valuation_method' => Product::METHOD_FIFO,
        ]);

        $this->issues = app(MaterialIssueService::class);
    }

    /**
     * Stock 20 t into the site store through the real receipt path, so the lot carries a traceable cost code.
     *
     * Through the goods receipt rather than by hand, because the reclass depends on the lot naming the receipt line that
     * named the code — asserting against a hand-made lot would test nothing.
     */
    private function receiveIntoStore(float $quantity = 20, float $rate = 250_000, ?CostCode $code = null): GoodsReceiptLine
    {
        $commitments = app(CommitmentService::class);
        $order = $commitments->create(['type' => Commitment::TYPE_PURCHASE_ORDER]);
        $commitments->addLine($order, $this->job, $code ?? $this->supply, [
            'description' => 'Rebar 16mm', 'quantity' => $quantity, 'rate' => $rate, 'product_id' => $this->rebar->getKey(),
        ]);
        $commitments->approve($order->refresh());
        $commitments->issue($order->refresh());

        $receipts = app(GoodsReceiptService::class);
        $receipt = $receipts->create($order->refresh());
        $line = $receipts->addLineFor($receipt, $order->lines()->firstOrFail(), $quantity, [
            'destination' => 'store',
            'product_id' => $this->rebar->getKey(),
        ]);
        $receipts->post($receipt->refresh());

        return $line->refresh();
    }

    /** @param array<string, mixed> $attributes */
    private function docket(float $quantity = 12, array $attributes = [], ?CostCode $to = null): MaterialIssue
    {
        $issue = $this->issues->create($this->store, ['issued_on' => '2026-08-15']);

        $this->issues->addLine($issue, $this->job, $to ?? $this->fixing, $this->rebar, array_merge([
            'quantity' => $quantity,
        ], $attributes));

        return $issue->refresh();
    }

    /** Cost on one code, over every entry — which is what the four-column report reads. */
    private function costOn(CostCode $code): float
    {
        return round((float) CostEntry::query()->where('cost_code_id', $code->getKey())->sum('amount'), 2);
    }

    // ------------------------------------------------------------------ the invariant

    /**
     * **The rule the whole sub-phase rests on: posting an issue does not change the job's total cost.**
     *
     * 20 t received at 250,000 costs the job 5,000,000 against the supply code. Issuing 12 t moves 3,000,000 onto the
     * fixing code and leaves the total exactly where it was.
     */
    public function test_posting_an_issue_moves_cost_and_adds_none(): void
    {
        $this->receiveIntoStore();

        $before = app(CostLedger::class)->totalFor($this->job);
        $this->assertSame(5_000_000.0, $before, 'the receipt is what costs the material');

        $this->issues->post($this->docket(12));

        $this->assertSame($before, app(CostLedger::class)->totalFor($this->job), 'and the issue adds nothing');
        $this->assertSame(2_000_000.0, $this->costOn($this->supply), '5,000,000 less the 3,000,000 issued out');
        $this->assertSame(3_000_000.0, $this->costOn($this->fixing));
    }

    /** The pair is `reclass` and `memo`: it nets to zero inside one ledger account, so it never needs to post. */
    public function test_the_reclass_pair_is_memo_and_sums_to_zero(): void
    {
        $this->receiveIntoStore();
        $this->issues->post($this->docket(12));

        $reclass = CostEntry::query()->where('kind', CostEntry::KIND_RECLASS)->get();

        $this->assertCount(2, $reclass);
        $this->assertSame(0.0, round((float) $reclass->sum('amount'), 2));

        foreach ($reclass as $entry) {
            $this->assertSame(CostEntry::GL_MEMO, $entry->gl_treatment);
            $this->assertSame(ModuleMap::alias(MaterialIssueLine::class), $entry->source_type);
        }
    }

    /**
     * **Where the code is unchanged, nothing is written at all.**
     *
     * A pair that nets to zero on one code is two rows of noise on a report people have to read — and it would make
     * every cost-code history twice as long for no information.
     */
    public function test_issuing_to_the_same_code_writes_no_reclass(): void
    {
        $this->receiveIntoStore();

        $this->issues->post($this->docket(12, to: $this->supply));

        $this->assertSame(0, CostEntry::query()->where('kind', CostEntry::KIND_RECLASS)->count());
        $this->assertSame(5_000_000.0, $this->costOn($this->supply));
    }

    /** The line's value comes from the FIFO lots, and the blended unit cost with it. */
    public function test_the_line_is_valued_from_the_fifo_lots(): void
    {
        $this->receiveIntoStore(10, 200_000);
        $this->receiveIntoStore(10, 300_000);

        $this->issues->post($this->docket(12));

        $line = MaterialIssueLine::query()->firstOrFail();

        // 10 at 200,000 then 2 at 300,000 = 2,600,000 over 12 t.
        $this->assertEquals(2_600_000, $line->amount);
        $this->assertEquals(216_666.6667, round((float) $line->unit_cost, 4));
    }

    /** And the reclass splits by the code each lot was received against, not by one blended code. */
    public function test_the_reclass_splits_by_the_code_each_lot_came_in_on(): void
    {
        $other = CostCode::create([
            'code' => '03.150', 'name' => 'Reinforcement, second delivery',
            'cost_type' => CostCode::TYPE_MATERIAL, 'unit' => 't',
        ]);

        $this->receiveIntoStore(10, 200_000, $this->supply);
        $this->receiveIntoStore(10, 300_000, $other);

        $this->issues->post($this->docket(12));

        // 10 t out of the first delivery (2,000,000) and 2 t out of the second (600,000).
        $this->assertSame(0.0, $this->costOn($this->supply), '2,000,000 received, 2,000,000 issued out');
        $this->assertSame(2_400_000.0, $this->costOn($other), '3,000,000 less 600,000');
        $this->assertSame(2_600_000.0, $this->costOn($this->fixing));
    }

    /** Lots with no traceable receipt are left alone: there is no code to move the cost from. */
    public function test_stock_with_no_traceable_receipt_is_not_reclassified(): void
    {
        StockMovement::create([
            'product_id' => $this->rebar->getKey(),
            'stock_location_id' => $this->store->getKey(),
            'type' => 'purchase',
            'quantity' => 20,
            'unit_cost' => 250_000,
            'remaining_quantity' => 20,
            'movement_date' => '2026-08-01',
        ]);

        $this->issues->post($this->docket(12));

        $this->assertSame(0, CostEntry::query()->where('kind', CostEntry::KIND_RECLASS)->count());
        $this->assertEquals(3_000_000, MaterialIssueLine::query()->firstOrFail()->amount, 'still valued, just not moved');
    }

    /**
     * **A reclass across cost types is refused**, which is what keeps the pair inside one ledger account.
     *
     * It is also a real business rule: material received as material has not become labour by being carried to the
     * work face.
     */
    public function test_issuing_to_a_different_cost_type_is_refused(): void
    {
        $labour = CostCode::create([
            'code' => '02.100', 'name' => 'Steel fixing labour', 'cost_type' => CostCode::TYPE_LABOUR, 'unit' => 't',
        ]);

        $this->receiveIntoStore();
        $issue = $this->docket(12, to: $labour);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('between types would move it between ledger accounts');

        $this->issues->post($issue);
    }

    // ------------------------------------------------------------------ the stock side

    public function test_posting_takes_the_stock_off_the_store(): void
    {
        $this->receiveIntoStore();

        $this->assertSame(20.0, app(InventoryValuationService::class)->onHand($this->rebar, $this->store));

        $this->issues->post($this->docket(12));

        $this->assertSame(8.0, app(InventoryValuationService::class)->onHand($this->rebar, $this->store));

        $movement = StockMovement::query()->where('type', StockMovement::TYPE_ISSUE)->firstOrFail();
        $this->assertEquals(-12, $movement->quantity);
        $this->assertSame($this->store->getKey(), $movement->stock_location_id);
        $this->assertEquals(3_000_000, $movement->total_cost);
    }

    /**
     * **Wastage leaves as its own `waste` movement**, which is why §6 added that type to the enum.
     *
     * Eleven tonnes built in and one wasted is two movements, so "what did we waste" is a query on a type rather than a
     * column somebody has to remember to subtract.
     */
    public function test_wastage_leaves_as_its_own_movement(): void
    {
        $this->receiveIntoStore();

        $this->issues->post($this->docket(12, ['wastage_quantity' => 1, 'wastage_reason' => 'Cut short in error.']));

        $issue = StockMovement::query()->where('type', StockMovement::TYPE_ISSUE)->firstOrFail();
        $waste = StockMovement::query()->where('type', StockMovement::TYPE_WASTE)->firstOrFail();

        $this->assertEquals(-11, $issue->quantity);
        $this->assertEquals(-1, $waste->quantity);
        $this->assertEquals(250_000, $waste->total_cost);

        // Both left the store, so on hand fell by the whole twelve.
        $this->assertSame(8.0, app(InventoryValuationService::class)->onHand($this->rebar, $this->store));

        // And the whole twelve is reclassified: wasted material was paid for and wasted on this activity.
        $this->assertSame(3_000_000.0, $this->costOn($this->fixing));
        $this->assertEquals(250_000, MaterialIssueLine::query()->firstOrFail()->wastageValue());
    }

    /** Wastage is part of the quantity, not on top of it. */
    public function test_more_wasted_than_issued_is_refused(): void
    {
        $this->receiveIntoStore();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('not on top of it');

        $this->docket(12, ['wastage_quantity' => 13]);
    }

    /** More than the store holds is refused, at that location rather than company-wide. */
    public function test_issuing_more_than_the_store_holds_is_refused(): void
    {
        $this->receiveIntoStore(5);

        $issue = $this->docket(12);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Insufficient stock');

        $this->issues->post($issue);
    }

    // ------------------------------------------------------------------ returns

    /**
     * **A return goes back at the cost it left at**, and unwinds the reclass in the same proportion.
     *
     * Revaluing it at today's FIFO would make a return a way of changing the value of stock without buying anything.
     */
    public function test_a_return_goes_back_at_the_cost_it_left_at(): void
    {
        $this->receiveIntoStore();
        $this->issues->post($this->docket(12));

        $line = MaterialIssueLine::query()->firstOrFail();
        $this->issues->recordReturn($line, 4, 'Over-ordered for the pour.');

        $movement = StockMovement::query()->where('type', StockMovement::TYPE_RETURN)->firstOrFail();

        $this->assertEquals(4, $movement->quantity);
        $this->assertEquals(250_000, $movement->unit_cost, 'the rate it left at');
        $this->assertEquals(4, $movement->remaining_quantity, 'available to be issued again');
        $this->assertSame(12.0, app(InventoryValuationService::class)->onHand($this->rebar, $this->store));

        // The reclass unwinds pro-rata: 4 of 12 is 1,000,000 back on the supply code.
        $this->assertSame(3_000_000.0, $this->costOn($this->supply));
        $this->assertSame(2_000_000.0, $this->costOn($this->fixing));
        $this->assertEquals(4, $line->refresh()->returned_quantity);
    }

    /** And the job's total is still unchanged, which is the invariant seen from the other end. */
    public function test_a_return_does_not_change_the_jobs_total_either(): void
    {
        $this->receiveIntoStore();
        $this->issues->post($this->docket(12));

        $line = MaterialIssueLine::query()->firstOrFail();
        $this->issues->recordReturn($line, 4);

        $this->assertSame(5_000_000.0, app(CostLedger::class)->totalFor($this->job));
    }

    /**
     * **Two partial returns unwind correctly and write no junk.**
     *
     * The bug this guards was real and subtle: an unwind writes a reclass pair of its own, and the negative half sits on
     * the code the material was *issued* to — which looked exactly like a posting entry to the query that finds what to
     * unwind. A second return therefore read its predecessor's row and wrote two more that netted to zero on one code:
     * the totals stayed right and the cost report filled up with the very noise `reclassify()` refuses to write.
     *
     * Posting never puts a negative on the issue's own code, so "not on the issued-to code" separates the two exactly.
     */
    public function test_two_partial_returns_unwind_without_writing_noise(): void
    {
        $this->receiveIntoStore();
        $this->issues->post($this->docket(12));

        $line = MaterialIssueLine::query()->firstOrFail();

        $this->issues->recordReturn($line, 4);
        $afterFirst = CostEntry::query()->where('kind', CostEntry::KIND_RECLASS)->count();

        $this->issues->recordReturn($line->refresh(), 4);

        // Two rows for the posting, two for each unwind. Six, not eight.
        $this->assertSame(4, $afterFirst);
        $this->assertSame(6, CostEntry::query()->where('kind', CostEntry::KIND_RECLASS)->count());

        // And the arithmetic: 8 of 12 back, so 2,000,000 of the 3,000,000 returns to the supply code.
        $this->assertSame(4_000_000.0, $this->costOn($this->supply));
        $this->assertSame(1_000_000.0, $this->costOn($this->fixing));
        $this->assertSame(5_000_000.0, app(CostLedger::class)->totalFor($this->job));
        $this->assertEquals(8, $line->refresh()->returned_quantity);
    }

    /** Returning everything puts the whole reclass back and leaves nothing on the issued-to code. */
    public function test_returning_everything_unwinds_the_whole_reclass(): void
    {
        $this->receiveIntoStore();
        $this->issues->post($this->docket(12));

        $line = MaterialIssueLine::query()->firstOrFail();

        $this->issues->recordReturn($line, 6);
        $this->issues->recordReturn($line->refresh(), 6);

        $this->assertSame(5_000_000.0, $this->costOn($this->supply));
        $this->assertSame(0.0, $this->costOn($this->fixing));
        $this->assertSame(20.0, app(InventoryValuationService::class)->onHand($this->rebar, $this->store));
    }

    public function test_returning_more_than_is_out_is_refused(): void
    {
        $this->receiveIntoStore();
        $this->issues->post($this->docket(12));

        $line = MaterialIssueLine::query()->firstOrFail();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('more than is still out');

        $this->issues->recordReturn($line, 13);
    }

    /** Wasted material cannot come back, because it is not there — only the usable part is outstanding. */
    public function test_wasted_material_is_not_returnable(): void
    {
        $this->receiveIntoStore();
        $this->issues->post($this->docket(12, ['wastage_quantity' => 2]));

        $line = MaterialIssueLine::query()->firstOrFail();

        $this->assertSame(10.0, $line->outstandingQuantity());

        $this->expectException(InvalidArgumentException::class);
        $this->issues->recordReturn($line, 11);
    }

    public function test_a_draft_docket_has_nothing_to_return(): void
    {
        $this->receiveIntoStore();
        $issue = $this->docket(12);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('posted docket');

        $this->issues->recordReturn($issue->lines()->firstOrFail(), 2);
    }

    // ------------------------------------------------------------------ drafts, posting, reversal

    public function test_a_draft_moves_nothing(): void
    {
        $this->receiveIntoStore();
        $this->docket(12);

        $this->assertSame(20.0, app(InventoryValuationService::class)->onHand($this->rebar, $this->store));
        $this->assertSame(0, CostEntry::query()->where('kind', CostEntry::KIND_RECLASS)->count());
        $this->assertNull(MaterialIssueLine::query()->firstOrFail()->amount);
    }

    public function test_the_docket_is_numbered_per_year(): void
    {
        $this->assertSame('MI-'.now()->year.'-0001', $this->issues->create($this->store)->number);
        $this->assertSame('MI-'.now()->year.'-0002', $this->issues->create($this->store)->number);
    }

    public function test_a_posted_docket_refuses_a_new_line(): void
    {
        $this->receiveIntoStore();
        $issue = $this->issues->post($this->docket(12));

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('A further issue is a new docket');

        $this->issues->addLine($issue, $this->job, $this->fixing, $this->rebar, ['quantity' => 1]);
    }

    public function test_an_empty_docket_cannot_be_posted(): void
    {
        $issue = $this->issues->create($this->store);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Nothing left the store');

        $this->issues->post($issue);
    }

    public function test_posting_twice_is_refused(): void
    {
        $this->receiveIntoStore();
        $issue = $this->issues->post($this->docket(12));

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('already posted');

        $this->issues->post($issue);
    }

    /** Reversing puts the material back and unwinds the whole reclass. */
    public function test_reversing_puts_the_material_back_and_the_cost_where_it_was(): void
    {
        $this->receiveIntoStore();
        $issue = $this->issues->post($this->docket(12));

        $this->issues->reverse($issue, 'Docket written against the wrong job.');

        $this->assertTrue($issue->refresh()->isReversed());
        $this->assertSame(20.0, app(InventoryValuationService::class)->onHand($this->rebar, $this->store));
        $this->assertSame(5_000_000.0, $this->costOn($this->supply));
        $this->assertSame(0.0, $this->costOn($this->fixing));
        $this->assertSame(5_000_000.0, app(CostLedger::class)->totalFor($this->job));
    }

    public function test_reversing_needs_a_reason(): void
    {
        $this->receiveIntoStore();
        $issue = $this->issues->post($this->docket(12));

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('needs a reason');

        $this->issues->reverse($issue, '  ');
    }

    public function test_a_reversed_docket_cannot_be_reposted(): void
    {
        $this->receiveIntoStore();
        $issue = $this->issues->post($this->docket(12));
        $this->issues->reverse($issue, 'Wrong job.');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Write a new docket');

        $this->issues->post($issue->refresh());
    }

    // ------------------------------------------------------------------ guarded, not required (§18.1)

    /**
     * Without Inventory there are no issues at all, and the refusal says what the alternative is.
     *
     * An issue *is* a stock movement, so unlike most of §18.1's rows there is nothing smaller to degrade to — §6's
     * direct-to-site path costs material on receipt and is what most contractors use for everything.
     */
    public function test_issuing_is_refused_without_the_inventory_module(): void
    {
        CompanyModule::query()
            ->where('company_id', $this->tenant->getKey())
            ->where('module', 'inventory')
            ->update(['licensed' => false, 'enabled' => false]);
        modules()->flush();

        $this->assertFalse($this->issues->isAvailable());

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('needs the Inventory module');

        $this->issues->create($this->store);
    }

    // ------------------------------------------------------------------ the screen

    public function test_the_register_renders_and_says_the_value_moved(): void
    {
        $this->receiveIntoStore();
        $issue = $this->issues->post($this->docket(12));

        Livewire::test(ListMaterialIssues::class)
            ->assertCanSeeTableRecords([$issue])
            ->assertSee($issue->number)
            ->assertSee('reclassified, not added');
    }

    public function test_the_post_action_moves_the_stock_and_the_cost(): void
    {
        $this->receiveIntoStore();
        $issue = $this->docket(12);

        Livewire::test(ListMaterialIssues::class)
            ->callAction(TestAction::make('post')->table($issue));

        $this->assertTrue($issue->refresh()->isPosted());
        $this->assertSame(8.0, app(InventoryValuationService::class)->onHand($this->rebar, $this->store));
        $this->assertSame(3_000_000.0, $this->costOn($this->fixing));
    }

    /** And a refusal reaches the user as a notification rather than a stack trace. */
    public function test_the_post_action_surfaces_insufficient_stock(): void
    {
        $this->receiveIntoStore(5);
        $issue = $this->docket(12);

        Livewire::test(ListMaterialIssues::class)
            ->callAction(TestAction::make('post')->table($issue))
            ->assertNotified();

        $this->assertTrue($issue->refresh()->isDraft());
    }
}
