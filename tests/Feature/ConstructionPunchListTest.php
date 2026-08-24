<?php

namespace Tests\Feature;

use App\Modules\Construction\Models\Job;
use App\Modules\Construction\Models\Location;
use App\Modules\ConstructionField\Filament\Resources\PunchLists\Pages\EditPunchList;
use App\Modules\ConstructionField\Filament\Resources\PunchLists\Pages\ListPunchLists;
use App\Modules\ConstructionField\Filament\Resources\PunchLists\RelationManagers\ItemsRelationManager;
use App\Modules\ConstructionField\Models\PunchInspection;
use App\Modules\ConstructionField\Models\PunchItem;
use App\Modules\ConstructionField\Models\PunchList;
use App\Modules\ConstructionField\Services\PunchListService;
use App\Modules\Core\Models\CompanyModule;
use Filament\Actions\Testing\TestAction;
use InvalidArgumentException;
use Livewire\Livewire;
use Tests\AccountingTestCase;
use Tests\Concerns\InteractsWithTenant;

/**
 * Punch and snag lists — §16.4, Phase 9f.
 *
 * **Two things on this register carry money, and both are asserted here.**
 *
 * `affects_practical_completion` is the flag §11's AIA holdback reads: retention is held back against the cost of
 * rectifying open items carrying it. `ConstructionRetentionTest` asserts the money side; this file asserts the register
 * produces the figure honestly, including what it says when items carry no estimate.
 *
 * And **the attempt count**, which §16.4 says a `closed_at` column cannot hold: "closed after three failed
 * re-inspections is a different fact from closed first time". Counted rather than inferred from status history — the
 * same discipline `tickets.reopened_count` keeps.
 *
 * **The segregation on this register is structural rather than granted.** An item closes only when an inspection is
 * recorded with a passing result; there is no way to set the status and no permission that lets somebody skip it. That
 * is stronger than a `Close` grant, because a grant can be given to the person who caused the defect and a missing
 * passed inspection cannot be given away at all.
 */
class ConstructionPunchListTest extends AccountingTestCase
{
    use InteractsWithTenant;

    private Job $job;

    private PunchListService $punch;

    private PunchList $list;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs($this->makeUser('Administrator', 'punch@test.local'));
        $this->setCurrentTenant();

        foreach (['construction', 'construction_field'] as $module) {
            CompanyModule::updateOrCreate(
                ['company_id' => $this->tenant->getKey(), 'module' => $module],
                ['licensed' => true, 'enabled' => true],
            );
        }
        modules()->flush();

        $this->job = Job::create(['code' => 'J-1', 'name' => 'Tower']);
        $this->punch = app(PunchListService::class);

        $this->list = $this->punch->openList($this->job, [
            'name' => 'Level 4 pre-handover walk',
            'kind' => PunchList::KIND_PRE_HANDOVER,
            'opened_on' => '2026-08-10',
        ]);
    }

    /** @param array<string, mixed> $attributes */
    private function item(array $attributes = [], ?PunchList $list = null): PunchItem
    {
        return $this->punch->addItem($list ?? $this->list, array_merge([
            'description' => 'Sealant missing at window head',
            'responsible_label' => 'Glazing Co',
            'priority' => 'medium',
        ], $attributes));
    }

    // ------------------------------------------------------------------ lists

    public function test_a_list_needs_a_name_and_a_kind(): void
    {
        foreach ([
            [['name' => ' '], 'needs a name'],
            [['kind' => 'whatever'], 'needs a kind'],
        ] as [$override, $expected]) {
            try {
                $this->punch->openList($this->job, array_merge([
                    'name' => 'A walk', 'kind' => PunchList::KIND_HANDOVER,
                ], $override));
                $this->fail("Expected a refusal mentioning: {$expected}");
            } catch (InvalidArgumentException $e) {
                $this->assertStringContainsString($expected, $e->getMessage());
            }
        }
    }

    /** **The internal sweep is not a document to hand over**, which is why the kind is separated at all. */
    public function test_an_internal_sweep_is_kept_out_of_the_external_lists(): void
    {
        $internal = $this->punch->openList($this->job, [
            'name' => 'Our own check', 'kind' => PunchList::KIND_INTERNAL,
        ]);

        $this->assertTrue($internal->isInternal());
        $this->assertSame(
            ['Level 4 pre-handover walk'],
            PunchList::query()->external()->pluck('name')->all(),
        );
    }

    public function test_items_are_numbered_per_list(): void
    {
        $this->assertSame('1', $this->item()->reference);
        $this->assertSame('2', $this->item(['description' => 'Paint scuffed'])->reference);

        $second = $this->punch->openList($this->job, ['name' => 'Level 5', 'kind' => PunchList::KIND_HANDOVER]);

        $this->assertSame('1', $this->item([], $second)->reference, 'the series is per list');
    }

    /** An imported list that starts at 100 continues from 101 — read off the number, not counted. */
    public function test_the_series_continues_from_whatever_is_highest(): void
    {
        $this->item(['reference' => '100']);

        $this->assertSame('101', $this->punch->nextReference($this->list));
    }

    public function test_an_item_needs_describing(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('needs describing');

        $this->item(['description' => '  ']);
    }

    /**
     * **A closed list with open items on it is a handover certificate nobody should have signed.**
     *
     * And the refusal names the count, because "three items outstanding" is actionable where "cannot close" is not.
     */
    public function test_a_list_cannot_close_while_items_are_open(): void
    {
        $item = $this->item();

        try {
            $this->punch->closeList($this->list);
            $this->fail('A list with open items should not close.');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('1 item(s) outstanding', $e->getMessage());
        }

        $this->punch->inspect($item, ['result' => PunchInspection::RESULT_PASSED, 'inspected_on' => '2026-08-20']);

        $closed = $this->punch->closeList($this->list->refresh(), '2026-08-21');

        $this->assertTrue($closed->isClosed());
        $this->assertSame('2026-08-21', $closed->closed_on->toDateString());
    }

    /** A rejected item settles a list too — "not a defect" is an outcome, not an evasion. */
    public function test_a_rejected_item_lets_the_list_close(): void
    {
        $this->punch->reject($this->item(), 'Design intent — the gap is specified.');

        $this->assertTrue($this->punch->closeList($this->list->refresh())->isClosed());
    }

    public function test_a_closed_list_takes_no_new_items(): void
    {
        $this->punch->reject($this->item(), 'Not a defect.');
        $this->punch->closeList($this->list->refresh());

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Open a new list');

        $this->item([], $this->list->refresh());
    }

    // ------------------------------------------------------------------ closing is what a passed inspection does

    /**
     * **The only route to a closed item.** Not a status somebody sets — the form cannot write it at all.
     */
    public function test_a_passed_inspection_closes_the_item_and_nothing_else_does(): void
    {
        $item = $this->item();

        // The service drops a posted status rather than honouring it.
        $item = $this->punch->updateItem($item, [
            'status' => PunchItem::STATUS_CLOSED,
            'closed_on' => '2026-08-20',
            'notes' => 'Trade says done.',
        ]);

        $this->assertSame(PunchItem::STATUS_OPEN, $item->status);
        $this->assertNull($item->closed_on);
        $this->assertSame('Trade says done.', $item->notes);

        $inspection = $this->punch->inspect($item, [
            'result' => PunchInspection::RESULT_PASSED, 'inspected_on' => '2026-08-22',
        ]);

        $item->refresh();

        $this->assertTrue($inspection->passed());
        $this->assertSame(PunchItem::STATUS_CLOSED, $item->status);
        $this->assertSame('2026-08-22', $item->closed_on->toDateString());
        $this->assertNotNull($item->closed_by);
    }

    /**
     * **Every visit is a row** — §16.4's counted rather than inferred.
     *
     * Three attempts is two site attendances nobody planned and, on a subcontractor's defect, the evidence behind a
     * back charge.
     */
    public function test_every_re_inspection_is_counted(): void
    {
        $item = $this->item();

        $this->punch->inspect($item, ['result' => PunchInspection::RESULT_FAILED, 'inspected_on' => '2026-08-15']);
        $this->assertSame(PunchItem::STATUS_IN_PROGRESS, $item->refresh()->status, 'a failure leaves it open');

        $this->punch->inspect($item, ['result' => PunchInspection::RESULT_PARTIAL, 'inspected_on' => '2026-08-18']);
        $this->assertSame(PunchItem::STATUS_IN_PROGRESS, $item->refresh()->status, 'partly done is not a pass');

        $third = $this->punch->inspect($item, [
            'result' => PunchInspection::RESULT_PASSED, 'inspected_on' => '2026-08-25',
        ]);

        $this->assertSame(3, $third->attempt);

        $item->refresh()->load('inspections');

        $this->assertSame(3, $item->attemptsMade());
        $this->assertSame(2, $item->failedAttempts());
        $this->assertTrue($item->neededMoreThanOneVisit());
        $this->assertSame(PunchInspection::RESULT_PASSED, $item->latestInspection()->result);
        $this->assertSame(PunchItem::STATUS_CLOSED, $item->status);
    }

    /** Closed first time is the other fact, and it reads differently. */
    public function test_closed_first_time_needed_only_one_visit(): void
    {
        $item = $this->item();
        $this->punch->inspect($item, ['result' => PunchInspection::RESULT_PASSED]);

        $item->refresh()->load('inspections');

        $this->assertSame(1, $item->attemptsMade());
        $this->assertFalse($item->neededMoreThanOneVisit());
    }

    public function test_an_inspection_needs_a_result(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('needs a result');

        $this->punch->inspect($this->item(), ['inspected_on' => '2026-08-20']);
    }

    public function test_a_closed_item_takes_no_further_inspections_or_edits(): void
    {
        $item = $this->item();
        $this->punch->inspect($item, ['result' => PunchInspection::RESULT_PASSED]);
        $item->refresh();

        foreach ([
            fn () => $this->punch->inspect($item, ['result' => PunchInspection::RESULT_FAILED]),
            fn () => $this->punch->updateItem($item, ['description' => 'Something else']),
            fn () => $this->punch->reject($item, 'Changed my mind.'),
        ] as $attempt) {
            try {
                $attempt();
                $this->fail('A closed item should be settled.');
            } catch (InvalidArgumentException $e) {
                $this->assertMatchesRegularExpression('/closed|Raise it again/', $e->getMessage());
            }
        }
    }

    /** Rejection keeps the reason, because it is the answer to a question asked again in month nine. */
    public function test_rejection_keeps_the_reason_and_needs_one(): void
    {
        try {
            $this->punch->reject($this->item(), '   ');
            $this->fail('A rejection with no reason should be refused.');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('needs a reason', $e->getMessage());
        }

        $item = $this->punch->reject($this->item(['description' => 'Gap at skirting']), 'Design intent.');

        $this->assertSame(PunchItem::STATUS_REJECTED, $item->status);
        $this->assertTrue($item->isClosed());
        $this->assertStringContainsString('Not a defect: Design intent.', $item->notes);
    }

    public function test_an_item_can_be_marked_ready_for_inspection(): void
    {
        $item = $this->punch->readyForInspection($this->item());

        $this->assertSame(PunchItem::STATUS_READY, $item->status);
        $this->assertTrue($item->isLive(), 'ready is not closed — somebody still has to look');
    }

    // ------------------------------------------------------------------ the holdback figure

    /**
     * **The flag defaults off, and that is the decision.**
     *
     * A default of on would hold retention against every scuff of paint and make the figure meaningless inside a week.
     */
    public function test_an_item_does_not_block_completion_by_default(): void
    {
        $item = $this->item();

        $this->assertFalse($item->affects_practical_completion);
        $this->assertFalse($item->blocksCompletion());
        $this->assertSame(['amount' => 0.0, 'items' => 0, 'unpriced' => 0], $this->punch->holdbackFor($this->job));
    }

    /** Only the flagged, open ones — most snags are paint and sealant. */
    public function test_the_holdback_sums_only_the_open_flagged_items(): void
    {
        $this->item(['affects_practical_completion' => true, 'cost_to_rectify' => 150_000]);
        $this->item(['description' => 'Fire stopping missing', 'affects_practical_completion' => true, 'cost_to_rectify' => 90_000]);
        $this->item(['description' => 'Paint scuffed', 'cost_to_rectify' => 400_000]);

        $this->assertSame(
            ['amount' => 240_000.0, 'items' => 2, 'unpriced' => 0],
            $this->punch->holdbackFor($this->job),
        );
    }

    /**
     * **An unpriced blocking item is counted separately, not as zero.**
     *
     * §18.1: a holdback of 150,000 across three items where one has never been priced is not a holdback of 150,000, and
     * a figure hiding that has to say so.
     */
    public function test_unpriced_blocking_items_are_counted_rather_than_treated_as_nil(): void
    {
        $this->item(['affects_practical_completion' => true, 'cost_to_rectify' => 150_000]);
        $this->item(['description' => 'Balustrade loose', 'affects_practical_completion' => true]);

        $this->assertSame(
            ['amount' => 150_000.0, 'items' => 2, 'unpriced' => 1],
            $this->punch->holdbackFor($this->job),
        );
    }

    /** Closing one takes its cost out of the holdback, which is what makes clearing a list release money. */
    public function test_closing_a_flagged_item_removes_it_from_the_holdback(): void
    {
        $item = $this->item(['affects_practical_completion' => true, 'cost_to_rectify' => 150_000]);

        $this->assertSame(150_000.0, $this->punch->holdbackFor($this->job)['amount']);

        $this->punch->inspect($item, ['result' => PunchInspection::RESULT_PASSED]);

        $this->assertSame(0.0, $this->punch->holdbackFor($this->job)['amount']);
    }

    /** So does agreeing it was never a defect. */
    public function test_rejecting_a_flagged_item_removes_it_from_the_holdback(): void
    {
        $item = $this->item(['affects_practical_completion' => true, 'cost_to_rectify' => 150_000]);

        $this->punch->reject($item, 'Specified as an open joint.');

        $this->assertSame(0.0, $this->punch->holdbackFor($this->job)['amount']);
        $this->assertCount(0, $this->punch->blockingCompletion($this->job));
    }

    // ------------------------------------------------------------------ the reports

    /** §16.5's named report: every open item in this room. */
    public function test_open_items_are_grouped_by_location_with_the_unlocated_kept_visible(): void
    {
        $room = Location::create([
            'job_id' => $this->job->getKey(), 'code' => 'R412', 'name' => 'Room 412', 'type' => Location::TYPE_ROOM,
        ]);

        $this->item(['location_id' => $room->getKey()]);
        $this->item(['description' => 'Paint scuffed', 'location_id' => $room->getKey()]);
        $this->item(['description' => 'Somewhere on this floor']);

        $byLocation = $this->punch->byLocation($this->job);

        $this->assertCount(2, $byLocation[$room->getKey()]);
        // Kept under the empty key rather than dropped: a report right in total and silently missing the unlocated
        // items is the failure §6 already names in another subsystem.
        $this->assertCount(1, $byLocation['']);
    }

    public function test_overdue_items_are_asked_as_at_a_date(): void
    {
        $this->item(['due_on' => '2026-08-20']);
        $this->item(['description' => 'Later', 'due_on' => '2026-09-20']);

        $this->assertCount(0, $this->punch->overdue($this->job, '2026-08-15'));
        $this->assertCount(1, $this->punch->overdue($this->job, '2026-08-25'));
        $this->assertCount(2, $this->punch->overdue($this->job, '2026-10-01'));
    }

    public function test_items_needing_repeat_visits_are_reported(): void
    {
        $once = $this->item();
        $this->punch->inspect($once, ['result' => PunchInspection::RESULT_PASSED]);

        $twice = $this->item(['description' => 'Fire stopping missing']);
        $this->punch->inspect($twice, ['result' => PunchInspection::RESULT_FAILED]);
        $this->punch->inspect($twice->refresh(), ['result' => PunchInspection::RESULT_PASSED]);

        $repeat = $this->punch->neededRepeatVisits($this->job);

        $this->assertCount(1, $repeat);
        $this->assertSame($twice->getKey(), $repeat->first()->getKey());
    }

    /**
     * **Rectification cost with a responsible party and no back charge** — the exposure this register carries.
     *
     * A subcontractor's defect the main contractor put right at its own cost, priced, and never recharged: cost
     * absorbed, margin down, nothing wrong anywhere.
     */
    public function test_priced_items_with_a_responsible_party_and_no_back_charge_are_reported(): void
    {
        $exposed = $this->item(['cost_to_rectify' => 40_000]);
        // Recharged already.
        $this->punch->recordBackCharge($this->item(['description' => 'Cracked tile', 'cost_to_rectify' => 20_000]), 77);
        // Nobody else's — the contractor owns this cost.
        $this->item(['description' => 'Our own damage', 'cost_to_rectify' => 5_000, 'responsible_label' => null]);
        // Priced at nothing, so nothing to recharge.
        $this->item(['description' => 'Adjust door', 'cost_to_rectify' => null]);

        $unrecharged = $this->punch->unrecharged($this->job);

        $this->assertCount(1, $unrecharged);
        $this->assertSame($exposed->getKey(), $unrecharged->first()->getKey());
        $this->assertTrue($exposed->refresh()->rechargeableAndUnbilled());
    }

    public function test_the_back_charge_link_is_recorded(): void
    {
        $item = $this->punch->recordBackCharge($this->item(['cost_to_rectify' => 40_000]), 4242);

        $this->assertSame(4242, (int) $item->back_charge_id);
        $this->assertFalse($item->rechargeableAndUnbilled());
    }

    /** Where an item is, both ways over: the room from the tree and the grid reference on the drawing. */
    public function test_an_item_says_where_it_is_twice_over(): void
    {
        $room = Location::create([
            'job_id' => $this->job->getKey(), 'code' => 'R412', 'name' => 'Room 412', 'type' => Location::TYPE_ROOM,
        ]);

        $item = $this->item(['location_id' => $room->getKey(), 'grid_reference' => 'C/4']);

        $this->assertSame('Room 412 @ C/4', $item->refresh()->where());
        $this->assertNull($this->item(['description' => 'Nowhere in particular'])->where());
    }

    // ------------------------------------------------------------------ the screens

    public function test_the_register_shows_the_open_and_blocking_counts(): void
    {
        $this->item(['affects_practical_completion' => true, 'cost_to_rectify' => 150_000]);
        $this->item(['description' => 'Paint scuffed']);

        Livewire::test(ListPunchLists::class)
            ->assertCanSeeTableRecords([$this->list])
            ->assertSee('Level 4 pre-handover walk')
            ->assertSee('Pre-handover');

        $this->assertSame(2, $this->list->refresh()->load('items')->openItems());
        $this->assertSame(1, $this->list->blockingItems());
    }

    public function test_the_close_action_refuses_while_items_are_open(): void
    {
        $this->item();

        Livewire::test(ListPunchLists::class)
            ->callAction(TestAction::make('close')->table($this->list));

        $this->assertFalse($this->list->refresh()->isClosed());
    }

    public function test_the_items_tab_records_an_item_and_inspects_it(): void
    {
        Livewire::test(ItemsRelationManager::class, [
            'ownerRecord' => $this->list,
            'pageClass' => EditPunchList::class,
        ])
            ->callAction(TestAction::make('create')->table(), [
                'description' => 'Fire stopping missing above ceiling',
                'priority' => 'critical',
                'affects_practical_completion' => true,
                'cost_to_rectify' => 90_000,
                'responsible_label' => 'M&E Co',
            ]);

        $item = PunchItem::query()->firstOrFail();

        $this->assertTrue($item->affects_practical_completion);
        $this->assertSame(90_000.0, (float) $item->cost_to_rectify);
        $this->assertSame(90_000.0, $this->punch->holdbackFor($this->job)['amount']);

        Livewire::test(ItemsRelationManager::class, [
            'ownerRecord' => $this->list->refresh(),
            'pageClass' => EditPunchList::class,
        ])
            ->assertSee('Fire stopping missing')
            ->callAction(TestAction::make('inspect')->table($item), [
                'result' => PunchInspection::RESULT_FAILED,
                'inspected_on' => '2026-08-20',
                'notes' => 'Still open at two penetrations.',
            ]);

        $this->assertSame(PunchItem::STATUS_IN_PROGRESS, $item->refresh()->status);
        $this->assertSame(1, $item->load('inspections')->attemptsMade());
        $this->assertSame(90_000.0, $this->punch->holdbackFor($this->job)['amount'], 'still holding');
    }

    public function test_the_items_tab_rejects_with_a_reason(): void
    {
        $item = $this->item();

        Livewire::test(ItemsRelationManager::class, [
            'ownerRecord' => $this->list,
            'pageClass' => EditPunchList::class,
        ])
            ->callAction(TestAction::make('reject')->table($item), ['reason' => 'Specified as an open joint.']);

        $this->assertSame(PunchItem::STATUS_REJECTED, $item->refresh()->status);
        $this->assertStringContainsString('open joint', $item->notes);
    }

    /** The register works with only the spine and this module — §18. */
    public function test_the_register_works_with_only_the_spine_and_this_module(): void
    {
        CompanyModule::query()
            ->where('company_id', $this->tenant->getKey())
            ->whereIn('module', ['construction_costing', 'construction_contracts', 'invoicing', 'accounting'])
            ->update(['licensed' => false, 'enabled' => false]);
        modules()->flush();

        $item = $this->item([
            'affects_practical_completion' => true,
            'cost_to_rectify' => 150_000,
            'trade_label' => 'Glazier',
        ]);

        // The free-text labels carry the trade and the responsible party without either module.
        $this->assertSame('Glazing Co', $item->responsibleName());
        $this->assertSame('Glazier', $item->trade_label);
        $this->assertSame(150_000.0, $this->punch->holdbackFor($this->job)['amount']);

        $this->punch->inspect($item, ['result' => PunchInspection::RESULT_PASSED]);

        $this->assertSame(PunchItem::STATUS_CLOSED, $item->refresh()->status);
    }
}
