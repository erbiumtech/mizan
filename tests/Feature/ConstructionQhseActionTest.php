<?php

namespace Tests\Feature;

use App\Modules\Construction\Models\Job;
use App\Modules\ConstructionQhse\Filament\Resources\QhseActions\Pages\ListQhseActions;
use App\Modules\ConstructionQhse\Models\Inspection;
use App\Modules\ConstructionQhse\Models\ItpActivity;
use App\Modules\ConstructionQhse\Models\Ncr;
use App\Modules\ConstructionQhse\Models\QhseAction;
use App\Modules\ConstructionQhse\Services\ActionService;
use App\Modules\ConstructionQhse\Services\InspectionService;
use App\Modules\ConstructionQhse\Services\ItpService;
use App\Modules\ConstructionQhse\Services\NcrService;
use App\Modules\Core\Models\CompanyModule;
use Filament\Actions\Testing\TestAction;
use InvalidArgumentException;
use Livewire\Livewire;
use Tests\AccountingTestCase;
use Tests\Concerns\InteractsWithTenant;

/**
 * The one actions register — §17.4, Phase 10c.
 *
 * **"Every QHSE object generates the same record — somebody must do something by a date and somebody else must verify
 * it."** One table, because "four separate action tables produce four *overdue actions* reports that never agree, and
 * the safety manager's one genuinely useful screen — everything overdue, from every source, in one list — becomes a
 * four-way union nobody maintains."
 *
 * Four properties:
 *
 *  - **One table over several sources**, and each row can say which raised it.
 *  - **An action needs somebody against it**, in any of three forms — and the free-text name has to work alone, because
 *    on most sites most of the people who have to do something are a subcontractor's.
 *  - **Done and verified are two acts.** Verifying something nobody has claimed to have done is refused, which is the
 *    same rule §16.4's punch item and §17.2's NCR both keep.
 *  - **NCR CAPA is not mirrored into this table**, and the combined overdue view names the source of every row rather
 *    than merging two answers into one figure.
 */
class ConstructionQhseActionTest extends AccountingTestCase
{
    use InteractsWithTenant;

    private Job $job;

    private ActionService $actions;

    private NcrService $ncrs;

    private ?ItpActivity $point = null;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs($this->makeUser('Administrator', 'action@test.local'));
        $this->setCurrentTenant();

        foreach (['construction', 'construction_qhse'] as $module) {
            CompanyModule::updateOrCreate(
                ['company_id' => $this->tenant->getKey(), 'module' => $module],
                ['licensed' => true, 'enabled' => true],
            );
        }
        modules()->flush();

        $this->job = Job::create(['code' => 'J-1', 'name' => 'Tower']);
        $this->actions = app(ActionService::class);
        $this->ncrs = app(NcrService::class);
    }

    /** @param array<string, mixed> $attributes */
    private function ncr(array $attributes = []): Ncr
    {
        return $this->ncrs->raise($this->job, array_merge([
            'description' => 'Cover to reinforcement 22 mm against 40 mm specified',
            'severity' => Ncr::SEVERITY_MAJOR,
            'responsible_label' => 'Concrete Co',
        ], $attributes));
    }

    private function inspection(): Inspection
    {
        $plans = app(ItpService::class);

        if ($this->point === null) {
            $itp = $plans->draft($this->job, ['reference' => 'ITP-CIV-001', 'title' => 'In-situ concrete']);
            $point = $plans->addActivity($itp, [
                'activity_description' => 'Reinforcement prior to pour',
                'point_type' => ItpActivity::POINT_HOLD,
            ]);
            $plans->addParty($point, ['party' => 'engineer', 'role' => 'witnesses']);
            $plans->issue($itp->refresh());
            $this->point = $point->refresh();
        }

        return app(InspectionService::class)->requestAgainst($this->point);
    }

    /** @param array<string, mixed> $attributes */
    private function action(?object $subject = null, array $attributes = []): QhseAction
    {
        return $this->actions->raise($subject ?? $this->ncr(), array_merge([
            'description' => 'Break out and recast the affected metre.',
            'assignee_label' => 'Site agent',
            'due_on' => '2026-08-30',
        ], $attributes));
    }

    // ------------------------------------------------------------------ one table, several sources

    /** **The point of the table**: an NCR and an inspection produce rows of the same shape in one place. */
    public function test_several_sources_produce_rows_in_one_table(): void
    {
        $ncr = $this->ncr();
        $inspection = $this->inspection();

        $fromNcr = $this->action($ncr);
        $fromInspection = $this->action($inspection, ['description' => 'Re-inspect after the pour.']);

        $this->assertSame(2, QhseAction::query()->count());
        $this->assertSame('NCR', $fromNcr->sourceLabel());
        $this->assertSame('Inspection', $fromInspection->sourceLabel());

        // Each finding can be asked for its own.
        $this->assertCount(1, $this->actions->forSubject($ncr));
        $this->assertCount(1, $this->actions->forSubject($inspection));

        // And the NCR's own relation reads them back.
        $this->assertCount(1, $ncr->refresh()->actions);
    }

    /** The job is denormalised, because every report here is per job. */
    public function test_the_job_comes_from_the_subject(): void
    {
        $this->assertSame($this->job->getKey(), $this->action()->job_id);
    }

    public function test_an_action_needs_describing_and_a_known_type(): void
    {
        foreach ([
            [['description' => ' '], 'needs describing'],
            [['action_type' => 'sort_it_out'], 'needs a type'],
        ] as [$override, $expected]) {
            try {
                $this->action(null, $override);
                $this->fail("Expected a refusal mentioning: {$expected}");
            } catch (InvalidArgumentException $e) {
                $this->assertStringContainsString($expected, $e->getMessage());
            }
        }
    }

    /**
     * **An action nobody is assigned to is an action nobody does**, and the free-text name is the one that always
     * works.
     */
    public function test_an_action_needs_somebody_against_it_in_any_of_three_forms(): void
    {
        try {
            $this->actions->raise($this->ncr(), [
                'description' => 'Do something', 'due_on' => '2026-08-30',
            ]);
            $this->fail('An unassigned action should be refused.');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('an action nobody does', $e->getMessage());
        }

        // A plain name is enough, and it is what a subcontractor's foreman gets.
        $named = $this->action(null, ['assignee_label' => 'Ali, Concrete Co']);
        $this->assertSame('Ali, Concrete Co', $named->assigneeName());
        $this->assertFalse($named->isUnassigned());

        // A user works too.
        $byUser = $this->action(null, ['assignee_label' => null, 'assigned_user_id' => auth()->id()]);
        $this->assertSame(auth()->user()->name, $byUser->assigneeName());
    }

    /** Containment is not correction, and the register can tell them apart. */
    public function test_containment_is_its_own_type(): void
    {
        $containment = $this->action(null, [
            'description' => 'Barrier the area off.', 'action_type' => 'containment',
        ]);

        $this->assertSame('containment', $containment->action_type);
        $this->assertStringContainsString('stop it spreading', $containment->typeLabel());
    }

    // ------------------------------------------------------------------ done is not verified

    /**
     * **The claim, then the confirmation.** Marking it done leaves it live and awaiting verification.
     */
    public function test_marking_done_is_a_claim_and_leaves_it_awaiting_verification(): void
    {
        $action = $this->action();

        $action = $this->actions->complete($action, '2026-08-28', 'Recast on Friday.');

        $this->assertSame(QhseAction::STATUS_DONE, $action->status);
        $this->assertTrue($action->isDone());
        $this->assertFalse($action->isVerified());
        $this->assertTrue($action->isLive(), 'an unverified claim is not a closed action');
        $this->assertTrue($action->awaitingVerification());
        $this->assertCount(1, $this->actions->awaitingVerification($this->job));

        $action = $this->actions->verify($action, '2026-08-29', 'Checked on site.');

        $this->assertSame(QhseAction::STATUS_VERIFIED, $action->status);
        $this->assertTrue($action->isVerified());
        $this->assertFalse($action->isLive());
        $this->assertNotNull($action->verified_by);
        $this->assertCount(0, $this->actions->awaitingVerification($this->job));
    }

    /** **Verifying something nobody has done is refused** — the same rule as a punch item's re-inspection. */
    public function test_verifying_an_action_nobody_has_done_is_refused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('a closure that proves nothing');

        $this->actions->verify($this->action());
    }

    public function test_verifying_twice_is_refused(): void
    {
        $action = $this->actions->verify($this->actions->complete($this->action()));

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('already verified');

        $this->actions->verify($action);
    }

    /** Started is worth distinguishing from nobody having looked. */
    public function test_starting_is_its_own_state(): void
    {
        $action = $this->actions->start($this->action());

        $this->assertSame(QhseAction::STATUS_IN_PROGRESS, $action->status);
        $this->assertTrue($action->isLive());
    }

    public function test_cancelling_keeps_the_reason(): void
    {
        try {
            $this->actions->cancel($this->action(), '  ');
            $this->fail('A cancel with no reason should be refused.');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('raised again next month', $e->getMessage());
        }

        $action = $this->actions->cancel($this->action(), 'Superseded by the redesign.');

        $this->assertSame(QhseAction::STATUS_CANCELLED, $action->status);
        $this->assertSame('Superseded by the redesign.', $action->cancel_reason);
        $this->assertFalse($action->isLive());
    }

    public function test_a_settled_action_takes_no_further_changes(): void
    {
        $action = $this->actions->verify($this->actions->complete($this->action()));

        foreach ([
            fn () => $this->actions->update($action, ['description' => 'Something else']),
            fn () => $this->actions->complete($action),
            fn () => $this->actions->start($action),
            fn () => $this->actions->cancel($action, 'Changed my mind.'),
        ] as $attempt) {
            try {
                $attempt();
                $this->fail('A verified action should be settled.');
            } catch (InvalidArgumentException $e) {
                $this->assertStringContainsString('verified', $e->getMessage());
            }
        }
    }

    /** The status, the completion and the verification are each their own act, so an edit cannot write them. */
    public function test_an_edit_cannot_write_the_status_or_the_verification(): void
    {
        $action = $this->actions->update($this->action(), [
            'status' => QhseAction::STATUS_VERIFIED,
            'verified_on' => '2026-08-01',
            'completed_on' => '2026-08-01',
            'priority' => 'critical',
        ]);

        $this->assertSame(QhseAction::STATUS_OPEN, $action->status);
        $this->assertNull($action->verified_on);
        $this->assertNull($action->completed_on);
        $this->assertSame('critical', $action->priority);
    }

    // ------------------------------------------------------------------ the one overdue list

    public function test_overdue_is_asked_as_at_a_date(): void
    {
        $this->action(null, ['due_on' => '2026-08-20']);
        $this->action(null, ['description' => 'Later', 'due_on' => '2026-09-20']);
        // No date: never overdue, and the register names it separately.
        $this->action(null, ['description' => 'No date', 'due_on' => null]);

        $this->assertCount(0, $this->actions->overdue($this->job, '2026-08-15'));
        $this->assertCount(1, $this->actions->overdue($this->job, '2026-08-25'));
        $this->assertCount(2, $this->actions->overdue($this->job, '2026-10-01'));
    }

    /** A verified action is off the overdue list however late it was. */
    public function test_a_verified_action_is_not_overdue(): void
    {
        $action = $this->action(null, ['due_on' => '2026-08-01']);

        $this->assertCount(1, $this->actions->overdue($this->job, '2026-08-25'));

        $this->actions->verify($this->actions->complete($action, '2026-08-24'));

        $this->assertCount(0, $this->actions->overdue($this->job, '2026-08-25'));
    }

    /**
     * **Everything overdue, from every source, and each row says where it came from.**
     *
     * §17.4 asks for one list; §17.2 puts CAPA dates on the NCR because ISO 9001 asks for them there. Both are honoured
     * without mirroring either into the other, and the source is named — which is what stops the same date being counted
     * twice.
     */
    public function test_the_combined_overdue_list_names_the_source_of_every_row(): void
    {
        // An action row.
        $this->action(null, ['description' => 'Break out the slab.', 'due_on' => '2026-08-10']);

        // And an NCR whose own CAPA dates have passed, with both halves outstanding.
        $ncr = $this->ncrs->recordCapa(
            $this->ncrs->disposition($this->ncr(['description' => 'Honeycombing']), Ncr::DISPOSITION_REPAIR),
            [
                'corrective_action' => 'Patch and make good.',
                'corrective_due_on' => '2026-08-05',
                'corrective_owner_label' => 'Site agent',
                'preventive_action' => 'Vibration training for the gang.',
                'preventive_due_on' => '2026-08-15',
                'preventive_owner_label' => 'QA manager',
            ],
        );

        $rows = $this->actions->everythingOverdue($this->job, '2026-08-25');

        $this->assertCount(3, $rows);

        $sources = array_column($rows, 'source');
        $this->assertContains('Action · NCR', $sources);
        $this->assertContains('NCR CAPA · corrective', $sources);
        $this->assertContains('NCR CAPA · preventive', $sources);

        // Sorted worst first, which is what a chase list wants.
        $this->assertSame(20, $rows[0]['days_late'], 'the corrective action, due on the 5th');
        $this->assertSame('NCR CAPA · corrective', $rows[0]['source']);
        $this->assertSame($ncr->ncr_number, $rows[0]['reference']);
        $this->assertSame('Site agent', $rows[0]['assignee']);
    }

    /** A CAPA half that is done drops off the combined list; the other stays. */
    public function test_a_completed_capa_half_leaves_the_combined_list(): void
    {
        $ncr = $this->ncrs->recordCapa(
            $this->ncrs->disposition($this->ncr(), Ncr::DISPOSITION_REPAIR),
            [
                'corrective_action' => 'Patch.', 'corrective_due_on' => '2026-08-05',
                'corrective_done_on' => '2026-08-06',
                'preventive_action' => 'Training.', 'preventive_due_on' => '2026-08-15',
            ],
        );

        $rows = $this->actions->everythingOverdue($this->job, '2026-08-25');

        $this->assertCount(1, $rows);
        $this->assertSame('NCR CAPA · preventive', $rows[0]['source']);
        $this->assertTrue($ncr->refresh()->fixedButNotPrevented());
    }

    /** A closed NCR's CAPA is out of the list — there is nothing left to chase. */
    public function test_a_closed_ncr_is_not_in_the_combined_list(): void
    {
        $ncr = $this->ncrs->recordCapa(
            $this->ncrs->disposition($this->ncr(), Ncr::DISPOSITION_REWORK),
            ['corrective_action' => 'Patch.', 'corrective_due_on' => '2026-08-05'],
        );
        $this->ncrs->void($ncr, 'Agreed to be within tolerance after survey.');

        $this->assertCount(0, $this->actions->everythingOverdue($this->job, '2026-08-25'));
    }

    // ------------------------------------------------------------------ the screens

    public function test_the_register_shows_every_source_together(): void
    {
        $fromNcr = $this->action(null, ['due_on' => '2026-08-01']);
        $fromInspection = $this->action($this->inspection(), ['description' => 'Re-inspect after the pour.']);

        Livewire::test(ListQhseActions::class)
            ->assertCanSeeTableRecords([$fromNcr, $fromInspection])
            ->assertSee('NCR')
            ->assertSee('Inspection')
            ->assertSee('Site agent');
    }

    public function test_the_screen_completes_then_verifies(): void
    {
        $action = $this->action();

        Livewire::test(ListQhseActions::class)
            ->callAction(TestAction::make('complete')->table($action), [
                'on' => '2026-08-28',
                'notes' => 'Recast on Friday.',
            ]);

        $this->assertTrue($action->refresh()->awaitingVerification());

        Livewire::test(ListQhseActions::class)
            ->assertSee('Done, awaiting verification')
            ->callAction(TestAction::make('verify')->table($action), ['on' => '2026-08-29']);

        $this->assertTrue($action->refresh()->isVerified());
    }

    public function test_the_screen_surfaces_the_verify_refusal(): void
    {
        $action = $this->action();

        // The policy directly: an Administrator passes through `Gate::before`, which is a different question from
        // whether the rule is written. The point is that the register cannot verify an unclaimed action by any route —
        // the button is hidden and the service refuses.
        $policy = app(\App\Modules\ConstructionQhse\Policies\QhseActionPolicy::class);

        $this->assertFalse($policy->verify(auth()->user(), $action));
        $this->assertTrue($policy->verify(auth()->user(), $this->actions->complete($action)));
    }

    /** The register works with only the spine and this module — §18. */
    public function test_the_register_works_with_only_the_spine_and_this_module(): void
    {
        CompanyModule::query()
            ->where('company_id', $this->tenant->getKey())
            ->whereIn('module', ['construction_contracts', 'construction_costing', 'invoicing', 'accounting'])
            ->update(['licensed' => false, 'enabled' => false]);
        modules()->flush();

        $action = $this->action(null, ['assignee_label' => 'Ali, Concrete Co', 'due_on' => '2026-08-01']);

        $this->assertSame('Ali, Concrete Co', $action->assigneeName());
        $this->assertCount(1, $this->actions->overdue($this->job, '2026-08-25'));
        $this->assertTrue($this->actions->verify($this->actions->complete($action))->isVerified());
    }
}
