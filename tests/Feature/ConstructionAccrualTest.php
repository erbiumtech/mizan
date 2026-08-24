<?php

namespace Tests\Feature;

use App\Modules\Construction\Models\CostCode;
use App\Modules\Construction\Models\Job;
use App\Modules\ConstructionCosting\Models\CostBatch;
use App\Modules\ConstructionCosting\Models\CostEntry;
use App\Modules\ConstructionCosting\Models\CostPeriod;
use App\Modules\ConstructionCosting\Services\AccrualService;
use App\Modules\ConstructionCosting\Services\CostLedger;
use App\Modules\Core\Models\CompanyModule;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Tests\AccountingTestCase;
use Tests\Concerns\InteractsWithTenant;

/**
 * The two accruals and the reversal that keeps them honest — §4.5, Phase 11b.
 *
 * §4.5's whole argument is about what happens when an accrual is *not* unwound, and it is worth restating because every
 * test below defends one half of it:
 *
 *  - **Reverse-and-re-accrue rather than matching off.** "Matching an accrual line-by-line to a later invoice is the
 *    same heuristic that fails for commitment relief, and an accrual that fails to match sits on the balance sheet
 *    forever with nobody able to say what it is for."
 *  - **At period open, not period close**, because the failure mode of reverse-and-re-accrue is the reversal not
 *    running — "so the accrual and the real invoice both sit in the ledger and the job costs double for a month". A step
 *    attached to opening the month runs before anybody looks at the figures.
 *
 * And one decision that is this phase's rather than the plan's: **the reversal is itself `kind = accrual`**, negative.
 * §3.5 defines Actual as `kind != accrual` and Accrued as `kind = accrual`, so a reversal written as `kind = reversal`
 * would net out of the Accrued column into the Actual one and make a job look cheaper for no reason any query could
 * find.
 */
class ConstructionAccrualTest extends AccountingTestCase
{
    use InteractsWithTenant;

    private Job $job;

    private CostCode $material;

    private CostCode $subcontract;

    private CostLedger $ledger;

    private AccrualService $accruals;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs($this->makeUser('Administrator', 'accruals@test.local'));
        $this->setCurrentTenant();

        foreach (['construction', 'construction_costing', 'accounting'] as $module) {
            CompanyModule::updateOrCreate(
                ['company_id' => $this->tenant->getKey(), 'module' => $module],
                ['licensed' => true, 'enabled' => true],
            );
        }
        modules()->flush();

        $this->job = Job::create(['code' => 'J-1', 'name' => 'Tower']);
        $this->material = CostCode::create([
            'code' => '03.100', 'name' => 'Ready-mix concrete', 'cost_type' => CostCode::TYPE_MATERIAL, 'unit' => 'm3',
        ]);
        $this->subcontract = CostCode::create([
            'code' => '07.100', 'name' => 'Cladding', 'cost_type' => CostCode::TYPE_SUBCONTRACT, 'unit' => 'm2',
        ]);

        $this->ledger = app(CostLedger::class);
        $this->accruals = app(AccrualService::class);
    }

    private function accrual(string $incurredOn, float $amount, array $attributes = []): CostEntry
    {
        return $this->ledger->record($this->job, $this->material, array_merge([
            'kind' => CostEntry::KIND_ACCRUAL,
            'gl_purpose' => 'grni',
            'amount' => $amount,
            'incurred_on' => $incurredOn,
            'description' => 'Goods received not invoiced',
        ], $attributes));
    }

    /**
     * A purchase order line, a posted delivery against it, and optionally an invoice allocation.
     *
     * Written with the query builder because these tables belong to Phases 5 and 6 and what is under test is the
     * accrual's *arithmetic* over them. Going through `CommitmentService` and `GoodsReceiptService` would drag their
     * approval and posting rules into every fixture and test three things at once.
     *
     * @return int the commitment line id
     */
    private function orderAndDeliver(float $ordered, float $received, ?float $invoiced = null, ?CostCode $code = null): int
    {
        $code ??= $this->material;

        // No `job_id` here on purpose: §5 puts the job on the *line*, because one order can cover several jobs.
        $commitmentId = DB::table('construction_commitments')->insertGetId([
            'number' => 'PO-'.uniqid(),
            'type' => 'purchase_order',
            'status' => 'issued',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $lineId = DB::table('construction_commitment_lines')->insertGetId([
            'commitment_id' => $commitmentId,
            'job_id' => $this->job->getKey(),
            'cost_code_id' => $code->getKey(),
            'description' => 'Concrete grade 30',
            'amount' => $ordered,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $receiptId = DB::table('construction_goods_receipts')->insertGetId([
            'commitment_id' => $commitmentId,
            'number' => 'GRN-'.uniqid(),
            'received_on' => '2026-08-20',
            'status' => 'posted',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('construction_goods_receipt_lines')->insert([
            'goods_receipt_id' => $receiptId,
            'commitment_line_id' => $lineId,
            'job_id' => $this->job->getKey(),
            'cost_code_id' => $code->getKey(),
            'description' => 'Concrete grade 30',
            // Required by the table, and rightly: §6's whole point is that a delivery is a quantity at a rate rather
            // than a number of rupees. The accrual reads `amount`, so the figure here only has to be consistent.
            'quantity' => 100,
            'unit_of_measure' => 'm3',
            'unit_rate' => $received / 100,
            'amount' => $received,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        if ($invoiced !== null) {
            $invoiceId = DB::table('invoices')->insertGetId([
                'invoice_number' => 'INV-'.uniqid(),
                'kind' => 'purchase',
                'contact_id' => $this->supplierId(),
                'invoice_date' => '2026-09-05',
                'total' => $invoiced,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            DB::table('construction_invoice_allocations')->insert([
                'invoice_id' => $invoiceId,
                'job_id' => $this->job->getKey(),
                'cost_code_id' => $code->getKey(),
                'commitment_line_id' => $lineId,
                'amount' => $invoiced,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        return $lineId;
    }

    /** A supplier, because `invoices.contact_id` is a required foreign key. */
    private function supplierId(): int
    {
        return DB::table('contacts')->where('name', 'Concrete supplier')->value('id')
            ?? DB::table('contacts')->insertGetId([
                'name' => 'Concrete supplier',
                'kind' => 'supplier',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
    }

    /** A payable contract with one item, a live claim on it, and optionally an issued certificate. */
    private function subcontractClaim(
        float $claimed,
        ?float $certified = null,
        string $claimStatus = 'submitted',
        ?CostCode $code = null,
        string $certificateStatus = 'issued',
    ): int {
        $contractId = DB::table('construction_contracts')->insertGetId([
            'job_id' => $this->job->getKey(),
            'side' => 'payable',
            'contract_number' => 'SC-'.uniqid(),
            'title' => 'Cladding subcontract',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $itemId = DB::table('construction_contract_items')->insertGetId([
            'contract_id' => $contractId,
            'item_no' => '1.1',
            'description' => 'Supply and fix cladding',
            'cost_code_id' => $code?->getKey(),
            'scheduled_value' => 5_000_000,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $claimId = DB::table('construction_progress_claims')->insertGetId([
            'contract_id' => $contractId,
            'claim_number' => 'STMT-1',
            'period_end' => '2026-08-31',
            'status' => $claimStatus,
            'claimed_gross_to_date' => $claimed,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('construction_progress_claim_lines')->insert([
            'progress_claim_id' => $claimId,
            'contract_item_id' => $itemId,
            'cumulative_work_value' => $claimed,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        if ($certified !== null) {
            $certId = DB::table('construction_payment_certificates')->insertGetId([
                'contract_id' => $contractId,
                'certificate_number' => 'IPC-1',
                'sequence' => 1,
                'period_end' => '2026-08-31',
                'status' => $certificateStatus,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            DB::table('construction_certificate_lines')->insert([
                'payment_certificate_id' => $certId,
                'contract_item_id' => $itemId,
                'item_no' => '1.1',
                'description' => 'Supply and fix cladding',
                'cumulative_work_value' => $certified,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        return $itemId;
    }

    // ---------------------------------------------------------------- the reversal

    /**
     * **The whole of §4.5's mechanism in one test.**
     *
     * An accrual raised in August is off the job in September, and the Accrued column nets to nothing.
     */
    public function test_last_months_accrual_is_reversed_when_this_month_opens(): void
    {
        $august = $this->accrual('2026-08-20', 400_000);

        $run = $this->accruals->open('2026-09-01');

        $this->assertSame(1, $run->reversedCount);
        $this->assertSame(400_000.0, $run->reversedTotal);
        $this->assertNotNull($august->fresh()->reversed_by_id);

        $accrued = (float) CostEntry::query()->where('kind', CostEntry::KIND_ACCRUAL)->sum('amount');
        $this->assertSame(0.0, round($accrued, 2), 'the accrued column nets to nothing');
    }

    /**
     * **The reversal is `kind = accrual`, and this is the test that says why.**
     *
     * §3.5: Actual is `kind != accrual`. A reversal written as `kind = reversal` would land in the Actual column as
     * −400,000, understating the job's actual cost by exactly the accrual — a job that looked cheaper with nothing in
     * any query to explain it.
     */
    public function test_the_reversal_stays_inside_the_accrued_column(): void
    {
        $this->accrual('2026-08-20', 400_000);
        $this->accruals->open('2026-09-01');

        $actual = (float) CostEntry::query()->where('kind', '!=', CostEntry::KIND_ACCRUAL)->sum('amount');

        $this->assertSame(0.0, round($actual, 2), 'the reversal never touched the actual column');
    }

    /** It lands in the new month, not the old one — "at the opening of the next period". */
    public function test_the_reversal_lands_in_the_month_being_opened(): void
    {
        $this->accrual('2026-08-20', 400_000);
        $this->accruals->open('2026-09-01');

        $reversal = CostEntry::query()->whereNotNull('reverses_id')->firstOrFail();

        $this->assertSame('2026-09-01', $reversal->posting_period->toDateString());
        $this->assertSame('2026-09-01', $reversal->incurred_on->toDateString());
    }

    /** August's own total is untouched, which is what makes a closed month safe to have signed. */
    public function test_the_month_being_reversed_keeps_its_own_total(): void
    {
        $this->accrual('2026-08-20', 400_000);
        $this->accruals->open('2026-09-01');

        $august = (float) CostEntry::query()->inPeriod('2026-08-01')->sum('amount');

        $this->assertSame(400_000.0, round($august, 2));
    }

    /**
     * **It does not reverse its own reversals**, which is what `reverses_id` is for.
     *
     * Without that guard, October would reverse September's reversal, November would reverse October's, and the accrued
     * column would oscillate forever with a growing pile of entries and nothing to say what any of them was for.
     */
    public function test_a_reversal_is_never_itself_reversed(): void
    {
        $this->accrual('2026-08-20', 400_000);
        $this->accruals->open('2026-09-01');

        $october = $this->accruals->open('2026-10-01');

        $this->assertSame(0, $october->reversedCount, 'nothing was outstanding');
        $this->assertSame(2, CostEntry::query()->where('kind', CostEntry::KIND_ACCRUAL)->count());
    }

    /** And an accrual is reversed once, not once per month for the rest of the job. */
    public function test_the_same_accrual_is_not_reversed_twice(): void
    {
        $this->accrual('2026-08-20', 400_000);
        $this->accruals->open('2026-09-01');
        $this->accruals->open('2026-09-01');

        $this->assertSame(1, CostEntry::query()->whereNotNull('reverses_id')->count());
    }

    /**
     * **This month's own accruals are reversed too, and that is the correction of a first draft.**
     *
     * Restricting the unwind to *earlier* periods reads as a faithful transcription of §4.5's "auto-reverse at the
     * opening of the next period", and it is wrong — because §4.5 is not the only thing that raises accruals.
     * `GoodsReceiptService` raises one the moment a delivery is posted, since §5's committed-cost report is worthless if
     * a delivery takes a month to appear. A pass that skipped the current period would find that delivery *outstanding*,
     * raise a second accrual, and double the job's accrued cost for the month — §4.5's own failure, reached from the
     * opposite direction. `test_a_delivery_accrued_at_receipt_is_not_accrued_twice` is that bug as a test.
     *
     * So the rule is: wipe everything standing, recompute. The invariant afterwards is that the only standing accruals
     * are the ones this run raised.
     */
    public function test_this_months_accruals_are_reversed_and_recomputed(): void
    {
        $this->accrual('2026-09-10', 400_000);

        $this->assertSame(1, $this->accruals->open('2026-09-01')->reversedCount);
        $this->assertSame(
            0.0,
            round((float) CostEntry::query()->where('kind', CostEntry::KIND_ACCRUAL)->sum('amount'), 2),
            'and with no order behind it there is nothing to re-raise, so the position is nil',
        );
    }

    /**
     * **The double-count the wipe-and-recompute rule prevents.**
     *
     * A delivery posted in September is accrued by the goods receipt on the day it arrives, because §5's report needs
     * the cost then. Opening September must not accrue it a second time.
     */
    public function test_a_delivery_accrued_at_receipt_is_not_accrued_twice(): void
    {
        $this->orderAndDeliver(ordered: 500_000, received: 400_000);
        // What `GoodsReceiptService` wrote on the day it arrived, in the same month being opened.
        $this->accrual('2026-09-20', 400_000);

        $this->accruals->open('2026-09-01');

        $this->assertSame(
            400_000.0,
            round((float) CostEntry::query()->where('kind', CostEntry::KIND_ACCRUAL)->sum('amount'), 2),
            'one delivery, one accrual — not 800,000',
        );
    }

    /** A memo accrual stays memo through the reversal — it deliberately never reaches the accounts either way. */
    public function test_a_memo_accrual_reverses_as_memo(): void
    {
        $this->accrual('2026-08-20', 400_000, ['gl_treatment' => CostEntry::GL_MEMO]);
        $this->accruals->open('2026-09-01');

        $reversal = CostEntry::query()->whereNotNull('reverses_id')->firstOrFail();

        $this->assertSame(CostEntry::GL_MEMO, $reversal->gl_treatment);
    }

    /** The reversal carries the credit the original owed, so §11a's posting debits the accrual account back. */
    public function test_the_reversal_owes_the_same_account(): void
    {
        $this->accrual('2026-08-20', 400_000);
        $this->accruals->open('2026-09-01');

        $reversal = CostEntry::query()->whereNotNull('reverses_id')->firstOrFail();

        $this->assertSame('grni', $reversal->gl_purpose);
    }

    /** One batch for the unwind, which is §3.2's "one thing to reverse" applied to the reversal itself. */
    public function test_the_reversal_is_one_batch(): void
    {
        $this->accrual('2026-08-20', 400_000);
        $this->accrual('2026-08-21', 100_000);

        $run = $this->accruals->open('2026-09-01');

        $this->assertNotNull($run->reversalBatch);
        $this->assertSame(CostBatch::KIND_ACCRUAL_REVERSAL, $run->reversalBatch->kind);
        $this->assertSame(2, $run->reversalBatch->entries()->count());
        $this->assertSame(-500_000.0, $run->reversalBatch->total());
    }

    /**
     * **`accrual_reversal` is its own batch kind rather than `reversal`.**
     *
     * A `reversal` batch backs out something that was wrong; this backs out something that was right last month.
     * Sharing one kind would make "how often does this company correct itself" unanswerable, since twelve routine
     * unwinds a year would swamp the corrections.
     */
    public function test_a_routine_unwind_is_distinguishable_from_a_correction(): void
    {
        $accrual = $this->accrual('2026-08-20', 400_000);
        $this->ledger->reverse($accrual, 'The delivery note was for the wrong job.');

        $this->accrual('2026-08-22', 250_000);
        $this->accruals->open('2026-09-01');

        $this->assertSame(1, CostBatch::query()->where('kind', CostBatch::KIND_ACCRUAL_REVERSAL)->count());
        $this->assertSame(
            0,
            CostBatch::query()->where('kind', CostBatch::KIND_REVERSAL)->count(),
            'a corrected entry is reversed without a batch, so the two never mix',
        );
    }

    /**
     * An accrual already corrected is not reversed again.
     *
     * `reversed_by_id` is set by `CostLedger::reverse()` too, so a correction and the monthly unwind cannot both back
     * out the same entry — which would credit the job twice for one accrual.
     */
    public function test_an_already_corrected_accrual_is_left_alone(): void
    {
        $accrual = $this->accrual('2026-08-20', 400_000);
        $this->ledger->reverse($accrual, 'Wrong job.');

        $this->assertSame(0, $this->accruals->open('2026-09-01')->reversedCount);
    }

    /** Rolling into a closed month is refused: it would restate a total somebody has already signed off. */
    public function test_accruals_do_not_roll_into_a_closed_month(): void
    {
        CostPeriod::forDate('2026-09-01')->update(['status' => CostPeriod::STATUS_CLOSED]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('roll into an open month');
        $this->accruals->open('2026-09-01');
    }

    /**
     * The first month says so rather than saying nothing.
     *
     * "No accrual was outstanding" and "the reversal failed to find last month's accruals" look identical as silence,
     * and they are very different facts.
     */
    public function test_a_first_month_states_that_nothing_was_outstanding(): void
    {
        $run = $this->accruals->open('2026-09-01');

        $this->assertFalse($run->reversedSomething());
        $this->assertStringContainsString('nothing was reversed', $run->describe());
    }

    // ---------------------------------------------- goods received not invoiced

    /**
     * **§4.5's first accrual, re-raised because the invoice still has not come.**
     *
     * The delivery put cost on the job in August. September's open took it off, and this puts it back — because the
     * cost is still real and nobody has billed for it.
     */
    public function test_an_uninvoiced_delivery_is_re_accrued_into_the_new_month(): void
    {
        $this->orderAndDeliver(ordered: 500_000, received: 400_000);

        $run = $this->accruals->open('2026-09-01');

        $this->assertTrue($run->goodsReceived->raisedSomething());
        $this->assertSame(400_000.0, $run->goodsReceived->total);
        $this->assertSame('grni', CostEntry::query()
            ->inPeriod('2026-09-01')->where('kind', CostEntry::KIND_ACCRUAL)->value('gl_purpose'));
    }

    /** Invoiced in full, nothing to accrue. The self-correction §4.5 is built on. */
    public function test_a_fully_invoiced_delivery_is_not_accrued(): void
    {
        $this->orderAndDeliver(ordered: 500_000, received: 400_000, invoiced: 400_000);

        $this->assertFalse($this->accruals->open('2026-09-01')->goodsReceived->raisedSomething());
    }

    /** Part-invoiced accrues the balance and nothing more. */
    public function test_a_part_invoiced_delivery_accrues_only_the_balance(): void
    {
        $this->orderAndDeliver(ordered: 500_000, received: 400_000, invoiced: 150_000);

        $this->assertSame(250_000.0, $this->accruals->open('2026-09-01')->goodsReceived->total);
    }

    /**
     * **Over-invoiced accrues nothing rather than a negative.**
     *
     * A supplier who has billed more than they delivered is a three-way-match variance (§5), not an accrual. A negative
     * accrual here would credit the job for money the supplier has already charged, and the variance report is where
     * that argument belongs.
     */
    public function test_an_over_invoiced_delivery_does_not_produce_a_negative_accrual(): void
    {
        $this->orderAndDeliver(ordered: 500_000, received: 400_000, invoiced: 460_000);

        $run = $this->accruals->open('2026-09-01');

        $this->assertFalse($run->goodsReceived->raisedSomething());
        $this->assertSame(0.0, $run->goodsReceived->total);
    }

    /**
     * **A delivery with no order behind it is reported, not guessed at.**
     *
     * There is no link through which an invoice could ever be matched to it, so "not invoiced" is unanswerable — and a
     * guess either way would be wrong on half the deliveries in the country.
     */
    public function test_a_delivery_with_no_order_is_named_rather_than_accrued(): void
    {
        $receiptId = DB::table('construction_goods_receipts')->insertGetId([
            'number' => 'GRN-DIRECT',
            'received_on' => '2026-08-20',
            'status' => 'posted',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('construction_goods_receipt_lines')->insert([
            'goods_receipt_id' => $receiptId,
            'job_id' => $this->job->getKey(),
            'cost_code_id' => $this->material->getKey(),
            'description' => 'Sand, cash purchase',
            'quantity' => 10,
            'unit_of_measure' => 'm3',
            'unit_rate' => 4_000,
            'amount' => 40_000,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $run = $this->accruals->open('2026-09-01');

        $this->assertFalse($run->goodsReceived->raisedSomething());
        $this->assertStringContainsString('name no purchase order', $run->goodsReceived->describe());
    }

    /** A draft receipt is not a delivery. Nothing arrived until somebody posted it. */
    public function test_a_draft_receipt_is_not_accrued(): void
    {
        $this->orderAndDeliver(ordered: 500_000, received: 400_000);
        DB::table('construction_goods_receipts')->update(['status' => 'draft']);

        $this->assertFalse($this->accruals->open('2026-09-01')->goodsReceived->raisedSomething());
    }

    /** A delivery dated after the month being accrued is next month's problem. */
    public function test_a_delivery_after_the_period_end_is_not_accrued(): void
    {
        $this->orderAndDeliver(ordered: 500_000, received: 400_000);
        DB::table('construction_goods_receipts')->update(['received_on' => '2026-10-05']);

        $this->assertFalse($this->accruals->open('2026-09-01')->goodsReceived->raisedSomething());
    }

    // ------------------------------------ subcontract work done not certified

    /**
     * **§4.5's second accrual.** The subcontractor has claimed, nobody has certified, and the work is done.
     */
    public function test_claimed_and_uncertified_subcontract_work_is_accrued(): void
    {
        $this->subcontractClaim(claimed: 1_200_000, code: $this->subcontract);

        $run = $this->accruals->open('2026-09-01');

        $this->assertTrue($run->subcontract->raisedSomething());
        $this->assertSame(1_200_000.0, $run->subcontract->total);
        $this->assertSame('subcontract_accrual', CostEntry::query()
            ->where('cost_code_id', $this->subcontract->getKey())->value('gl_purpose'));
    }

    /** Certified in part accrues the balance. The certificate is the real cost; the rest is still an accrual. */
    public function test_part_certified_work_accrues_only_the_uncertified_balance(): void
    {
        $this->subcontractClaim(claimed: 1_200_000, certified: 900_000, code: $this->subcontract);

        $this->assertSame(300_000.0, $this->accruals->open('2026-09-01')->subcontract->total);
    }

    /** Certified in full accrues nothing. */
    public function test_fully_certified_work_is_not_accrued(): void
    {
        $this->subcontractClaim(claimed: 1_200_000, certified: 1_200_000, code: $this->subcontract);

        $this->assertFalse($this->accruals->open('2026-09-01')->subcontract->raisedSomething());
    }

    /**
     * **A draft claim accrues nothing.**
     *
     * A draft is a subcontractor's working paper nobody has received. Accruing off it would put cost on a job on the
     * strength of a document that may never be sent.
     */
    public function test_a_draft_claim_is_not_accrued(): void
    {
        $this->subcontractClaim(claimed: 1_200_000, claimStatus: 'draft', code: $this->subcontract);

        $this->assertFalse($this->accruals->open('2026-09-01')->subcontract->raisedSomething());
    }

    /**
     * **A draft certificate certifies nothing**, so the whole claim is still accrued.
     *
     * §10.1 keeps the claim and the certificate apart precisely because the certifier's figure is not the claimant's.
     * Netting an accrual off against a document nobody has signed would understate the job by whatever a draft happened
     * to say.
     */
    public function test_a_draft_certificate_does_not_reduce_the_accrual(): void
    {
        $this->subcontractClaim(
            claimed: 1_200_000,
            certified: 900_000,
            code: $this->subcontract,
            certificateStatus: 'draft',
        );

        $this->assertSame(1_200_000.0, $this->accruals->open('2026-09-01')->subcontract->total);
    }

    /** And a void one has been withdrawn, so it certifies nothing either. */
    public function test_a_void_certificate_does_not_reduce_the_accrual(): void
    {
        $this->subcontractClaim(
            claimed: 1_200_000,
            certified: 900_000,
            code: $this->subcontract,
            certificateStatus: 'void',
        );

        $this->assertSame(1_200_000.0, $this->accruals->open('2026-09-01')->subcontract->total);
    }

    /** A receivable contract is the client's side and is revenue, not cost. It is not accrued here. */
    public function test_the_head_contract_is_not_a_subcontract_accrual(): void
    {
        $this->subcontractClaim(claimed: 1_200_000, code: $this->subcontract);
        DB::table('construction_contracts')->update(['side' => 'receivable']);

        $this->assertFalse($this->accruals->open('2026-09-01')->subcontract->raisedSomething());
    }

    /**
     * **An item with no cost code is named rather than booked somewhere plausible.**
     *
     * A contract-level accrual would have to pick one code for a subcontract spanning six, and picking would be a
     * guess. §18.1's rule about a healthy figure hiding an absence: the total would look fine and the cost would be
     * nowhere.
     */
    public function test_an_item_with_no_cost_code_is_reported(): void
    {
        $this->subcontractClaim(claimed: 1_200_000, code: null);

        $run = $this->accruals->open('2026-09-01');

        $this->assertFalse($run->subcontract->raisedSomething());
        $this->assertStringContainsString('carry no cost code', $run->subcontract->describe());
        $this->assertStringContainsString('picking would be a guess', $run->subcontract->describe());
    }

    /** A refusal from the ledger's own rules is named too, rather than swallowed. */
    public function test_a_switched_off_cost_code_is_named(): void
    {
        $this->subcontractClaim(claimed: 1_200_000, code: $this->subcontract);
        $this->subcontract->update(['is_active' => false]);

        $run = $this->accruals->open('2026-09-01');

        $this->assertFalse($run->subcontract->raisedSomething());
        $this->assertStringContainsString('switched off', $run->subcontract->describe());
    }

    // ---------------------------------------------------------------- the whole cycle

    /**
     * **The self-correction, end to end.**
     *
     * August: a delivery, accrued. September's open: reversed, and re-accrued because no invoice came. October's open:
     * reversed, and *not* re-accrued because the invoice arrived. The accrued column is empty and nobody matched
     * anything off line by line.
     */
    public function test_the_cycle_self_corrects_when_the_invoice_finally_arrives(): void
    {
        $lineId = $this->orderAndDeliver(ordered: 500_000, received: 400_000);
        // August's own accrual, as the goods receipt would have raised it.
        $this->accrual('2026-08-20', 400_000);

        $september = $this->accruals->open('2026-09-01');
        $this->assertSame(1, $september->reversedCount);
        $this->assertSame(400_000.0, $september->goodsReceived->total);

        // The invoice arrives.
        $invoiceId = DB::table('invoices')->insertGetId([
            'invoice_number' => 'INV-LATE',
            'kind' => 'purchase',
            'contact_id' => $this->supplierId(),
            'invoice_date' => '2026-10-03',
            'total' => 400_000,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('construction_invoice_allocations')->insert([
            'invoice_id' => $invoiceId,
            'job_id' => $this->job->getKey(),
            'cost_code_id' => $this->material->getKey(),
            'commitment_line_id' => $lineId,
            'amount' => 400_000,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $october = $this->accruals->open('2026-10-01');

        $this->assertSame(1, $october->reversedCount, 'September\'s re-accrual came off');
        $this->assertFalse($october->goodsReceived->raisedSomething(), 'and nothing replaced it');

        $accrued = (float) CostEntry::query()->where('kind', CostEntry::KIND_ACCRUAL)->sum('amount');
        $this->assertSame(0.0, round($accrued, 2), 'the accrued column is empty with no matching-off anywhere');
    }

    /**
     * And the month-open is idempotent, which is what makes it safe to attach to a button somebody will press twice.
     */
    public function test_opening_the_same_month_twice_changes_nothing(): void
    {
        $this->orderAndDeliver(ordered: 500_000, received: 400_000);
        $this->accrual('2026-08-20', 400_000);

        $this->accruals->open('2026-09-01');
        $afterFirst = (float) CostEntry::query()->sum('amount');

        $this->accruals->open('2026-09-01');

        $this->assertSame($afterFirst, (float) CostEntry::query()->sum('amount'));
    }

    /**
     * Both accruals in one month, and the run describes each separately.
     *
     * One combined figure would answer "how much is accrued" and lose "how much of it is somebody's delivery and how
     * much is somebody's work" — which are two different conversations with two different people.
     */
    public function test_the_two_accruals_are_reported_separately(): void
    {
        $this->orderAndDeliver(ordered: 500_000, received: 400_000);
        $this->subcontractClaim(claimed: 1_200_000, code: $this->subcontract);

        $run = $this->accruals->open('2026-09-01');

        $this->assertSame(400_000.0, $run->goodsReceived->total);
        $this->assertSame(1_200_000.0, $run->subcontract->total);
        $this->assertSame(1_600_000.0, $run->total());
        $this->assertStringContainsString('Goods received not invoiced', $run->describe());
        $this->assertStringContainsString('Subcontract work done not certified', $run->describe());
    }

    /** Each accrual is its own batch, so either can be examined or backed out without the other. */
    public function test_each_accrual_is_its_own_batch(): void
    {
        $this->orderAndDeliver(ordered: 500_000, received: 400_000);
        $this->subcontractClaim(claimed: 1_200_000, code: $this->subcontract);

        $run = $this->accruals->open('2026-09-01');

        $this->assertNotSame($run->goodsReceived->batch->getKey(), $run->subcontract->batch->getKey());
        $this->assertSame(CostBatch::KIND_ACCRUAL, $run->goodsReceived->batch->kind);
    }

    /**
     * **Nothing in this service names a `ConstructionContracts` class.**
     *
     * `construction_contracts` already declares an edge to `construction_costing`, so an import the other way would be
     * a two-cycle and `TANGLED_MODULE_BUDGET = 0` would be right to fail. The tables are read with the query builder,
     * which is the same discipline §17.6's exposure hours use across the field boundary — and this asserts it rather
     * than trusting it, because the failure is a boundary test somebody would be tempted to appease by raising a budget.
     */
    public function test_the_accrual_service_names_no_contracts_class(): void
    {
        $source = file_get_contents(
            base_path('app/Modules/ConstructionCosting/Services/AccrualService.php')
        );

        $this->assertStringNotContainsString('App\\Modules\\ConstructionContracts', $source);
        // And it does read those tables, so the assertion above is about a real temptation rather than a vacuous one.
        $this->assertStringContainsString("'construction_progress_claim_lines", $source);
        $this->assertStringContainsString("'construction_certificate_lines", $source);
    }
}
