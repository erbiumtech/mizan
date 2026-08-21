<?php

namespace Tests\Feature;

use App\Modules\Construction\Models\Job;
use App\Modules\ConstructionField\Filament\Resources\Submittals\Pages\EditSubmittal;
use App\Modules\ConstructionField\Filament\Resources\Submittals\Pages\ListSubmittals;
use App\Modules\ConstructionField\Filament\Resources\Submittals\RelationManagers\ReviewsRelationManager;
use App\Modules\ConstructionField\Models\DelayEvent;
use App\Modules\ConstructionField\Models\Submittal;
use App\Modules\ConstructionField\Models\SubmittalReview;
use App\Modules\ConstructionField\Services\SubmittalService;
use App\Modules\Core\Models\CompanyModule;
use Filament\Actions\Testing\TestAction;
use InvalidArgumentException;
use Livewire\Livewire;
use Tests\AccountingTestCase;
use Tests\Concerns\InteractsWithTenant;

/**
 * The submittal register — §16.3, Phase 9e.
 *
 * **The submit-by date is computed and never stored**, which is the whole of §16.3: "typed, it goes stale the day the
 * programme moves, and a stale submit-by date is worse than none". Required on site, less fabrication, procurement,
 * review period and buffer — recomputed on every read, so moving any one of the five moves the answer.
 *
 * The consequence is the finding a real job needs first: **an item can be late before anybody has done anything wrong**,
 * because nobody did the subtraction when the programme was agreed.
 *
 * **A row per round**, because §16.3 says a status column cannot hold it: "a submittal that has been round three times
 * is a schedule risk". One round was budgeted for; the rest spend float nobody planned.
 *
 * And the distinction this sub-phase adds to the section's pattern: **whose lateness it is decides what it is.** The
 * three exposures the field module has surfaced so far are money somebody else owes. A submittal late to *submit* is the
 * contractor's own risk — there is no notice for it and nothing here offers one. A reviewer past their period has taken
 * somebody else's programme, and that is on the round, with a notice dated the day the period expired.
 */
class ConstructionSubmittalTest extends AccountingTestCase
{
    use InteractsWithTenant;

    private Job $job;

    private SubmittalService $submittals;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs($this->makeUser('Administrator', 'submittal@test.local'));
        $this->setCurrentTenant();

        foreach (['construction', 'construction_field'] as $module) {
            CompanyModule::updateOrCreate(
                ['company_id' => $this->tenant->getKey(), 'module' => $module],
                ['licensed' => true, 'enabled' => true],
            );
        }
        modules()->flush();

        $this->job = Job::create(['code' => 'J-1', 'name' => 'Tower']);
        $this->submittals = app(SubmittalService::class);
    }

    /** @param array<string, mixed> $attributes */
    private function register(array $attributes = []): Submittal
    {
        return $this->submittals->register($this->job, array_merge([
            'spec_section' => '05 12 00',
            'title' => 'Structural steel shop drawings',
            'type' => 'shop_drawing',
            'responsible_label' => 'Metro Steel',
            'required_on_site_date' => '2026-12-01',
            'fabrication_lead_days' => 60,
            'procurement_lead_days' => 21,
            'review_period_days' => 14,
            'buffer_days' => 7,
        ], $attributes));
    }

    // ------------------------------------------------------------------ the computed date

    /** **The whole of §16.3**: required on site, less the four durations, every time it is asked. */
    public function test_the_submit_by_date_is_computed_backwards_from_the_programme(): void
    {
        $submittal = $this->register();

        $this->assertSame(102, $submittal->leadDays());
        $this->assertSame('2026-08-21', $submittal->submitBy()->toDateString());
    }

    /**
     * **Moving any one of the five moves the answer**, which is what a stored date could not do.
     *
     * §16.3's argument in one assertion: a typed submit-by date would still say 21 August after the programme moved.
     */
    public function test_moving_the_programme_moves_the_computed_date(): void
    {
        $submittal = $this->submittals->update($this->register(), ['required_on_site_date' => '2026-11-01']);

        $this->assertSame('2026-07-22', $submittal->submitBy()->toDateString());

        // And so does a lead time — the four are independent inputs, not a single "lead" figure.
        $submittal = $this->submittals->update($submittal, ['fabrication_lead_days' => 90]);

        $this->assertSame('2026-06-22', $submittal->submitBy()->toDateString());
    }

    /** No programme date is an honest "cannot tell", not today's date dressed up as an answer. */
    public function test_without_a_programme_date_there_is_no_submit_by_date(): void
    {
        $submittal = $this->register(['required_on_site_date' => null]);

        $this->assertNull($submittal->submitBy());
        $this->assertNull($submittal->daysUntilSubmitBy());
        $this->assertSame(0, $submittal->daysLate('2030-01-01'));
        $this->assertFalse($submittal->isLateToSubmit('2030-01-01'));
    }

    /**
     * **Late before anybody has done anything wrong** — the register's first useful finding.
     *
     * Nobody did the subtraction when the programme was agreed, so the date had already passed by the time it was
     * computed.
     */
    public function test_an_item_can_be_late_the_day_it_is_registered(): void
    {
        $submittal = $this->register(['required_on_site_date' => '2026-09-01']);

        $this->assertSame('2026-05-22', $submittal->submitBy()->toDateString());
        $this->assertTrue($submittal->isLateToSubmit('2026-08-21'));
        $this->assertSame(91, $submittal->daysLate('2026-08-21'));
        $this->assertSame(-91, $submittal->daysUntilSubmitBy('2026-08-21'));
    }

    /** Early is not lateness, and a report summing the column wants only the loss. */
    public function test_being_in_time_is_zero_days_late_and_not_a_negative(): void
    {
        $submittal = $this->register();

        $this->assertSame(0, $submittal->daysLate('2026-08-01'));
        $this->assertSame(20, $submittal->daysUntilSubmitBy('2026-08-01'));
        $this->assertFalse($submittal->isLateToSubmit('2026-08-01'));
    }

    /** Once submitted, the lateness stops moving — it is measured to the submission, not to today. */
    public function test_lateness_is_measured_to_the_submission_once_there_is_one(): void
    {
        $submittal = $this->register();
        $this->submittals->submit($submittal, ['sent_on' => '2026-08-31']);

        $submittal->refresh();

        $this->assertSame(10, $submittal->daysLate('2026-12-31'), 'it stops at the day it went');
        $this->assertFalse($submittal->isLateToSubmit('2026-12-31'), 'it is no longer awaiting submission');
    }

    public function test_a_submittal_needs_a_section_a_title_and_a_type(): void
    {
        foreach ([
            [['spec_section' => ' '], 'a specification section'],
            [['title' => ' '], 'a title'],
            [['type' => 'poster'], 'needs a type'],
        ] as [$override, $expected]) {
            try {
                $this->register($override);
                $this->fail("Expected a refusal mentioning: {$expected}");
            } catch (InvalidArgumentException $e) {
                $this->assertStringContainsString($expected, $e->getMessage());
            }
        }
    }

    // ------------------------------------------------------------------ rounds

    /** **A row per round** — §16.3, and the fact a status column cannot hold. */
    public function test_each_submission_opens_a_round(): void
    {
        $submittal = $this->register();

        $first = $this->submittals->submit($submittal, ['sent_on' => '2026-08-01', 'revision' => 'P01']);

        $this->assertSame(1, $first->round);
        $this->assertSame(Submittal::STATUS_UNDER_REVIEW, $submittal->refresh()->status);
        $this->assertSame('2026-08-01', $submittal->submitted_on->toDateString());

        $this->submittals->recordReturn($first, [
            'result' => SubmittalReview::RESULT_REVISE_AND_RESUBMIT,
            'returned_on' => '2026-08-12',
        ]);

        $this->assertSame(Submittal::STATUS_REVISE_AND_RESUBMIT, $submittal->refresh()->status);

        $second = $this->submittals->submit($submittal, ['sent_on' => '2026-08-20', 'revision' => 'P02']);

        $this->assertSame(2, $second->round);
        $this->assertSame('P02', $submittal->refresh()->revision);
        // The *first* submission is kept: it is what the register's lateness is measured to.
        $this->assertSame('2026-08-01', $submittal->submitted_on->toDateString());

        $submittal->load('reviews');
        $this->assertSame(2, $submittal->roundsUsed());
        $this->assertTrue($submittal->hasResubmitted());
    }

    /** Two rounds open at once is how a register loses count of how many it has been through. */
    public function test_two_rounds_cannot_be_open_at_once(): void
    {
        $submittal = $this->register();
        $this->submittals->submit($submittal, ['sent_on' => '2026-08-01']);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('out for review as round 1');

        $this->submittals->submit($submittal->refresh(), ['sent_on' => '2026-08-05']);
    }

    public function test_a_round_cannot_be_returned_twice(): void
    {
        $submittal = $this->register();
        $round = $this->submittals->submit($submittal, ['sent_on' => '2026-08-01']);
        $this->submittals->recordReturn($round, [
            'result' => SubmittalReview::RESULT_APPROVED, 'returned_on' => '2026-08-10',
        ]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Submit again to open a new round');

        $this->submittals->recordReturn($round->refresh(), [
            'result' => SubmittalReview::RESULT_REJECTED, 'returned_on' => '2026-08-11',
        ]);
    }

    public function test_a_returned_round_needs_a_result_and_cannot_predate_the_sending(): void
    {
        $submittal = $this->register();
        $round = $this->submittals->submit($submittal, ['sent_on' => '2026-08-10']);

        try {
            $this->submittals->recordReturn($round, ['returned_on' => '2026-08-20']);
            $this->fail('A return with no result should be refused.');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('needs a result', $e->getMessage());
        }

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('cannot come back before it went out');

        $this->submittals->recordReturn($round, [
            'result' => SubmittalReview::RESULT_APPROVED, 'returned_on' => '2026-08-01',
        ]);
    }

    // ------------------------------------------------------------------ the status is a projection

    /** **Approved as noted clears the item**, because the fabricator starts. */
    public function test_approved_as_noted_clears_the_item(): void
    {
        $submittal = $this->register();
        $round = $this->submittals->submit($submittal, ['sent_on' => '2026-08-01']);
        $this->submittals->recordReturn($round, [
            'result' => SubmittalReview::RESULT_APPROVED_AS_NOTED, 'returned_on' => '2026-08-10',
        ]);

        $submittal->refresh();

        $this->assertSame(Submittal::STATUS_APPROVED_AS_NOTED, $submittal->status);
        $this->assertTrue($submittal->isCleared());
        $this->assertSame('2026-08-10', $submittal->approved_on->toDateString());
    }

    /** Every result maps to a status, and the two that send it back leave it uncleared. */
    public function test_each_result_writes_the_status(): void
    {
        foreach ([
            SubmittalReview::RESULT_APPROVED => [Submittal::STATUS_APPROVED, true],
            SubmittalReview::RESULT_APPROVED_AS_NOTED => [Submittal::STATUS_APPROVED_AS_NOTED, true],
            SubmittalReview::RESULT_FOR_RECORD => [Submittal::STATUS_CLOSED, true],
            SubmittalReview::RESULT_REVISE_AND_RESUBMIT => [Submittal::STATUS_REVISE_AND_RESUBMIT, false],
            SubmittalReview::RESULT_REJECTED => [Submittal::STATUS_REJECTED, false],
        ] as $result => [$status, $cleared]) {
            $submittal = $this->register(['spec_section' => "05 12 {$result}"]);
            $round = $this->submittals->submit($submittal, ['sent_on' => '2026-08-01']);
            $this->submittals->recordReturn($round, ['result' => $result, 'returned_on' => '2026-08-10']);

            $submittal->refresh();

            $this->assertSame($status, $submittal->status, "result {$result}");
            $this->assertSame($cleared, $submittal->isCleared(), "result {$result} cleared");
        }
    }

    /** The status is a projection of the rounds, so a form cannot set it beside them. */
    public function test_the_status_cannot_be_typed(): void
    {
        $submittal = $this->submittals->update($this->register(), [
            'status' => Submittal::STATUS_APPROVED,
            'notes' => 'Chased twice.',
        ]);

        $this->assertSame(Submittal::STATUS_PENDING, $submittal->status);
        $this->assertSame('Chased twice.', $submittal->notes);
    }

    public function test_a_cleared_submittal_takes_no_further_changes(): void
    {
        $submittal = $this->register();
        $round = $this->submittals->submit($submittal, ['sent_on' => '2026-08-01']);
        $this->submittals->recordReturn($round, [
            'result' => SubmittalReview::RESULT_APPROVED, 'returned_on' => '2026-08-10',
        ]);

        $submittal->refresh();

        try {
            $this->submittals->update($submittal, ['title' => 'Something else']);
            $this->fail('A cleared submittal should be immutable.');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('record of what the approval bought', $e->getMessage());
        }

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('already approved');

        $this->submittals->submit($submittal);
    }

    // ------------------------------------------------------------------ turnaround and the claim

    /**
     * **The review period is snapshotted onto the round.**
     *
     * A round whose overrun was computed against fourteen days keeps saying fourteen, whatever the contract is later
     * renegotiated to — the same reasoning §8 uses for freezing a certificate's retention terms.
     */
    public function test_the_review_period_is_snapshotted_onto_the_round(): void
    {
        $submittal = $this->register();
        $round = $this->submittals->submit($submittal, ['sent_on' => '2026-08-01']);

        $this->assertSame(14, $round->review_period_days);

        $this->submittals->update($submittal->refresh(), ['review_period_days' => 28]);

        $this->assertSame(14, $round->refresh()->review_period_days, 'the round keeps the period that applied');
    }

    /** Turnaround counts to today while it is still out — forty days on a desk is the fact worth reporting. */
    public function test_turnaround_counts_to_today_while_it_is_still_out(): void
    {
        $round = $this->submittals->submit($this->register(), ['sent_on' => '2026-08-01']);

        $this->assertSame(40, $round->turnaroundDays('2026-09-10'));
        $this->assertSame(26, $round->overrunDays('2026-09-10'));
        $this->assertTrue($round->hasOverrun('2026-09-10'));
    }

    /** Returned early is zero overrun, not a negative entitlement. */
    public function test_an_early_return_is_no_overrun(): void
    {
        $submittal = $this->register();
        $round = $this->submittals->submit($submittal, ['sent_on' => '2026-08-01']);
        $this->submittals->recordReturn($round, [
            'result' => SubmittalReview::RESULT_APPROVED, 'returned_on' => '2026-08-08',
        ]);

        $round->refresh();

        $this->assertSame(7, $round->turnaroundDays());
        $this->assertSame(0, $round->overrunDays());
        $this->assertFalse($round->hasOverrun());
        $this->assertFalse($round->overrunUnnotified());
    }

    /**
     * **The notice is dated the day the review period expired.**
     *
     * Not the day it came back and not today: the notice period runs from the event, and the event is the reviewer
     * passing their own deadline.
     */
    public function test_notifying_an_overrun_dates_it_from_the_day_the_period_expired(): void
    {
        $submittal = $this->register();
        $round = $this->submittals->submit($submittal, ['sent_on' => '2026-08-01']);
        $this->submittals->recordReturn($round, [
            'result' => SubmittalReview::RESULT_APPROVED_AS_NOTED, 'returned_on' => '2026-09-10',
        ]);

        $round->refresh();
        $this->assertSame('2026-08-15', $round->overrunStartedOn());

        $event = $this->submittals->raiseDelayForOverrun($round);

        $this->assertSame('2026-08-15', $event->occurred_on->toDateString());
        $this->assertSame('late_information', $event->cause_category);
        $this->assertSame(26, (int) $event->claimed_days);
        $this->assertStringContainsString('14-day review period', $event->description);
        // The clock is now running on the delay register, from the day the period expired.
        $this->assertSame('2026-09-12', $event->notice_required_by->toDateString());

        $this->assertSame($event->getKey(), $round->refresh()->delay_event_id);
        $this->assertFalse($round->overrunUnnotified());
    }

    public function test_a_round_inside_its_period_has_nothing_to_notify(): void
    {
        $submittal = $this->register();
        $round = $this->submittals->submit($submittal, ['sent_on' => '2026-08-01']);
        $this->submittals->recordReturn($round, [
            'result' => SubmittalReview::RESULT_APPROVED, 'returned_on' => '2026-08-08',
        ]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('inside its 14-day review period');

        $this->submittals->raiseDelayForOverrun($round->refresh());
    }

    public function test_an_overrun_cannot_be_notified_twice(): void
    {
        $submittal = $this->register();
        $round = $this->submittals->submit($submittal, ['sent_on' => '2026-08-01']);
        $this->submittals->recordReturn($round, [
            'result' => SubmittalReview::RESULT_APPROVED, 'returned_on' => '2026-09-10',
        ]);

        $this->submittals->raiseDelayForOverrun($round->refresh());

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('already has a delay event');

        $this->submittals->raiseDelayForOverrun($round->refresh());
    }

    /**
     * **Being late to submit is the contractor's own risk, and there is no notice for it.**
     *
     * The distinction this sub-phase draws against the section's other three exposures: those are money somebody else
     * owes. This one is money you are about to lose yourself, and the register's job is to rank it, not to notify it.
     */
    public function test_being_late_to_submit_offers_no_notice(): void
    {
        $submittal = $this->register(['required_on_site_date' => '2026-09-01']);

        $this->assertTrue($submittal->isLateToSubmit('2026-08-21'));
        $this->assertCount(0, $this->submittals->unnotifiedReviewOverruns($this->job, '2026-08-21'));
        $this->assertSame(0, DelayEvent::query()->count());
    }

    // ------------------------------------------------------------------ the reports

    /** Ordered on the computed date, with no-programme items last since they cannot be sequenced. */
    public function test_what_is_due_to_submit_is_ordered_on_the_computed_date(): void
    {
        $this->register(['spec_section' => 'A', 'required_on_site_date' => '2026-12-01']);
        $this->register(['spec_section' => 'B', 'required_on_site_date' => '2026-10-01']);
        $this->register(['spec_section' => 'C', 'required_on_site_date' => null]);

        $this->assertSame(
            ['B', 'A', 'C'],
            $this->submittals->dueToSubmit($this->job)->pluck('spec_section')->all(),
        );
    }

    public function test_what_is_already_late_is_asked_as_at_a_date(): void
    {
        $this->register(['spec_section' => 'A', 'required_on_site_date' => '2026-09-01']);
        $this->register(['spec_section' => 'B', 'required_on_site_date' => '2027-06-01']);

        $this->assertCount(1, $this->submittals->lateToSubmit($this->job, '2026-08-21'));
        $this->assertCount(2, $this->submittals->lateToSubmit($this->job, '2027-06-01'));
    }

    /** A submitted item leaves the due list, whatever its date. */
    public function test_a_submitted_item_is_no_longer_due(): void
    {
        $submittal = $this->register(['required_on_site_date' => '2026-09-01']);

        $this->assertCount(1, $this->submittals->dueToSubmit($this->job));

        $this->submittals->submit($submittal, ['sent_on' => '2026-08-21']);

        $this->assertCount(0, $this->submittals->dueToSubmit($this->job));
        $this->assertCount(0, $this->submittals->lateToSubmit($this->job, '2026-08-21'));
    }

    /** The twenty items that will stop the job, out of four hundred that will not. */
    public function test_long_lead_outstanding_is_its_own_report(): void
    {
        $this->register(['spec_section' => 'A', 'is_long_lead' => true]);
        $this->register(['spec_section' => 'B']);

        $cleared = $this->register(['spec_section' => 'C', 'is_long_lead' => true]);
        $round = $this->submittals->submit($cleared, ['sent_on' => '2026-08-01']);
        $this->submittals->recordReturn($round, [
            'result' => SubmittalReview::RESULT_APPROVED, 'returned_on' => '2026-08-08',
        ]);

        $this->assertSame(['A'], $this->submittals->longLeadOutstanding($this->job)->pluck('spec_section')->all());
    }

    public function test_items_that_have_been_round_more_than_once_are_reported(): void
    {
        $once = $this->register(['spec_section' => 'A']);
        $this->submittals->submit($once, ['sent_on' => '2026-08-01']);

        $twice = $this->register(['spec_section' => 'B']);
        $first = $this->submittals->submit($twice, ['sent_on' => '2026-08-01']);
        $this->submittals->recordReturn($first, [
            'result' => SubmittalReview::RESULT_REVISE_AND_RESUBMIT, 'returned_on' => '2026-08-10',
        ]);
        $this->submittals->submit($twice->refresh(), ['sent_on' => '2026-08-15']);

        $this->assertSame(['B'], $this->submittals->resubmitted($this->job)->pluck('spec_section')->all());
    }

    public function test_unnotified_overruns_are_reported_across_the_job(): void
    {
        $overrun = $this->register(['spec_section' => 'A']);
        $round = $this->submittals->submit($overrun, ['sent_on' => '2026-08-01']);
        $this->submittals->recordReturn($round, [
            'result' => SubmittalReview::RESULT_APPROVED, 'returned_on' => '2026-09-10',
        ]);

        $inTime = $this->register(['spec_section' => 'B']);
        $ok = $this->submittals->submit($inTime, ['sent_on' => '2026-08-01']);
        $this->submittals->recordReturn($ok, [
            'result' => SubmittalReview::RESULT_APPROVED, 'returned_on' => '2026-08-08',
        ]);

        $exposed = $this->submittals->unnotifiedReviewOverruns($this->job, '2026-09-11');

        $this->assertCount(1, $exposed);
        $this->assertSame($round->getKey(), $exposed->first()->getKey());
    }

    /** Null rather than zero where nothing has come back — the flattering figure this register prevents. */
    public function test_the_average_turnaround_is_null_until_something_comes_back(): void
    {
        $submittal = $this->register();
        $round = $this->submittals->submit($submittal, ['sent_on' => '2026-08-01']);

        $this->assertNull($this->submittals->averageTurnaroundDays($this->job));

        $this->submittals->recordReturn($round, [
            'result' => SubmittalReview::RESULT_APPROVED, 'returned_on' => '2026-08-11',
        ]);

        $this->assertSame(10.0, $this->submittals->averageTurnaroundDays($this->job));
    }

    // ------------------------------------------------------------------ the screens

    public function test_the_register_shows_the_computed_date_and_the_rounds(): void
    {
        $late = $this->register(['required_on_site_date' => '2026-09-01']);

        Livewire::test(ListSubmittals::class)
            ->assertCanSeeTableRecords([$late])
            ->assertSee('05 12 00')
            ->assertSee('Metro Steel')
            ->assertSee('days late');
    }

    public function test_the_screen_submits_and_records_the_return(): void
    {
        $submittal = $this->register();

        Livewire::test(ListSubmittals::class)
            ->callAction(TestAction::make('submit')->table($submittal), [
                'sent_on' => '2026-08-01',
                'revision' => 'P01',
                'review_period_days' => 14,
                'reviewer_label' => 'Design Consultants',
            ]);

        $this->assertSame(Submittal::STATUS_UNDER_REVIEW, $submittal->refresh()->status);

        Livewire::test(ListSubmittals::class)
            ->callAction(TestAction::make('recordReturn')->table($submittal), [
                'result' => SubmittalReview::RESULT_REVISE_AND_RESUBMIT,
                'returned_on' => '2026-09-10',
                'comments' => 'Connections to be redesigned.',
            ]);

        $submittal->refresh();
        $round = $submittal->reviews()->firstOrFail();

        $this->assertSame(Submittal::STATUS_REVISE_AND_RESUBMIT, $submittal->status);
        $this->assertSame(26, $round->overrunDays());
        $this->assertSame('Design Consultants', $round->reviewerName());
    }

    public function test_the_rounds_tab_notifies_an_overrun(): void
    {
        $submittal = $this->register();
        $round = $this->submittals->submit($submittal, ['sent_on' => '2026-08-01']);
        $this->submittals->recordReturn($round, [
            'result' => SubmittalReview::RESULT_APPROVED, 'returned_on' => '2026-09-10',
        ]);

        Livewire::test(ReviewsRelationManager::class, [
            'ownerRecord' => $submittal->refresh(),
            'pageClass' => EditSubmittal::class,
        ])
            ->assertCanSeeTableRecords([$round])
            ->assertSee('no notice')
            ->callAction(TestAction::make('notifyOverrun')->table($round));

        $event = DelayEvent::query()->firstOrFail();

        $this->assertSame('2026-08-15', $event->occurred_on->toDateString());
        $this->assertSame($event->getKey(), $round->refresh()->delay_event_id);
    }

    /** The register works with only the spine and this module — §18. */
    public function test_the_register_works_with_only_the_spine_and_this_module(): void
    {
        CompanyModule::query()
            ->where('company_id', $this->tenant->getKey())
            ->whereIn('module', ['construction_costing', 'construction_contracts', 'invoicing', 'accounting'])
            ->update(['licensed' => false, 'enabled' => false]);
        modules()->flush();

        $submittal = $this->register();

        // The free-text name carries the responsibility without contact records.
        $this->assertSame('Metro Steel', $submittal->responsibleName());
        $this->assertSame('2026-08-21', $submittal->submitBy()->toDateString());

        $round = $this->submittals->submit($submittal, [
            'sent_on' => '2026-08-01', 'reviewer_label' => 'Design Consultants',
        ]);
        $this->submittals->recordReturn($round, [
            'result' => SubmittalReview::RESULT_APPROVED, 'returned_on' => '2026-09-10',
        ]);

        // And the notice clock still works, on the shipped default period rather than a contract's own.
        $event = $this->submittals->raiseDelayForOverrun($round->refresh());
        $this->assertSame(28, (int) $event->notice_days);
    }
}
