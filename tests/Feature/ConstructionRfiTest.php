<?php

namespace Tests\Feature;

use App\Modules\Construction\Models\Job;
use App\Modules\ConstructionField\Filament\Resources\Rfis\Pages\ListRfis;
use App\Modules\ConstructionField\Models\DelayEvent;
use App\Modules\ConstructionField\Models\Rfi;
use App\Modules\ConstructionField\Services\RfiService;
use App\Modules\Core\Models\CompanyModule;
use Filament\Actions\Testing\TestAction;
use InvalidArgumentException;
use Livewire\Livewire;
use Tests\AccountingTestCase;
use Tests\Concerns\InteractsWithTenant;

/**
 * The RFI register — §16.2, Phase 9d.
 *
 * **Every time figure is computed and none is stored**, which is §16.2's closing line: "days open, overdue and response
 * time are all computed". A stored days-open is wrong by one every midnight; a stored overdue flag is wrong the moment
 * somebody moves the required-by date.
 *
 * Four properties carry the register, and each has a failure behind it:
 *
 *  - **The numbering has no gaps, so nothing is deleted.** A hole in a register both sides quote by number is
 *    indistinguishable from a removal somebody wanted. Cancellation with a reason is the way out.
 *  - **`ball_in_court` is a role and a person, both.** The role is what "seventeen RFIs sitting with the Architect"
 *    counts; the person is who a chase goes to, and they change three times over a two-year job.
 *  - **Impact is a flag with an estimate beside it.** Forcing a number at raise time makes a column of zeros that later
 *    reads as *no impact* when it meant *not yet assessed*.
 *  - **A stated time impact with no delay event behind it is money already lost**, and the notice runs from the day the
 *    answer was needed — not today, and not the day the question was asked.
 */
class ConstructionRfiTest extends AccountingTestCase
{
    use InteractsWithTenant;

    private Job $job;

    private RfiService $rfis;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs($this->makeUser('Administrator', 'rfi@test.local'));
        $this->setCurrentTenant();

        foreach (['construction', 'construction_field'] as $module) {
            CompanyModule::updateOrCreate(
                ['company_id' => $this->tenant->getKey(), 'module' => $module],
                ['licensed' => true, 'enabled' => true],
            );
        }
        modules()->flush();

        $this->job = Job::create(['code' => 'J-1', 'name' => 'Tower']);
        $this->rfis = app(RfiService::class);
    }

    /** @param array<string, mixed> $attributes */
    private function raise(array $attributes = []): Rfi
    {
        return $this->rfis->raise($this->job, array_merge([
            'subject' => 'Lintel size over opening D-04',
            'question' => 'The schedule shows a 200 lintel and the detail shows 150. Which governs?',
            'proposed_solution' => 'Use the 200 as scheduled.',
            'ball_in_court' => 'architect',
            'raised_on' => '2026-08-01',
            'required_by' => '2026-08-15',
        ], $attributes));
    }

    // ------------------------------------------------------------------ raising and numbering

    public function test_the_register_numbers_itself_per_job(): void
    {
        $first = $this->raise();
        $second = $this->raise(['subject' => 'Slab thickness at grid C']);

        $annexe = Job::create(['code' => 'J-2', 'name' => 'Annexe']);
        $other = $this->rfis->raise($annexe, [
            'subject' => 'Drainage invert', 'question' => 'What level?', 'ball_in_court' => 'engineer',
        ]);

        $this->assertSame('RFI-1', $first->rfi_number);
        $this->assertSame('RFI-2', $second->rfi_number);
        $this->assertSame('RFI-1', $other->rfi_number, 'the series is per job');
    }

    /** An imported register that starts at 100 continues from 101 — read off the number, not counted. */
    public function test_the_series_continues_from_whatever_is_highest(): void
    {
        $this->raise(['rfi_number' => 'RFI-100']);

        $this->assertSame('RFI-101', $this->rfis->nextNumber($this->job));
    }

    /**
     * **Nothing is deleted**, because a gap in a register quoted by number is indistinguishable from a removal.
     */
    public function test_an_rfi_cannot_be_deleted(): void
    {
        $rfi = $this->raise();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('cannot be deleted');

        $rfi->delete();
    }

    public function test_an_rfi_needs_a_subject_a_question_and_a_court(): void
    {
        foreach ([
            [['subject' => ' '], 'needs a subject'],
            [['question' => ' '], 'needs a question'],
            [['ball_in_court' => 'nobody'], 'court to sit in'],
        ] as [$override, $expected]) {
            try {
                $this->raise($override);
                $this->fail("Expected a refusal mentioning: {$expected}");
            } catch (InvalidArgumentException $e) {
                $this->assertStringContainsString($expected, $e->getMessage());
            }
        }
    }

    public function test_an_rfi_opens_with_no_impact_stated(): void
    {
        $rfi = $this->raise();

        $this->assertSame(Rfi::STATUS_OPEN, $rfi->status);
        $this->assertSame(Rfi::IMPACT_NONE, $rfi->cost_impact_flag);
        $this->assertSame(Rfi::IMPACT_NONE, $rfi->time_impact_flag);
        $this->assertNotNull($rfi->raised_by);
    }

    // ------------------------------------------------------------------ the clock

    /** **Computed, not stored** — §16.2's closing line. */
    public function test_days_open_and_days_until_required_are_computed_as_at_a_date(): void
    {
        $rfi = $this->raise();

        $this->assertSame(9, $rfi->daysOpen('2026-08-10'));
        $this->assertSame(5, $rfi->daysUntilRequired('2026-08-10'));
        $this->assertFalse($rfi->isOverdue('2026-08-10'));

        // Negative once the date has passed, which is what a chase list sorts on.
        $this->assertSame(-5, $rfi->daysUntilRequired('2026-08-20'));
        $this->assertTrue($rfi->isOverdue('2026-08-20'));
    }

    public function test_an_rfi_with_no_required_by_is_never_overdue_but_is_still_counted_as_open(): void
    {
        $rfi = $this->raise(['required_by' => null]);

        $this->assertNull($rfi->daysUntilRequired('2026-09-01'));
        $this->assertFalse($rfi->isOverdue('2026-09-01'));
        $this->assertSame(31, $rfi->daysOpen('2026-09-01'));
    }

    /**
     * **The response time belongs to the other side, and an unanswered RFI has none.**
     *
     * Null rather than days-so-far: reporting one would put a flattering average in front of somebody negotiating about
     * lateness.
     */
    public function test_response_time_is_null_until_it_is_answered(): void
    {
        $rfi = $this->raise();

        $this->assertNull($rfi->responseDays());

        $rfi = $this->rfis->answer($rfi, ['answer' => 'The 200 governs.', 'answered_on' => '2026-08-11']);

        $this->assertSame(10, $rfi->responseDays());
        $this->assertSame(10, $rfi->daysOpen('2026-09-01'), 'days open stops at the answer');
        $this->assertFalse($rfi->answeredLate());
        $this->assertFalse($rfi->isOverdue('2026-09-01'), 'an answered RFI is not overdue');
    }

    /** Dated the day it was given, not the day it was typed — and late is recorded as late. */
    public function test_a_late_answer_is_recorded_and_marked(): void
    {
        $rfi = $this->rfis->answer($this->raise(), [
            'answer' => 'The 200 governs.',
            'answered_on' => '2026-08-25',
        ]);

        $this->assertTrue($rfi->answeredLate());
        $this->assertSame(24, $rfi->responseDays());
        $this->assertSame(Rfi::STATUS_ANSWERED, $rfi->status);
    }

    public function test_an_answer_needs_words(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('needs recording in words');

        $this->rfis->answer($this->raise(), ['answered_on' => '2026-08-11']);
    }

    // ------------------------------------------------------------------ closing and cancelling

    /**
     * **Closing requires an answer**, because closing an unanswered question takes it off the one list that matters
     * while it is still outstanding.
     */
    public function test_an_unanswered_rfi_cannot_be_closed(): void
    {
        $rfi = $this->raise();

        try {
            $this->rfis->close($rfi);
            $this->fail('An unanswered RFI should not close.');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('no answer against it', $e->getMessage());
        }

        $answered = $this->rfis->answer($rfi, ['answer' => 'The 200 governs.', 'answered_on' => '2026-08-11']);
        $closed = $this->rfis->close($answered, '2026-08-12');

        $this->assertSame(Rfi::STATUS_CLOSED, $closed->status);
        $this->assertSame('2026-08-12', $closed->closed_on->toDateString());
        $this->assertNotNull($closed->closed_by);
    }

    /** Cancelling keeps the number and says why — the alternative leaves a hole somebody has to explain. */
    public function test_cancelling_keeps_the_number_and_the_reason(): void
    {
        $rfi = $this->rfis->cancel($this->raise(), 'Superseded by revision C of the lintel schedule.');

        $this->assertSame(Rfi::STATUS_CANCELLED, $rfi->status);
        $this->assertStringContainsString('revision C', $rfi->cancel_reason);
        $this->assertSame('RFI-1', $rfi->rfi_number);
        $this->assertSame('RFI-2', $this->rfis->nextNumber($this->job), 'the number stays used');
    }

    public function test_cancelling_needs_a_reason(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('needs a reason');

        $this->rfis->cancel($this->raise(), '  ');
    }

    public function test_a_closed_rfi_takes_no_further_changes(): void
    {
        $rfi = $this->rfis->close(
            $this->rfis->answer($this->raise(), ['answer' => 'Yes.', 'answered_on' => '2026-08-11']),
        );

        foreach ([
            fn () => $this->rfis->update($rfi, ['subject' => 'Something else']),
            fn () => $this->rfis->answer($rfi, ['answer' => 'Actually no.']),
            fn () => $this->rfis->close($rfi),
            fn () => $this->rfis->cancel($rfi, 'Changed my mind.'),
        ] as $attempt) {
            try {
                $attempt();
                $this->fail('A closed RFI should be immutable.');
            } catch (InvalidArgumentException $e) {
                $this->assertMatchesRegularExpression('/closed/', $e->getMessage());
            }
        }
    }

    // ------------------------------------------------------------------ the register's reports

    /** **"Seventeen RFIs sitting with the Architect"** — §16.2's named report, counted by role. */
    public function test_the_register_counts_by_court(): void
    {
        $this->raise(['ball_in_court' => 'architect']);
        $this->raise(['subject' => 'B', 'ball_in_court' => 'architect']);
        $this->raise(['subject' => 'C', 'ball_in_court' => 'engineer']);

        // Answered, so no longer sitting with anybody.
        $this->rfis->answer($this->raise(['subject' => 'D', 'ball_in_court' => 'architect']), [
            'answer' => 'Done.', 'answered_on' => '2026-08-10',
        ]);

        $this->assertSame(['architect' => 2, 'engineer' => 1], $this->rfis->byCourt($this->job));
    }

    public function test_the_outstanding_list_is_ordered_by_when_the_answer_is_needed(): void
    {
        $this->raise(['subject' => 'Later', 'required_by' => '2026-09-01']);
        $this->raise(['subject' => 'Sooner', 'required_by' => '2026-08-05']);

        $this->assertSame(
            ['Sooner', 'Later'],
            $this->rfis->outstanding($this->job)->pluck('subject')->all(),
        );
    }

    public function test_overdue_is_asked_as_at_a_date(): void
    {
        $this->raise(['required_by' => '2026-08-15']);
        $this->raise(['subject' => 'B', 'required_by' => '2026-09-15']);

        $this->assertCount(0, $this->rfis->overdue($this->job, '2026-08-10'));
        $this->assertCount(1, $this->rfis->overdue($this->job, '2026-08-20'));
        $this->assertCount(2, $this->rfis->overdue($this->job, '2026-10-01'));
    }

    /** The average is over answered RFIs only, and null rather than zero where none has been. */
    public function test_the_average_response_time_is_null_until_something_is_answered(): void
    {
        $this->raise();

        $this->assertNull($this->rfis->averageResponseDays($this->job));

        $this->rfis->answer($this->raise(['subject' => 'B']), ['answer' => 'Yes.', 'answered_on' => '2026-08-05']);
        $this->rfis->answer($this->raise(['subject' => 'C']), ['answer' => 'Yes.', 'answered_on' => '2026-08-11']);

        $this->assertSame(7.0, $this->rfis->averageResponseDays($this->job));
    }

    /** A stated impact with no figure four months on is the register's own loose end. */
    public function test_stated_impacts_with_no_figure_are_reported(): void
    {
        $unquantified = $this->raise(['cost_impact_flag' => Rfi::IMPACT_YES]);
        $this->raise(['subject' => 'B', 'cost_impact_flag' => Rfi::IMPACT_YES, 'cost_impact_estimate' => 250_000]);
        $this->raise(['subject' => 'C', 'cost_impact_flag' => Rfi::IMPACT_POSSIBLE]);

        $loose = $this->rfis->unquantifiedImpacts($this->job);

        $this->assertCount(1, $loose, 'possible is not a stated impact');
        $this->assertSame($unquantified->getKey(), $loose->first()->getKey());
        $this->assertTrue($unquantified->impactUnquantified());
    }

    // ------------------------------------------------------------------ the notice clock

    /**
     * **The exposure: a stated time impact with nobody notified.**
     *
     * `possible` is excluded on purpose — a notice for every impact somebody is still assessing turns the notice
     * register into noise nobody reads.
     */
    public function test_a_stated_time_impact_with_no_delay_event_is_the_exposure(): void
    {
        $exposed = $this->raise(['time_impact_flag' => Rfi::IMPACT_YES, 'time_impact_days' => 10]);
        $this->raise(['subject' => 'B', 'time_impact_flag' => Rfi::IMPACT_POSSIBLE]);
        $this->raise(['subject' => 'C']);

        $unnotified = $this->rfis->unnotifiedTimeImpacts($this->job);

        $this->assertCount(1, $unnotified);
        $this->assertSame($exposed->getKey(), $unnotified->first()->getKey());
        $this->assertTrue($exposed->timeImpactUnnotified());
    }

    /**
     * **The delay is dated the day the answer was needed.**
     *
     * Not today — dating it now is how a claim is time-barred by its own paperwork — and not the day the question was
     * asked, because the work was not blocked then.
     */
    public function test_raising_the_delay_dates_it_from_the_day_the_answer_was_needed(): void
    {
        $rfi = $this->raise(['time_impact_flag' => Rfi::IMPACT_YES, 'time_impact_days' => 10]);

        $event = $this->rfis->raiseDelay($rfi);

        $this->assertSame('2026-08-15', $event->occurred_on->toDateString());
        $this->assertSame('late_information', $event->cause_category);
        $this->assertSame(10, (int) $event->claimed_days);
        $this->assertStringContainsString('RFI-1', $event->description);
        $this->assertStringContainsString('Lintel size', $event->title);

        // And the clock is now running on the delay register, from that date.
        $this->assertSame('2026-09-12', $event->notice_required_by->toDateString());

        $rfi->refresh();
        $this->assertSame($event->getKey(), $rfi->delay_event_id);
        $this->assertFalse($rfi->timeImpactUnnotified());
        $this->assertCount(0, $this->rfis->unnotifiedTimeImpacts($this->job));
    }

    /** With no required-by date the delay starts the day the question was raised — the only date there is. */
    public function test_without_a_required_by_the_delay_starts_at_the_raise_date(): void
    {
        $rfi = $this->raise(['required_by' => null, 'time_impact_flag' => Rfi::IMPACT_YES]);

        $this->assertSame('2026-08-01', $this->rfis->raiseDelay($rfi)->occurred_on->toDateString());
    }

    public function test_a_delay_cannot_be_raised_twice_or_without_a_stated_impact(): void
    {
        $noImpact = $this->raise();

        try {
            $this->rfis->raiseDelay($noImpact);
            $this->fail('An RFI with no stated time impact should not raise a notice.');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('does not state a time impact', $e->getMessage());
        }

        $rfi = $this->raise(['subject' => 'B', 'time_impact_flag' => Rfi::IMPACT_YES]);
        $this->rfis->raiseDelay($rfi);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('already has a delay event');

        $this->rfis->raiseDelay($rfi->refresh());
    }

    public function test_the_variation_it_became_is_recorded(): void
    {
        $rfi = $this->rfis->recordVariation($this->raise(), 4242);

        $this->assertSame(4242, (int) $rfi->variation_id);
    }

    // ------------------------------------------------------------------ the screen

    public function test_the_register_shows_the_clock_and_the_court(): void
    {
        $rfi = $this->raise(['time_impact_flag' => Rfi::IMPACT_YES, 'time_impact_days' => 10]);

        Livewire::test(ListRfis::class)
            ->assertCanSeeTableRecords([$rfi])
            ->assertSee('RFI-1')
            ->assertSee('Architect')
            // The exposure, named rather than left blank.
            ->assertSee('no notice');
    }

    public function test_the_screen_records_an_answer_and_then_closes(): void
    {
        $rfi = $this->raise();

        Livewire::test(ListRfis::class)
            ->callAction(TestAction::make('answer')->table($rfi), [
                'answer' => 'The 200 lintel governs.',
                'answered_on' => '2026-08-11',
            ]);

        $this->assertSame(Rfi::STATUS_ANSWERED, $rfi->refresh()->status);

        Livewire::test(ListRfis::class)->callAction(TestAction::make('close')->table($rfi));

        $this->assertSame(Rfi::STATUS_CLOSED, $rfi->refresh()->status);
    }

    public function test_the_screen_raises_the_delay_event(): void
    {
        $rfi = $this->raise(['time_impact_flag' => Rfi::IMPACT_YES, 'time_impact_days' => 10]);

        Livewire::test(ListRfis::class)
            ->callAction(TestAction::make('raiseDelayEvent')->table($rfi), [
                'title' => 'Late information: lintel schedule',
                'claimed_days' => 10,
            ]);

        $event = DelayEvent::query()->firstOrFail();

        $this->assertSame('2026-08-15', $event->occurred_on->toDateString());
        $this->assertSame($event->getKey(), $rfi->refresh()->delay_event_id);
    }

    /** A service refusal reaches the screen as a sentence rather than an exception. */
    public function test_the_screen_surfaces_a_refusal(): void
    {
        $rfi = $this->raise();

        Livewire::test(ListRfis::class)
            ->callAction(TestAction::make('cancel')->table($rfi), ['reason' => 'No longer relevant.']);

        $this->assertSame(Rfi::STATUS_CANCELLED, $rfi->refresh()->status);
    }

    /** The register works with only the spine and this module — §18. */
    public function test_the_register_works_with_only_the_spine_and_this_module(): void
    {
        CompanyModule::query()
            ->where('company_id', $this->tenant->getKey())
            ->whereIn('module', ['construction_costing', 'construction_contracts', 'invoicing', 'accounting'])
            ->update(['licensed' => false, 'enabled' => false]);
        modules()->flush();

        $rfi = $this->raise(['time_impact_flag' => Rfi::IMPACT_YES, 'time_impact_days' => 5]);

        // The role carries the register on its own; only the chasing email loses its address.
        $this->assertSame('Architect', $rfi->courtLabel());
        $this->assertNull($rfi->ball_in_court_contact_id);
        $this->assertCount(1, $this->rfis->unnotifiedTimeImpacts($this->job));

        // And the notice clock still works, on the shipped default period rather than a contract's own.
        $event = $this->rfis->raiseDelay($rfi);
        $this->assertSame(28, (int) $event->notice_days);
    }
}
