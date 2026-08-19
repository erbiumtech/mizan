<?php

namespace Tests\Feature;

use App\Modules\Construction\Models\CostCode;
use App\Modules\Construction\Models\Job;
use App\Modules\ConstructionCosting\Filament\Resources\Commitments\Pages\ListCommitments;
use App\Modules\ConstructionCosting\Models\Commitment;
use App\Modules\ConstructionCosting\Models\CommitmentLine;
use App\Modules\ConstructionCosting\Models\CommitmentRelief;
use App\Modules\ConstructionCosting\Services\CommitmentService;
use App\Modules\ConstructionCosting\Services\CostLedger;
use App\Modules\Core\Models\CompanyModule;
use InvalidArgumentException;
use Livewire\Livewire;
use Tests\AccountingTestCase;
use Tests\Concerns\InteractsWithTenant;

/**
 * Commitments and their relief — `docs/construction-management-plan.md` §5, Phase 5a.
 *
 * **Phase 5's stated exit condition is that open commitment is *provable* per cost code, and that closing an order
 * with a balance has an author and a reason.** Both are asserted here, and the word provable is doing work: the
 * figure is `line.amount − Σ reliefs` over issued orders, and every relief names what caused it. A stored balance
 * would be a second place for the same number to live, and the first thing that goes wrong is a receipt that
 * relieves while the total does not move.
 *
 * Three more properties, each rejecting a shape that fails quietly:
 *
 *  - **Approved is not committed.** Only an issued order puts money on the cost report, because an approved order
 *    in a drawer can still be withdrawn with a phone call.
 *  - **Relief happens once**, at the earlier of receipt or certificate; an invoice relieves only the unreceived
 *    balance. Double relief reads as a cost code with room in it that has none.
 *  - **The job is on the line, not the header**, so one order can serve three sites — which is what a supplier will
 *    actually honour.
 */
class ConstructionCommitmentTest extends AccountingTestCase
{
    use InteractsWithTenant;

    private Job $job;

    private CostCode $material;

    private CostCode $labour;

    private CommitmentService $commitments;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs($this->makeUser('Administrator', 'commitments@test.local'));
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
        $this->labour = CostCode::create([
            'code' => '02.100', 'name' => 'Steel fixing', 'cost_type' => CostCode::TYPE_LABOUR, 'unit' => 't',
        ]);

        $this->commitments = app(CommitmentService::class);
    }

    /** An issued order for 1,000,000 of concrete against the one job, which is where most tests start. */
    private function issuedOrder(float $amount = 1_000_000, ?CostCode $code = null, ?Job $job = null): Commitment
    {
        $commitment = $this->commitments->create();

        $this->commitments->addLine(
            $commitment,
            $job ?? $this->job,
            $code ?? $this->material,
            ['description' => 'Ready-mix, 40 m3', 'quantity' => 400, 'rate' => $amount / 400],
        );

        $this->commitments->approve($commitment->refresh());

        return $this->commitments->issue($commitment->refresh());
    }

    private function firstLine(Commitment $commitment): CommitmentLine
    {
        return $commitment->lines()->orderBy('id')->firstOrFail();
    }

    // ------------------------------------------------------------------ raising

    public function test_an_order_is_numbered_in_its_own_yearly_series(): void
    {
        $year = now()->year;

        $this->assertSame("PO-{$year}-0001", $this->commitments->create()->number);
        $this->assertSame("PO-{$year}-0002", $this->commitments->create()->number);

        // Subcontract orders and plant hire have their own series, because a supplier quoting "SC-2026-0001" back
        // has said which document they mean.
        $this->assertSame("SC-{$year}-0001", $this->commitments->create(['type' => Commitment::TYPE_SUBCONTRACT])->number);
        $this->assertSame("PH-{$year}-0001", $this->commitments->create(['type' => Commitment::TYPE_PLANT_HIRE])->number);
    }

    /** The line carries the job, so one order can serve three sites (§5). */
    public function test_one_order_can_commit_against_several_jobs(): void
    {
        $second = Job::create(['code' => 'J-2', 'name' => 'Annexe']);
        $commitment = $this->commitments->create();

        $this->commitments->addLine($commitment, $this->job, $this->material, ['description' => 'To J-1', 'amount' => 600_000]);
        $this->commitments->addLine($commitment, $second, $this->material, ['description' => 'To J-2', 'amount' => 400_000]);

        $this->assertSame(1_000_000.0, $commitment->refresh()->orderedTotal());
        $this->assertSame(
            [$this->job->getKey(), $second->getKey()],
            $commitment->lines()->orderBy('id')->pluck('job_id')->all(),
        );
    }

    public function test_the_cost_type_is_snapshotted_from_the_code(): void
    {
        $commitment = $this->commitments->create();
        $line = $this->commitments->addLine($commitment, $this->job, $this->labour, [
            'description' => 'Fixing', 'amount' => 100_000,
        ]);

        $this->assertSame(CostCode::TYPE_LABOUR, $line->cost_type);

        // Re-typing the code in June must not restate what March committed — the same rule the cost ledger keeps.
        $this->labour->update(['cost_type' => CostCode::TYPE_SUBCONTRACT]);

        $this->assertSame(CostCode::TYPE_LABOUR, $line->refresh()->cost_type);
    }

    public function test_a_heading_code_cannot_take_commitment(): void
    {
        $heading = CostCode::create(['code' => '03', 'name' => 'Concrete', 'cost_type' => CostCode::TYPE_MATERIAL]);
        $this->material->update(['parent_id' => $heading->getKey()]);
        $commitment = $this->commitments->create();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('is a heading');

        $this->commitments->addLine($commitment, $this->job, $heading->refresh(), ['description' => 'x', 'amount' => 1]);
    }

    public function test_an_issued_order_refuses_a_new_line(): void
    {
        $commitment = $this->issuedOrder();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('working to the copy they were sent');

        $this->commitments->addLine($commitment, $this->job, $this->material, ['description' => 'more', 'amount' => 1]);
    }

    public function test_an_empty_order_cannot_be_approved(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('no lines');

        $this->commitments->approve($this->commitments->create());
    }

    // -------------------------------------------------- approved is not committed

    /**
     * **The distinction the register turns on.** Approving decides to spend; issuing commits.
     *
     * An approved order the supplier has not been sent can still be withdrawn with a phone call and no
     * consequence, so counting it would report money as committed that is not.
     */
    public function test_an_approved_order_commits_nothing_until_it_is_issued(): void
    {
        $commitment = $this->commitments->create();
        $this->commitments->addLine($commitment, $this->job, $this->material, ['description' => 'x', 'amount' => 500_000]);
        $this->commitments->approve($commitment->refresh());

        $this->assertSame(Commitment::STATUS_APPROVED, $commitment->refresh()->status);
        $this->assertSame([], $this->commitments->openByCode($this->job), 'nothing is committed yet');

        $this->commitments->issue($commitment->refresh());

        $this->assertSame(
            [$this->material->getKey() => 500_000.0],
            $this->commitments->openByCode($this->job),
        );
    }

    public function test_an_order_cannot_be_issued_before_it_is_approved(): void
    {
        $commitment = $this->commitments->create();
        $this->commitments->addLine($commitment, $this->job, $this->material, ['description' => 'x', 'amount' => 1]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('cannot be issued');

        $this->commitments->issue($commitment->refresh());
    }

    // ------------------------------------------------------------------ relief

    /**
     * **The exit condition.** Open commitment is `ordered − Σ reliefs`, and every relief names its cause.
     */
    public function test_open_commitment_is_provable_from_its_reliefs(): void
    {
        $commitment = $this->issuedOrder(1_000_000);
        $line = $this->firstLine($commitment);

        $this->commitments->relieve($line, CommitmentRelief::KIND_RECEIPT, 300_000);
        $this->commitments->relieve($line, CommitmentRelief::KIND_RECEIPT, 250_000);

        $line->refresh();

        $this->assertSame(550_000.0, $line->relievedTotal());
        $this->assertSame(450_000.0, $line->openAmount());
        $this->assertSame(450_000.0, $this->commitments->openFor($this->job, $this->material->getKey()));

        // And every figure is traceable: two rows, each naming what reduced the order.
        $this->assertSame(2, $line->reliefs()->count());
        $this->assertSame(
            [CommitmentRelief::KIND_RECEIPT, CommitmentRelief::KIND_RECEIPT],
            $line->reliefs()->orderBy('id')->pluck('kind')->all(),
        );
    }

    /** Relief moves an issued order to partially relieved, derived rather than remembered. */
    public function test_relieving_moves_the_status(): void
    {
        $commitment = $this->issuedOrder();

        $this->commitments->relieve($this->firstLine($commitment), CommitmentRelief::KIND_RECEIPT, 100_000);

        $this->assertSame(Commitment::STATUS_PARTIALLY_RELIEVED, $commitment->refresh()->status);
    }

    /**
     * **§5's double-relief hazard.** The receipt relieves; the invoice for the same goods does not relieve again.
     *
     * Get this wrong and the committed column reads as if the order were twice delivered — which reads as a cost
     * code with room left in it that has none.
     */
    public function test_an_invoice_relieves_only_the_unreceived_balance(): void
    {
        $commitment = $this->issuedOrder(1_000_000);
        $line = $this->firstLine($commitment);

        $this->commitments->relieve($line, CommitmentRelief::KIND_RECEIPT, 800_000);

        // An invoice for the whole order arrives. Only the 200,000 never received may be relieved by it.
        $relief = $this->commitments->relieve($line->refresh(), CommitmentRelief::KIND_INVOICE, 1_000_000);

        $this->assertNotNull($relief);
        $this->assertEquals(200_000, $relief->amount);
        $this->assertSame(0.0, $line->refresh()->openAmount(), 'the order is fully relieved, not doubly');
        $this->assertSame(1_000_000.0, $line->relievedTotal());
    }

    /** An invoice for goods already received relieves nothing, and that is correct rather than a fault. */
    public function test_an_invoice_for_received_goods_relieves_nothing(): void
    {
        $commitment = $this->issuedOrder(1_000_000);
        $line = $this->firstLine($commitment);

        $this->commitments->relieve($line, CommitmentRelief::KIND_RECEIPT, 1_000_000);

        $this->assertNull(
            $this->commitments->relieve($line->refresh(), CommitmentRelief::KIND_INVOICE, 1_000_000),
            'the receipt got there first, which is what should happen',
        );
        $this->assertSame(1_000_000.0, $line->refresh()->relievedTotal());
    }

    /** A cancelled receipt is a negative relief, not a deleted row: the history stays answerable. */
    public function test_relief_can_be_given_back_as_a_negative_row(): void
    {
        $commitment = $this->issuedOrder(1_000_000);
        $line = $this->firstLine($commitment);

        $this->commitments->relieve($line, CommitmentRelief::KIND_RECEIPT, 400_000);
        $this->commitments->relieve($line->refresh(), CommitmentRelief::KIND_RECEIPT, -400_000);

        $this->assertSame(1_000_000.0, $line->refresh()->openAmount());
        $this->assertSame(2, $line->reliefs()->count(), 'both rows survive');
    }

    /** Over-relief is a real condition with an answer, rather than one a heuristic leaves unclearable. */
    public function test_over_relief_is_visible_rather_than_impossible(): void
    {
        $commitment = $this->issuedOrder(1_000_000);

        $this->commitments->relieve($this->firstLine($commitment), CommitmentRelief::KIND_RECEIPT, 1_200_000);

        $this->assertTrue($commitment->refresh()->overRelieved());
        // Open reads zero rather than negative: the money is not owed back, it is over-delivered.
        $this->assertSame(0.0, $commitment->openTotal());
    }

    // ------------------------------------------------------------- closing

    /**
     * **The other half of the exit condition.** Closing writes off what is open, with an author and a reason.
     */
    public function test_closing_writes_off_the_balance_with_a_reason(): void
    {
        $commitment = $this->issuedOrder(1_000_000);
        $line = $this->firstLine($commitment);
        $this->commitments->relieve($line, CommitmentRelief::KIND_RECEIPT, 960_000);

        $this->commitments->close($commitment->refresh(), 'Delivered 9.6 t against 10 t and agreed to leave it.');
        $commitment->refresh();

        $this->assertSame(Commitment::STATUS_CLOSED, $commitment->status);
        $this->assertNotNull($commitment->closed_at);
        $this->assertNotNull($commitment->closed_by, 'somebody owns the write-off');
        $this->assertStringContainsString('9.6 t', $commitment->close_reason);

        // The write-off is a relief, so the committed column moves rather than the status disagreeing with it.
        $closeOut = $line->refresh()->reliefs()->where('kind', CommitmentRelief::KIND_CLOSE_OUT)->firstOrFail();
        $this->assertEquals(40_000, $closeOut->amount);
        $this->assertSame(0.0, $this->commitments->openFor($this->job, $this->material->getKey()));
    }

    public function test_closing_without_a_reason_is_refused(): void
    {
        $commitment = $this->issuedOrder();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('needs a reason');

        $this->commitments->close($commitment, '   ');
    }

    /** Cancelling is a different statement from closing, and both take the money off the report. */
    public function test_cancelling_an_issued_order_releases_the_commitment(): void
    {
        $commitment = $this->issuedOrder(1_000_000);

        $this->commitments->cancel($commitment, 'Ordered against the wrong job; reissued as PO-0007.');
        $commitment->refresh();

        $this->assertSame(Commitment::STATUS_CANCELLED, $commitment->status);
        $this->assertSame(0.0, $this->commitments->openFor($this->job, $this->material->getKey()));
        $this->assertSame(
            CommitmentRelief::KIND_CANCELLATION,
            $this->firstLine($commitment)->reliefs()->firstOrFail()->kind,
        );
    }

    /** A cancelled draft never committed anything, so it writes no relief at all. */
    public function test_cancelling_a_draft_writes_no_relief(): void
    {
        $commitment = $this->commitments->create();
        $this->commitments->addLine($commitment, $this->job, $this->material, ['description' => 'x', 'amount' => 5_000]);

        $this->commitments->cancel($commitment->refresh(), 'Raised in error.');

        $this->assertSame(0, $this->firstLine($commitment)->reliefs()->count());
    }

    public function test_a_closed_order_cannot_be_closed_again(): void
    {
        $commitment = $this->issuedOrder();
        $this->commitments->close($commitment, 'Finished.');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('already closed');

        $this->commitments->close($commitment->refresh(), 'Again.');
    }

    public function test_a_closed_order_stops_committing(): void
    {
        $commitment = $this->issuedOrder(1_000_000);
        $this->commitments->close($commitment, 'Finished.');

        $this->assertSame([], $this->commitments->openByCode($this->job));
    }

    /**
     * **Closing an order of more than one line, which is the case every test here was one line short of.**
     *
     * `relieve()` read the order back off `$line->commitment`, and lazy loading is disabled application-wide — so
     * the second time round the loop it threw, while the first line went through. Every order in this file had a
     * single line, so nothing saw it until a subcontract order arrived with one line per trade (Phase 6c). The
     * order is now fetched by key, and this asserts the whole loop rather than its first pass.
     */
    public function test_closing_an_order_of_several_lines_writes_off_every_one(): void
    {
        $commitment = $this->commitments->create();
        $this->commitments->addLine($commitment, $this->job, $this->material, [
            'description' => 'Ready-mix', 'quantity' => 100, 'rate' => 6_000,
        ]);
        $this->commitments->addLine($commitment, $this->job, $this->labour, [
            'description' => 'Steel fixing', 'quantity' => 40, 'rate' => 10_000,
        ]);
        $this->commitments->approve($commitment->refresh());
        $this->commitments->issue($commitment->refresh());

        $this->commitments->close($commitment->refresh(), 'The site closed early and the balance will not be spent.');

        $this->assertSame([], $this->commitments->openByCode($this->job));
        $this->assertSame(2, CommitmentRelief::query()
            ->where('kind', CommitmentRelief::KIND_CLOSE_OUT)
            ->count());
        $this->assertSame(1_000_000.0, $commitment->refresh()->relievedTotal());
    }

    // ------------------------------------------------- the four-column report

    /**
     * **The committed column, filled in.** It was `null` on every row until this phase.
     *
     * §3.5's table wanted committed beside actual, and the column read as an em dash because "nothing is on order"
     * was a statement the module could not make. It can now.
     */
    public function test_the_four_column_report_shows_open_commitment(): void
    {
        $this->issuedOrder(1_000_000);

        $row = collect(app(CostLedger::class)->fourColumnReport($this->job))->firstWhere('code', '03.100');

        $this->assertSame(1_000_000.0, $row['committed']);

        // And it falls as the order is relieved, because it is the open figure rather than the ordered one.
        $this->commitments->relieve(
            CommitmentLine::query()->where('cost_code_id', $this->material->getKey())->firstOrFail(),
            CommitmentRelief::KIND_RECEIPT,
            400_000,
        );

        $row = collect(app(CostLedger::class)->fourColumnReport($this->job))->firstWhere('code', '03.100');
        $this->assertSame(600_000.0, $row['committed']);
    }

    /** A code with an order and nothing else still appears: committed cost is the point of the column. */
    public function test_a_code_with_only_an_order_appears_on_the_report(): void
    {
        $this->issuedOrder(250_000, $this->labour);

        $codes = collect(app(CostLedger::class)->fourColumnReport($this->job))->pluck('code')->all();

        $this->assertContains('02.100', $codes);
    }

    /** And it rolls up the job tree, like every other figure on that report (§1.2). */
    public function test_commitment_rolls_up_to_a_parent_job(): void
    {
        $development = Job::create(['code' => 'D-1', 'name' => 'Development']);
        $this->job->update(['parent_id' => $development->getKey()]);

        $this->issuedOrder(1_000_000);

        $this->assertSame(
            1_000_000.0,
            $this->commitments->openFor($development->refresh(), $this->material->getKey()),
        );
    }

    // ------------------------------------------------------------------ the screen

    public function test_the_register_renders_with_ordered_and_open(): void
    {
        $commitment = $this->issuedOrder(1_000_000);
        $this->commitments->relieve($this->firstLine($commitment), CommitmentRelief::KIND_RECEIPT, 400_000);

        Livewire::test(ListCommitments::class)
            ->assertSuccessful()
            ->assertSee($commitment->number)
            ->assertSee('1,000,000.00')
            ->assertSee('600,000.00');
    }

    public function test_the_issue_action_puts_the_money_on_the_report(): void
    {
        $commitment = $this->commitments->create();
        $this->commitments->addLine($commitment, $this->job, $this->material, ['description' => 'x', 'amount' => 750_000]);
        $this->commitments->approve($commitment->refresh());

        Livewire::test(ListCommitments::class)
            ->callTableAction('issue', $commitment->refresh());

        $this->assertSame(Commitment::STATUS_ISSUED, $commitment->refresh()->status);
        $this->assertSame(750_000.0, $this->commitments->openFor($this->job, $this->material->getKey()));
    }

    public function test_the_close_action_demands_its_reason(): void
    {
        $commitment = $this->issuedOrder();

        Livewire::test(ListCommitments::class)
            ->callTableAction('close', $commitment, ['reason' => 'Supplier delivered short; agreed.']);

        $this->assertSame(Commitment::STATUS_CLOSED, $commitment->refresh()->status);
        $this->assertStringContainsString('delivered short', $commitment->close_reason);
    }
}
