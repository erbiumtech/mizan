<?php

namespace Tests\Feature;

use App\Modules\Construction\Models\CostCode;
use App\Modules\Construction\Models\Job;
use App\Modules\ConstructionContracts\Models\Contract;
use App\Modules\ConstructionContracts\Services\ContractService;
use App\Modules\ConstructionCosting\Filament\Pages\JobCostReport;
use App\Modules\ConstructionCosting\Models\Commitment;
use App\Modules\ConstructionCosting\Models\GoodsReceiptLine;
use App\Modules\ConstructionCosting\Models\MaterialIssue;
use App\Modules\ConstructionCosting\Services\CommitmentService;
use App\Modules\ConstructionCosting\Services\GoodsReceiptService;
use App\Modules\ConstructionCosting\Services\MaterialIssueService;
use App\Modules\ConstructionCosting\Services\MaterialsOnSite;
use App\Modules\Core\Models\CompanyModule;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\StockLocation;
use Livewire\Livewire;
use Tests\AccountingTestCase;
use Tests\Concerns\InteractsWithTenant;

/**
 * Delivered, costed, not yet consumed — §6, Phase 8c.
 *
 * **"Exactly the gap between receipt and issue, and it is one query."** That sentence is §6's whole justification for
 * receipt and issue being two documents: with a single "material used" event there is no moment at which material is on
 * site and unconsumed, and this figure could not exist at all. It is *one* query because Phase 8a put the location on
 * the movement and the FIFO engine already maintains `remaining_quantity` — nothing here re-derives received-minus-issued
 * by hand, which would be a second answer to a question the lots already answer.
 *
 * Four properties:
 *
 *  - **Receipt puts it on site; issue takes it off.** The two documents together are the figure.
 *  - **It is reported against the code the material was received on**, because until it is issued that is where the
 *    cost still sits — the same lot-to-receipt-to-code chain Phase 8b's reclass uses.
 *  - **Untraceable stock is its own row**, not dropped and not folded into a code: §6's failure is a figure right in
 *    total and wrong in every breakdown.
 *  - **It is a cost figure, not a claim.** §10's certificate materials line is a contractual assessment at contract
 *    rates; this is what the material cost, and the certificate shows it as evidence beside the claim rather than as
 *    the claim.
 */
class ConstructionMaterialsOnSiteTest extends AccountingTestCase
{
    use InteractsWithTenant;

    private Job $job;

    private StockLocation $store;

    private Product $rebar;

    private CostCode $supply;

    private CostCode $fixing;

    private MaterialsOnSite $onSite;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs($this->makeUser('Administrator', 'onsite@test.local'));
        $this->setCurrentTenant();

        foreach (['construction', 'construction_costing', 'construction_contracts', 'accounting', 'inventory'] as $module) {
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

        $this->onSite = app(MaterialsOnSite::class);
    }

    private function receiveIntoStore(float $quantity = 20, float $rate = 250_000, ?CostCode $code = null): GoodsReceiptLine
    {
        $commitments = app(CommitmentService::class);
        $order = $commitments->create(['type' => Commitment::TYPE_PURCHASE_ORDER]);
        $commitments->addLine($order, $this->job, $code ?? $this->supply, [
            'description' => 'Rebar 16mm', 'quantity' => $quantity, 'rate' => $rate,
            'product_id' => $this->rebar->getKey(),
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

    private function issue(float $quantity): MaterialIssue
    {
        $issues = app(MaterialIssueService::class);
        $issue = $issues->create($this->store, ['issued_on' => '2026-08-15']);
        $issues->addLine($issue, $this->job, $this->fixing, $this->rebar, ['quantity' => $quantity]);

        return $issues->post($issue->refresh());
    }

    // ------------------------------------------------------------------ the gap between receipt and issue

    /** Nothing received is nothing on site, and zero is the true answer rather than a null nobody can print. */
    public function test_a_job_with_no_deliveries_holds_nothing(): void
    {
        $this->assertSame([], $this->onSite->forJob($this->job));
        $this->assertSame(0.0, $this->onSite->valueFor($this->job));
    }

    /** **A receipt into the store puts material on site.** */
    public function test_a_store_receipt_puts_material_on_site(): void
    {
        $this->receiveIntoStore(20, 250_000);

        $rows = $this->onSite->forJob($this->job);

        $this->assertCount(1, $rows);
        $this->assertSame($this->rebar->getKey(), $rows[0]['product']->getKey());
        $this->assertSame(20.0, $rows[0]['quantity']);
        $this->assertSame(5_000_000.0, $rows[0]['value']);
        $this->assertSame(5_000_000.0, $this->onSite->valueFor($this->job));
    }

    /** **And an issue takes it off.** The two documents together are the figure, which is §6's whole point. */
    public function test_an_issue_takes_material_off_site(): void
    {
        $this->receiveIntoStore(20, 250_000);
        $this->issue(12);

        $this->assertSame(2_000_000.0, $this->onSite->valueFor($this->job));
        $this->assertSame(8.0, $this->onSite->forJob($this->job)[0]['quantity']);
    }

    /** Issuing everything empties it, and the answer is an empty list rather than a row of zero. */
    public function test_issuing_everything_leaves_nothing_on_site(): void
    {
        $this->receiveIntoStore(20, 250_000);
        $this->issue(20);

        $this->assertSame([], $this->onSite->forJob($this->job));
        $this->assertSame(0.0, $this->onSite->valueFor($this->job));
    }

    /** Material returned to the store is on site again, at the cost it left at. */
    public function test_returned_material_is_on_site_again(): void
    {
        $this->receiveIntoStore(20, 250_000);
        $issue = $this->issue(12);

        app(MaterialIssueService::class)->recordReturn($issue->lines()->firstOrFail(), 4);

        $this->assertSame(3_000_000.0, $this->onSite->valueFor($this->job));
    }

    /** Two deliveries at two rates value at their own rates rather than a company average. */
    public function test_two_deliveries_are_valued_at_their_own_rates(): void
    {
        $this->receiveIntoStore(10, 200_000);
        $this->receiveIntoStore(10, 300_000);

        $rows = $this->onSite->forJob($this->job);

        $this->assertSame(20.0, $rows[0]['quantity']);
        $this->assertSame(5_000_000.0, $rows[0]['value']);
    }

    /** And after a FIFO issue, what is left is valued at the rate of the lots that remain. */
    public function test_what_remains_is_valued_at_the_remaining_lots(): void
    {
        $this->receiveIntoStore(10, 200_000);
        $this->receiveIntoStore(10, 300_000);

        $this->issue(10);

        // The cheap lot went first, so the 10 left are the dear ones.
        $this->assertSame(3_000_000.0, $this->onSite->valueFor($this->job));
    }

    // ------------------------------------------------------------------ per cost code

    /**
     * Reported against the code the material was **received** on.
     *
     * Until it is issued, the cost is still sitting on that code — Phase 8b's reclass is what moves it. Reporting it
     * against anything else would put a figure on a code the ledger has nothing on.
     */
    public function test_it_is_reported_against_the_code_it_was_received_on(): void
    {
        $this->receiveIntoStore(20, 250_000, $this->supply);

        $byCode = $this->onSite->byCostCode($this->job);

        $this->assertArrayHasKey($this->supply->getKey(), $byCode);
        $this->assertSame(5_000_000.0, $byCode[$this->supply->getKey()]['value']);
        $this->assertArrayNotHasKey($this->fixing->getKey(), $byCode);
    }

    /** Two deliveries on two codes stay apart, so the breakdown adds up to the total. */
    public function test_deliveries_on_two_codes_stay_apart(): void
    {
        $other = CostCode::create([
            'code' => '03.150', 'name' => 'Reinforcement, second delivery',
            'cost_type' => CostCode::TYPE_MATERIAL, 'unit' => 't',
        ]);

        $this->receiveIntoStore(10, 200_000, $this->supply);
        $this->receiveIntoStore(10, 300_000, $other);

        $byCode = $this->onSite->byCostCode($this->job);

        $this->assertSame(2_000_000.0, $byCode[$this->supply->getKey()]['value']);
        $this->assertSame(3_000_000.0, $byCode[$other->getKey()]['value']);
        $this->assertSame(
            $this->onSite->valueFor($this->job),
            round(array_sum(array_column($byCode, 'value')), 2),
            'the breakdown adds up to the total, which is the property §6 is about',
        );
    }

    /**
     * Stock with no traceable delivery is **its own row**, not dropped and not folded into a code.
     *
     * §6's failure is a figure that is right in total and wrong in every breakdown. Showing the untraceable remainder
     * separately is what keeps the two consistent.
     */
    public function test_untraceable_stock_is_shown_separately(): void
    {
        $this->receiveIntoStore(10, 200_000);

        \App\Modules\Inventory\Models\StockMovement::create([
            'product_id' => $this->rebar->getKey(),
            'stock_location_id' => $this->store->getKey(),
            'type' => 'purchase',
            'quantity' => 5,
            'unit_cost' => 400_000,
            'remaining_quantity' => 5,
            'movement_date' => '2026-08-01',
        ]);

        $byCode = $this->onSite->byCostCode($this->job);

        $this->assertSame(2_000_000.0, $byCode[$this->supply->getKey()]['value']);
        $this->assertNull($byCode['']['code']);
        $this->assertSame(2_000_000.0, $byCode['']['value']);
        $this->assertSame(4_000_000.0, $this->onSite->valueFor($this->job));
    }

    // ------------------------------------------------------------------ absent rather than zero

    /** A job with no store holds nothing, and the report says "no store" rather than printing a zero. */
    public function test_a_job_with_no_store_reports_nothing(): void
    {
        $storeless = Job::create(['code' => 'J-2', 'name' => 'Annexe']);

        $this->assertSame([], $this->onSite->forJob($storeless));
        $this->assertSame(0.0, $this->onSite->valueFor($storeless));
    }

    /** Without Inventory there is no stock at all, so the figure is unavailable rather than zero. */
    public function test_it_is_unavailable_without_the_inventory_module(): void
    {
        $this->receiveIntoStore(20, 250_000);

        CompanyModule::query()
            ->where('company_id', $this->tenant->getKey())
            ->where('module', 'inventory')
            ->update(['licensed' => false, 'enabled' => false]);
        modules()->flush();

        $this->assertFalse($this->onSite->isAvailable());
        $this->assertSame([], $this->onSite->forJob($this->job));
    }

    // ------------------------------------------------------------------ the screens

    /** The cost report shows it, because it is the part of `actual` that has not been used yet. */
    public function test_the_cost_report_shows_what_is_on_site(): void
    {
        $this->receiveIntoStore(20, 250_000);

        Livewire::test(JobCostReport::class)
            ->fillForm(['job_id' => $this->job->getKey()])
            ->assertSee('Materials on site')
            ->assertSee('03.100');
    }

    /** And says so plainly when the store is empty, rather than hiding the section. */
    public function test_the_cost_report_says_when_the_store_is_empty(): void
    {
        $this->receiveIntoStore(20, 250_000);
        $this->issue(20);

        Livewire::test(JobCostReport::class)
            ->fillForm(['job_id' => $this->job->getKey()])
            ->assertSee('Nothing in the store');
    }

    /** A job with no store gets no section at all — there is nothing to be right or wrong about. */
    public function test_the_cost_report_omits_the_section_for_a_job_with_no_store(): void
    {
        $storeless = Job::create(['code' => 'J-2', 'name' => 'Annexe']);

        Livewire::test(JobCostReport::class)
            ->fillForm(['job_id' => $storeless->getKey()])
            ->assertDontSee('Materials on site');
    }

    /**
     * The certificate shows it as **evidence**, and the wording says so.
     *
     * §6 names the certificate's materials line as the second reason receipt and issue are separate documents. What is
     * claimed is a contractual assessment at contract rates; this is what the material cost, and conflating them would
     * tell a certifier their assessment had been made for them.
     */
    public function test_the_certificate_form_shows_the_cost_as_evidence(): void
    {
        $this->receiveIntoStore(20, 250_000);

        $contracts = app(ContractService::class);
        $contract = $contracts->create($this->job, [
            'side' => Contract::SIDE_RECEIVABLE,
            'title' => 'Main works',
            'contract_sum' => 100_000_000,
        ]);
        $contracts->addItem($contract, [
            'item_no' => '1', 'description' => 'The works', 'scheduled_value' => 100_000_000,
        ]);
        $contracts->execute($contract);

        Livewire::test(
            \App\Modules\ConstructionContracts\Filament\Resources\PaymentCertificates\Pages\CreatePaymentCertificate::class
        )
            ->fillForm(['contract_id' => $contract->getKey()])
            ->assertSee('Materials on site')
            ->assertSee('not the claim itself');
    }

    /**
     * **The certificate says which source the evidence came from** — Phase 9c, and it fixes a Phase 8c defect.
     *
     * The panel used to be absent whenever `construction_costing` was off, and `construction_contracts` does not
     * require that module. A certifier saw no materials-on-site panel and could not tell whether nothing was on site or
     * nothing was being tracked: §18.1's healthy figure hiding an absence, in the one place a figure is being certified.
     *
     * With both modules, stock is the authority — because `remaining_quantity` goes *down* when material is built in and
     * a diary flag never does — and the diary's dockets sit beside it saying so.
     */
    public function test_the_certificate_names_the_source_of_the_evidence(): void
    {
        $contract = $this->certifiableContract();
        $this->receiveIntoStore(20, 250_000);
        $this->flagDocketOnSite();

        Livewire::test(
            \App\Modules\ConstructionContracts\Filament\Resources\PaymentCertificates\Pages\CreatePaymentCertificate::class
        )
            ->fillForm(['contract_id' => $contract->getKey()])
            ->assertSee('Held in the store, at cost')
            ->assertSee('What site recorded, beside it')
            ->assertSee('20 tonnes of aggregate')
            ->assertSee('Not a total');
    }

    /**
     * **With no stock ledger the diary is the whole of the evidence, and the certificate says that too.**
     *
     * This is the case that used to be silent. A contractor certifying without cost control has no lots to read, so the
     * dockets site flagged are all there is — and the panel tells the certifier it is a quantity somebody has to verify
     * on site rather than a computed figure.
     */
    public function test_with_no_cost_ledger_the_diary_carries_the_evidence_and_says_so(): void
    {
        $contract = $this->certifiableContract();
        $this->flagDocketOnSite();

        CompanyModule::query()
            ->where('company_id', $this->tenant->getKey())
            ->whereIn('module', ['construction_costing', 'inventory'])
            ->update(['licensed' => false, 'enabled' => false]);
        modules()->flush();

        Livewire::test(
            \App\Modules\ConstructionContracts\Filament\Resources\PaymentCertificates\Pages\CreatePaymentCertificate::class
        )
            ->fillForm(['contract_id' => $contract->getKey()])
            ->assertSee('the only source here')
            ->assertSee('no stock ledger on this installation')
            ->assertDontSee('Held in the store, at cost');
    }

    /** And with neither source there is genuinely nothing to certify against, so the panel stays away. */
    public function test_with_neither_source_the_panel_is_absent(): void
    {
        $contract = $this->certifiableContract();

        CompanyModule::query()
            ->where('company_id', $this->tenant->getKey())
            ->whereIn('module', ['construction_costing', 'inventory', 'construction_field'])
            ->update(['licensed' => false, 'enabled' => false]);
        modules()->flush();

        Livewire::test(
            \App\Modules\ConstructionContracts\Filament\Resources\PaymentCertificates\Pages\CreatePaymentCertificate::class
        )
            ->fillForm(['contract_id' => $contract->getKey()])
            ->assertDontSee('Held in the store, at cost')
            ->assertDontSee('the only source here');
    }

    private function certifiableContract(): Contract
    {
        $contracts = app(ContractService::class);
        $contract = $contracts->create($this->job, [
            'side' => Contract::SIDE_RECEIVABLE,
            'title' => 'Main works',
            'contract_sum' => 100_000_000,
        ]);
        $contracts->addItem($contract, [
            'item_no' => '1', 'description' => 'The works', 'scheduled_value' => 100_000_000,
        ]);

        return $contracts->execute($contract);
    }

    /** A site diary docket flagged as standing on site — §16.1's `is_materials_on_site`. */
    private function flagDocketOnSite(): void
    {
        CompanyModule::updateOrCreate(
            ['company_id' => $this->tenant->getKey(), 'module' => 'construction_field'],
            ['licensed' => true, 'enabled' => true],
        );
        modules()->flush();

        $logs = app(\App\Modules\ConstructionField\Services\DailyLogService::class);

        $logs->addDelivery($logs->open($this->job, '2026-08-20'), [
            'docket_number' => 'DN-8841',
            'description' => '20 tonnes of aggregate',
            'quantity' => 20,
            'unit_of_measure' => 't',
            'is_materials_on_site' => true,
        ]);
    }
}
