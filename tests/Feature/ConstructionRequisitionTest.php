<?php

namespace Tests\Feature;

use App\Modules\Construction\Models\CostCode;
use App\Modules\Construction\Models\Job;
use App\Modules\ConstructionCosting\Filament\Resources\Requisitions\Pages\ListRequisitions;
use App\Modules\ConstructionCosting\Models\Commitment;
use App\Modules\ConstructionCosting\Models\Requisition;
use App\Modules\ConstructionCosting\Models\RequisitionLine;
use App\Modules\ConstructionCosting\Services\CommitmentService;
use App\Modules\ConstructionCosting\Services\RequisitionService;
use App\Modules\Core\Models\CompanyModule;
use InvalidArgumentException;
use Livewire\Livewire;
use Tests\AccountingTestCase;
use Tests\Concerns\InteractsWithTenant;

/**
 * The demand document — `docs/construction-management-plan.md` §5, Phase 5b.
 *
 * §5's reason for this table is an absence: "nothing here has a demand document". Without one, the first record of a
 * need is the order raised to satisfy it, so **what site asked for and nobody has ordered yet** has no answer and the
 * buyer's queue lives in somebody's inbox.
 *
 * Three properties carry the file, and each of them is a way the simpler shape loses a real need:
 *
 *  - **Asking commits nothing.** Approving says the need is real; money is committed when the order that follows is
 *    *issued*. Four steps, three people, and one place where money moves.
 *  - **Outstanding is `requested − ordered`, computed** from the order lines that name each request line — many
 *    orders to one line, because twenty tonnes now and twenty in March is ordinary.
 *  - **A cancelled order leaves its line outstanding again**, because that is the state site is in. Counting it would
 *    leave a need nobody is chasing and nothing showing it.
 */
class ConstructionRequisitionTest extends AccountingTestCase
{
    use InteractsWithTenant;

    private Job $job;

    private CostCode $material;

    private RequisitionService $requisitions;

    private CommitmentService $commitments;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs($this->makeUser('Administrator', 'requisitions@test.local'));
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
            'code' => '03.100', 'name' => 'Reinforcement', 'cost_type' => CostCode::TYPE_MATERIAL, 'unit' => 't',
        ]);

        $this->requisitions = app(RequisitionService::class);
        $this->commitments = app(CommitmentService::class);
    }

    /** An approved request for 40 t of rebar, which is where most of these tests start. */
    private function approvedRequest(float $quantity = 40, ?int $codeId = null): Requisition
    {
        $requisition = $this->requisitions->create($this->job, ['required_by' => '2026-09-15']);

        $this->requisitions->addLine($requisition, [
            'description' => 'Rebar, high tensile, 16mm',
            'quantity' => $quantity,
            'unit_of_measure' => 't',
            'estimated_rate' => 250_000,
            'cost_code_id' => $codeId,
        ]);

        $this->requisitions->submit($requisition->refresh());

        return $this->requisitions->approve($requisition->refresh());
    }

    private function firstLine(Requisition $requisition): RequisitionLine
    {
        return $requisition->lines()->orderBy('id')->firstOrFail();
    }

    // ------------------------------------------------------------------ raising

    public function test_a_requisition_is_numbered_in_the_years_series(): void
    {
        $year = now()->year;

        $this->assertSame("REQ-{$year}-0001", $this->requisitions->create($this->job)->number);
        $this->assertSame("REQ-{$year}-0002", $this->requisitions->create($this->job)->number);
    }

    public function test_it_records_who_asked_and_when(): void
    {
        $requisition = $this->requisitions->create($this->job);

        $this->assertSame(auth()->id(), $requisition->requested_by);
        $this->assertSame(now()->toDateString(), $requisition->requested_on->toDateString());
        $this->assertSame(Requisition::STATUS_DRAFT, $requisition->status);
    }

    /** The estimate follows quantity × rate, and is only ever an estimate. */
    public function test_the_estimate_is_computed_and_is_not_a_commitment(): void
    {
        $requisition = $this->approvedRequest(40);

        $this->assertEquals(10_000_000, $this->firstLine($requisition)->estimated_amount);
        $this->assertSame(10_000_000.0, $requisition->estimatedTotal());
        // Nothing is committed by asking: that is the whole reason the approval gate can be cheap.
        $this->assertSame([], $this->commitments->openByCode($this->job));
    }

    /** The cost code is optional on a request — site asks, the buyer codes it. */
    public function test_a_line_needs_no_cost_code(): void
    {
        $line = $this->firstLine($this->approvedRequest());

        $this->assertNull($line->cost_code_id);
    }

    public function test_a_line_needs_a_quantity(): void
    {
        $requisition = $this->requisitions->create($this->job);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('quantity greater than zero');

        $this->requisitions->addLine($requisition, ['description' => 'Rebar', 'quantity' => 0]);
    }

    public function test_an_empty_request_cannot_be_submitted(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('asks for nothing');

        $this->requisitions->submit($this->requisitions->create($this->job));
    }

    /** An approved request is not added to: the approval was for what it said at the time. */
    public function test_an_approved_request_refuses_a_new_line(): void
    {
        $requisition = $this->approvedRequest();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Raise another request');

        $this->requisitions->addLine($requisition, ['description' => 'More rebar', 'quantity' => 5]);
    }

    // ------------------------------------------------------------------ the gate

    /** A rejection needs a reason, and leaves the request editable — "not like that, like this". */
    public function test_a_rejection_needs_a_reason_and_reopens_the_request(): void
    {
        $requisition = $this->approvedRequest();

        try {
            $this->requisitions->reject($requisition, ' ');
            $this->fail('A rejection with no reason should be refused.');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('entitled to know why', $e->getMessage());
        }

        $rejected = $this->requisitions->reject($requisition->refresh(), 'Use 20mm, not 16mm.');

        $this->assertSame(Requisition::STATUS_REJECTED, $rejected->status);
        $this->assertTrue($rejected->isEditable(), 'site can amend and resubmit');

        // And it can go round again rather than being retyped from memory.
        $this->requisitions->addLine($rejected, ['description' => 'Rebar 20mm', 'quantity' => 40]);
        $this->assertSame(Requisition::STATUS_SUBMITTED, $this->requisitions->submit($rejected->refresh())->status);
    }

    public function test_a_request_cannot_be_ordered_from_until_it_is_approved(): void
    {
        $requisition = $this->requisitions->create($this->job);
        $this->requisitions->addLine($requisition, ['description' => 'Rebar', 'quantity' => 10]);
        $this->requisitions->submit($requisition->refresh());

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Only an approved request');

        $this->requisitions->order($requisition->refresh(), $this->commitments->create());
    }

    // ------------------------------------------------------------------ ordering

    /**
     * **The exit condition of this sub-phase.** Ordering part of a request leaves the rest outstanding.
     */
    public function test_ordering_part_of_a_request_leaves_the_rest_outstanding(): void
    {
        $requisition = $this->approvedRequest(40);
        $line = $this->firstLine($requisition);
        $order = $this->commitments->create();

        $this->requisitions->order($requisition, $order, [$line->getKey() => 20], [$line->getKey() => $this->material->getKey()]);

        $line->refresh();

        $this->assertSame(20.0, $line->orderedQuantity());
        $this->assertSame(20.0, $line->outstandingQuantity());
        $this->assertSame(Requisition::STATUS_PARTIALLY_ORDERED, $requisition->refresh()->status);

        // The order line carries the job, the code and the link back to the request.
        $orderLine = $order->refresh()->lines()->firstOrFail();
        $this->assertSame($this->job->getKey(), $orderLine->job_id);
        $this->assertSame($this->material->getKey(), $orderLine->cost_code_id);
        $this->assertSame($line->getKey(), $orderLine->requisition_line_id);
        $this->assertEquals(5_000_000, $orderLine->amount, '20 t at the estimated 250,000');
    }

    /** The rest can go on a second order, which is what "twenty now, twenty in March" means. */
    public function test_the_remainder_can_be_ordered_separately(): void
    {
        $requisition = $this->approvedRequest(40);
        $line = $this->firstLine($requisition);

        $this->requisitions->order($requisition, $this->commitments->create(), [$line->getKey() => 20], [$line->getKey() => $this->material->getKey()]);
        $this->requisitions->order($requisition->refresh(), $this->commitments->create(), [$line->getKey() => 20], [$line->getKey() => $this->material->getKey()]);

        $line->refresh();

        $this->assertSame(40.0, $line->orderedQuantity());
        $this->assertSame(0.0, $line->outstandingQuantity());
        $this->assertSame(Requisition::STATUS_ORDERED, $requisition->refresh()->status);
        $this->assertSame(2, $line->commitmentLines()->count(), 'many orders to one request line');
    }

    /** Omitting the quantities orders whatever is left, which is the ordinary case. */
    public function test_ordering_with_no_quantities_takes_everything_outstanding(): void
    {
        $requisition = $this->approvedRequest(40, $this->material->getKey());

        $this->requisitions->order($requisition, $order = $this->commitments->create());

        $this->assertSame(40.0, (float) $order->refresh()->lines()->firstOrFail()->quantity);
        $this->assertSame(Requisition::STATUS_ORDERED, $requisition->refresh()->status);
    }

    public function test_ordering_more_than_is_outstanding_is_refused(): void
    {
        $requisition = $this->approvedRequest(40, $this->material->getKey());
        $line = $this->firstLine($requisition);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('outstanding');

        $this->requisitions->order($requisition, $this->commitments->create(), [$line->getKey() => 60]);
    }

    /**
     * The order refuses without a cost code, and the message says whose job it is.
     *
     * The committed figure has to land somewhere the cost report reads, which is exactly why the request was allowed
     * to leave it out.
     */
    public function test_an_order_line_without_a_cost_code_is_refused(): void
    {
        $requisition = $this->approvedRequest(40);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('the buyer decides which code');

        $this->requisitions->order($requisition, $this->commitments->create());
    }

    /** A fully ordered request is not in an orderable state, and the refusal names the rule rather than the symptom. */
    public function test_ordering_a_fully_ordered_request_is_refused(): void
    {
        $requisition = $this->approvedRequest(40, $this->material->getKey());
        $this->requisitions->order($requisition, $this->commitments->create());

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Only an approved request');

        $this->requisitions->order($requisition->refresh(), $this->commitments->create());
    }

    /** And ordering zero of everything is refused too, which is the case the status guard cannot catch. */
    public function test_ordering_nothing_at_all_is_refused(): void
    {
        $requisition = $this->approvedRequest(40, $this->material->getKey());
        $line = $this->firstLine($requisition);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('nothing to order');

        $this->requisitions->order($requisition, $this->commitments->create(), [$line->getKey() => 0]);
    }

    public function test_lines_cannot_be_added_to_an_issued_order(): void
    {
        $requisition = $this->approvedRequest(40, $this->material->getKey());
        $order = $this->commitments->create();
        $this->commitments->addLine($order, $this->job, $this->material, ['description' => 'x', 'amount' => 1]);
        $this->commitments->approve($order->refresh());
        $this->commitments->issue($order->refresh());

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('working to the copy they were sent');

        $this->requisitions->order($requisition, $order->refresh());
    }

    /**
     * **A cancelled order leaves the need outstanding.**
     *
     * The state site is actually in. Counting a cancelled order as ordered would leave a need nobody is chasing,
     * with nothing on any screen showing it.
     */
    public function test_a_cancelled_order_puts_the_request_back_on_the_queue(): void
    {
        $requisition = $this->approvedRequest(40, $this->material->getKey());
        $order = $this->commitments->create();
        $this->requisitions->order($requisition, $order);

        $this->assertSame(Requisition::STATUS_ORDERED, $requisition->refresh()->status);

        $this->commitments->cancel($order->refresh(), 'Supplier could not supply.');
        $line = $this->firstLine($requisition);

        $this->assertSame(0.0, $line->orderedQuantity());
        $this->assertSame(40.0, $line->outstandingQuantity());

        // The status follows the rows once anything asks it to, rather than being remembered.
        $this->requisitions->refreshOrderedStatus($requisition->refresh());
        $this->assertSame(Requisition::STATUS_APPROVED, $requisition->refresh()->status);
        $this->assertTrue($requisition->isOrderable(), 'back on the buyer\'s queue');
    }

    /** Money is committed by issuing the order, not by asking or approving. */
    public function test_money_is_committed_only_when_the_order_is_issued(): void
    {
        $requisition = $this->approvedRequest(40, $this->material->getKey());
        $order = $this->commitments->create();
        $this->requisitions->order($requisition, $order);

        $this->assertSame([], $this->commitments->openByCode($this->job), 'a draft order commits nothing');

        $this->commitments->approve($order->refresh());
        $this->assertSame([], $this->commitments->openByCode($this->job), 'nor does an approved one');

        $this->commitments->issue($order->refresh());

        $this->assertSame(
            [$this->material->getKey() => 10_000_000.0],
            $this->commitments->openByCode($this->job),
        );
    }

    // ------------------------------------------------------------------ the queue

    /** The buyer's queue: approved with something outstanding, soonest needed first. */
    public function test_the_queue_is_ordered_by_when_it_is_needed(): void
    {
        $later = $this->requisitions->create($this->job, ['required_by' => '2026-12-01']);
        $this->requisitions->addLine($later, ['description' => 'Blockwork', 'quantity' => 100]);
        $this->requisitions->approve($later->refresh());

        $sooner = $this->approvedRequest();

        $undated = $this->requisitions->create($this->job);
        $this->requisitions->addLine($undated, ['description' => 'Sundries', 'quantity' => 1]);
        $this->requisitions->approve($undated->refresh());

        $queue = Requisition::query()->toOrder()->pluck('number')->all();

        $this->assertSame(
            [$sooner->number, $later->number, $undated->number],
            $queue,
            'soonest first, and an undated request last rather than absent',
        );
    }

    public function test_a_fully_ordered_request_leaves_the_queue(): void
    {
        $requisition = $this->approvedRequest(40, $this->material->getKey());
        $this->requisitions->order($requisition, $this->commitments->create());

        $this->assertNotContains(
            $requisition->number,
            Requisition::query()->toOrder()->pluck('number')->all(),
        );
    }

    // ------------------------------------------------------------------ the screens

    public function test_the_register_renders_with_what_is_still_to_order(): void
    {
        $requisition = $this->approvedRequest(40, $this->material->getKey());
        $line = $this->firstLine($requisition);
        $this->requisitions->order($requisition, $this->commitments->create(), [$line->getKey() => 20]);

        Livewire::test(ListRequisitions::class)
            ->assertSuccessful()
            ->assertSee($requisition->number)
            ->assertSee('Partially ordered');
    }

    public function test_the_approve_action_opens_it_for_ordering(): void
    {
        $requisition = $this->requisitions->create($this->job);
        $this->requisitions->addLine($requisition, ['description' => 'Rebar', 'quantity' => 10]);
        $this->requisitions->submit($requisition->refresh());

        Livewire::test(ListRequisitions::class)
            ->callTableAction('approve', $requisition->refresh());

        $this->assertSame(Requisition::STATUS_APPROVED, $requisition->refresh()->status);
    }

    /** The order action raises a draft order when none is named, so a buyer can act from the queue in one click. */
    public function test_the_order_action_raises_a_draft_order(): void
    {
        $requisition = $this->approvedRequest(40, $this->material->getKey());

        Livewire::test(ListRequisitions::class)
            ->callTableAction('order', $requisition, ['commitment_id' => null]);

        $order = Commitment::query()->firstOrFail();

        $this->assertSame(Commitment::STATUS_DRAFT, $order->status);
        $this->assertStringContainsString($requisition->number, $order->description);
        $this->assertSame(Requisition::STATUS_ORDERED, $requisition->refresh()->status);
    }
}
