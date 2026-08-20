<?php

namespace Tests\Feature;

use App\Modules\Construction\Models\Job;
use App\Modules\ConstructionField\Filament\Resources\DailyLogs\Pages\ListDailyLogs;
use App\Modules\ConstructionField\Models\DailyLog;
use App\Modules\ConstructionField\Models\DailyLogEvent;
use App\Modules\ConstructionField\Models\DelayEvent;
use App\Modules\ConstructionField\Services\DailyLogService;
use App\Modules\Core\Models\CompanyModule;
use Filament\Actions\Testing\TestAction;
use InvalidArgumentException;
use Livewire\Livewire;
use Tests\AccountingTestCase;
use Tests\Concerns\InteractsWithTenant;

/**
 * The site diary — §16.1, Phase 9b.
 *
 * **Two rules make a diary evidence, and both are asserted here.** §16.1: "unique on `(job_id, log_date)` — the
 * constraint is the feature, because two site diaries for one day is how a dispute starts", and "approval locks the
 * row. An editable site diary is not evidence."
 *
 * Four more properties:
 *
 *  - **The lock reaches the children.** Manpower, plant and events are where the numbers a claim is built from live, so
 *    a lock that stopped at the header would protect the prose and leave the figures editable.
 *  - **The claim is in two columns, not the prose.** §16.1: `working_conditions` and `weather_hours_lost` "not the free
 *    text, are what a weather-based extension of time is actually made of".
 *  - **Plant hours stay in three columns**, because idle against working is a standing-time claim and breakdown is a
 *    different risk again.
 *  - **A diary event with no delay event behind it is the exposure**, which joins §16.1 to §13's clock: hours already
 *    lost, already written down, with nobody notified.
 */
class ConstructionDailyLogTest extends AccountingTestCase
{
    use InteractsWithTenant;

    private Job $job;

    private DailyLogService $logs;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs($this->makeUser('Administrator', 'diary@test.local'));
        $this->setCurrentTenant();

        foreach (['construction', 'construction_field'] as $module) {
            CompanyModule::updateOrCreate(
                ['company_id' => $this->tenant->getKey(), 'module' => $module],
                ['licensed' => true, 'enabled' => true],
            );
        }
        modules()->flush();

        $this->job = Job::create(['code' => 'J-1', 'name' => 'Tower']);
        $this->logs = app(DailyLogService::class);
    }

    /** @param array<string, mixed> $attributes */
    private function day(string $date = '2026-08-20', array $attributes = []): DailyLog
    {
        return $this->logs->open($this->job, $date, $attributes);
    }

    // ------------------------------------------------------------------ one diary per day

    /** **The constraint is the feature** (§16.1), and the refusal is a sentence rather than a database error. */
    public function test_a_second_diary_for_one_day_is_refused(): void
    {
        $this->day('2026-08-20');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('how a dispute starts');

        $this->day('2026-08-20');
    }

    /** Another job on the same day is fine — the constraint is per job. */
    public function test_another_job_may_have_its_own_diary_that_day(): void
    {
        $this->day('2026-08-20');

        $annexe = Job::create(['code' => 'J-2', 'name' => 'Annexe']);

        $this->assertNotNull($this->logs->open($annexe, '2026-08-20'));
        $this->assertSame(2, DailyLog::query()->count());
    }

    /** A workable day is the default, so an unfilled diary does not read as a claim. */
    public function test_a_day_is_workable_with_no_hours_lost_by_default(): void
    {
        $log = $this->day();

        $this->assertSame(DailyLog::CONDITION_WORKABLE, $log->working_conditions);
        $this->assertEquals(0, $log->weather_hours_lost);
        $this->assertFalse($log->isApproved());
    }

    // ------------------------------------------------------------------ approval locks it

    /** **"An editable site diary is not evidence."** Approval records who and when, and locks the row. */
    public function test_approval_locks_the_day_and_records_who_signed_it(): void
    {
        $log = $this->logs->approve($this->day());

        $this->assertTrue($log->isApproved());
        $this->assertNotNull($log->approved_by);
        $this->assertNotNull($log->approved_at);
        $this->assertFalse($log->isEditable());

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('not evidence');

        $this->logs->update($log, ['work_summary' => 'Something else entirely.']);
    }

    /**
     * **The lock reaches the children**, which is where the numbers are.
     *
     * A lock that stopped at the header would protect the weather prose and leave the man-hours and the plant hours
     * editable — which is the half a claim is built from.
     */
    public function test_the_lock_reaches_the_manpower_plant_and_events(): void
    {
        $log = $this->logs->approve($this->day());

        foreach ([
            fn () => $this->logs->addManpower($log, ['trade_label' => 'Steel fixer', 'headcount' => 4, 'hours' => 8]),
            fn () => $this->logs->addPlant($log, ['plant_label' => 'EXC-04', 'working_hours' => 8]),
            fn () => $this->logs->addEvent($log, ['kind' => 'stoppage', 'description' => 'Power cut.']),
        ] as $attempt) {
            try {
                $attempt();
                $this->fail('An approved day should take no new rows.');
            } catch (InvalidArgumentException $e) {
                $this->assertStringContainsString('cannot be added to', $e->getMessage());
            }
        }
    }

    public function test_approving_twice_is_refused(): void
    {
        $log = $this->logs->approve($this->day());

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('is locked');

        $this->logs->approve($log);
    }

    /** Approval implies submission on a one-person site office, rather than refusing an unsubmitted day. */
    public function test_approval_stands_in_for_submission_where_nobody_submitted_separately(): void
    {
        $log = $this->logs->approve($this->day());

        $this->assertNotNull($log->submitted_at);
        $this->assertSame($log->approved_by, $log->submitted_by);
    }

    /**
     * Reopening clears the approval and keeps the reason.
     *
     * Refusing outright would leave a wrong signed diary wrong for ever, and a company in that position keeps its real
     * diary in a notebook — worse than a recorded correction by a wide margin.
     */
    public function test_reopening_clears_the_approval_and_keeps_the_reason(): void
    {
        $log = $this->logs->approve($this->day());
        $log = $this->logs->reopen($log, 'The plant hours were transposed.');

        $this->assertFalse($log->isApproved());
        $this->assertTrue($log->wasReopened());
        $this->assertNotNull($log->reopened_by);
        $this->assertStringContainsString('transposed', $log->reopen_reason);

        // And it is editable again, so the correction can actually be made.
        $this->assertNotNull($this->logs->update($log, ['work_summary' => 'Corrected.']));
    }

    public function test_reopening_needs_a_reason(): void
    {
        $log = $this->logs->approve($this->day());

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('needs a reason');

        $this->logs->reopen($log, '   ');
    }

    public function test_an_open_day_cannot_be_reopened(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('already open');

        $this->logs->reopen($this->day(), 'Nothing to reopen.');
    }

    // ------------------------------------------------------------------ the claim columns

    /** **The two columns a weather claim is made of**, kept apart from the prose. */
    public function test_the_conditions_and_hours_lost_carry_the_weather_claim(): void
    {
        $log = $this->logs->update($this->day(), [
            'weather_am' => 'Heavy rain',
            'working_conditions' => DailyLog::CONDITION_STOPPED,
            'weather_hours_lost' => 6.5,
            'delays' => 'Pour abandoned at eleven.',
        ]);

        $this->assertSame(DailyLog::CONDITION_STOPPED, $log->working_conditions);
        $this->assertEquals(6.5, $log->weather_hours_lost);
    }

    // ------------------------------------------------------------------ manpower

    /** Man-hours include overtime, and they are §17's exposure denominator. */
    public function test_man_hours_include_overtime(): void
    {
        $log = $this->day();

        $this->logs->addManpower($log, ['trade_label' => 'Steel fixer', 'headcount' => 4, 'hours' => 32, 'overtime_hours' => 4]);
        $this->logs->addManpower($log, ['trade_label' => 'Mason', 'headcount' => 2, 'hours' => 16]);

        $log->refresh()->load('manpower');

        $this->assertSame(52.0, $log->totalManHours());
        $this->assertSame(6, $log->totalHeadcount());
    }

    /** A row with neither a headcount nor hours adds a company to the day and nobody to the site. */
    public function test_a_manpower_row_needs_a_headcount_or_hours(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('headcount or hours');

        $this->logs->addManpower($this->day(), ['trade_label' => 'Steel fixer']);
    }

    /**
     * **Exposure hours read approved diaries only** — §17.6's "denominator nobody has".
     *
     * A rate computed from drafts would move every time somebody edited one, and a safety rate that moves is a safety
     * rate nobody trusts.
     */
    public function test_exposure_hours_count_only_approved_days(): void
    {
        $open = $this->day('2026-08-20');
        $this->logs->addManpower($open, ['trade_label' => 'Steel fixer', 'headcount' => 4, 'hours' => 32]);

        $this->assertSame(0.0, $this->logs->exposureHours($this->job), 'a draft day is not evidence');

        $this->logs->approve($open->refresh());

        $this->assertSame(32.0, $this->logs->exposureHours($this->job));
    }

    /** And they can be asked of a period, which is how a monthly rate is computed. */
    public function test_exposure_hours_can_be_asked_of_a_period(): void
    {
        foreach (['2026-07-31', '2026-08-20'] as $date) {
            $log = $this->day($date);
            $this->logs->addManpower($log, ['trade_label' => 'Steel fixer', 'headcount' => 1, 'hours' => 10]);
            $this->logs->approve($log->refresh());
        }

        $this->assertSame(20.0, $this->logs->exposureHours($this->job));
        $this->assertSame(10.0, $this->logs->exposureHours($this->job, '2026-08-01', '2026-08-31'));
    }

    // ------------------------------------------------------------------ plant

    /** **Three columns, because they are three arguments.** Idle and breakdown are both standing, and different risks. */
    public function test_plant_hours_stay_in_three_columns(): void
    {
        $log = $this->day();

        $row = $this->logs->addPlant($log, [
            'plant_label' => 'EXC-04',
            'working_hours' => 5, 'idle_hours' => 2, 'breakdown_hours' => 1,
            'idle_reason' => 'Waiting on the steel fixers.',
        ]);

        $this->assertSame(8.0, $row->totalHours());
        $this->assertSame(3.0, $row->standingHours());
        $this->assertFalse($row->isUnexplainedIdle());
        $this->assertSame(3.0, $log->refresh()->load('plant')->standingPlantHours());
    }

    /** Idle time with no reason is flagged, because it is the hour nobody recovers. */
    public function test_idle_time_with_no_reason_is_flagged(): void
    {
        $row = $this->logs->addPlant($this->day(), ['plant_label' => 'CRN-01', 'idle_hours' => 4]);

        $this->assertTrue($row->isUnexplainedIdle());
    }

    public function test_a_plant_row_needs_hours(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('needs hours against it');

        $this->logs->addPlant($this->day(), ['plant_label' => 'EXC-04']);
    }

    // ------------------------------------------------------------------ events, and the notice clock

    public function test_an_event_needs_a_description_and_a_kind(): void
    {
        $log = $this->day();

        try {
            $this->logs->addEvent($log, ['kind' => 'stoppage', 'description' => '  ']);
            $this->fail('An event with no description should be refused.');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('needs describing', $e->getMessage());
        }

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('needs a kind');

        $this->logs->addEvent($log, ['description' => 'Something happened']);
    }

    /**
     * **The exposure: a diary line that cost time with nobody notified.**
     *
     * §13's silence, written down on the day and then forgotten. The contractor's own events are excluded — there is
     * nothing to claim — and so are events that cost no time.
     */
    public function test_an_event_costing_time_with_no_delay_event_is_the_exposure(): void
    {
        $log = $this->day();

        $this->logs->addEvent($log, [
            'kind' => 'stoppage', 'description' => 'No access to the west bay.',
            'hours_lost' => 4, 'responsibility' => 'employer',
        ]);
        $this->logs->addEvent($log, [
            'kind' => 'stoppage', 'description' => 'Our crane broke down.',
            'hours_lost' => 3, 'responsibility' => 'contractor',
        ]);
        $this->logs->addEvent($log, [
            'kind' => 'visitor', 'description' => 'Engineer visited.', 'responsibility' => 'neutral',
        ]);

        $unnotified = $log->refresh()->load('events')->unnotifiedEvents();

        $this->assertCount(1, $unnotified, 'own risk and no-time-lost are not exposure');
        $this->assertStringContainsString('west bay', $unnotified->first()->description);
        $this->assertSame(7.0, $log->eventHoursLost(), 'but every hour lost is still totalled');
    }

    /** And the same question across a job, which is the list somebody should read weekly. */
    public function test_unnotified_events_can_be_asked_of_a_whole_job(): void
    {
        foreach (['2026-08-18', '2026-08-19'] as $date) {
            $this->logs->addEvent($this->day($date), [
                'kind' => 'delay', 'description' => 'Late drawings.',
                'hours_lost' => 5, 'responsibility' => 'employer',
            ]);
        }

        $this->assertCount(2, $this->logs->unnotifiedEvents($this->job));
    }

    /** Once a delay event is attached, the line stops being exposure. */
    public function test_attaching_a_delay_event_clears_the_exposure(): void
    {
        $log = $this->day();
        $event = $this->logs->addEvent($log, [
            'kind' => 'delay', 'description' => 'Late drawings.',
            'hours_lost' => 5, 'responsibility' => 'employer',
        ]);

        $delay = app(\App\Modules\ConstructionField\Services\DelayEventService::class)->raise($this->job, [
            'title' => 'Late drawings', 'cause_category' => 'late_information', 'occurred_on' => '2026-08-20',
        ]);

        $event->update(['delay_event_id' => $delay->getKey()]);

        $this->assertTrue($event->refresh()->isNotified());
        $this->assertCount(0, $this->logs->unnotifiedEvents($this->job));
    }

    // ------------------------------------------------------------------ the screens

    public function test_the_register_shows_the_days_figures(): void
    {
        $log = $this->day('2026-08-20', ['working_conditions' => DailyLog::CONDITION_STOPPED, 'weather_hours_lost' => 6]);
        $this->logs->addManpower($log, ['trade_label' => 'Steel fixer', 'headcount' => 4, 'hours' => 32]);
        $this->logs->addPlant($log, ['plant_label' => 'EXC-04', 'idle_hours' => 3]);

        Livewire::test(ListDailyLogs::class)
            ->assertCanSeeTableRecords([$log])
            ->assertSee('Stopped')
            ->assertSee('Open');
    }

    public function test_the_sign_off_action_locks_the_day(): void
    {
        $log = $this->day();

        Livewire::test(ListDailyLogs::class)
            ->callAction(TestAction::make('approve')->table($log));

        $this->assertTrue($log->refresh()->isApproved());
    }

    public function test_the_reopen_action_demands_a_reason_and_unlocks(): void
    {
        $log = $this->logs->approve($this->day());

        Livewire::test(ListDailyLogs::class)
            ->callAction(TestAction::make('reopen')->table($log), ['reason' => 'Plant hours transposed.']);

        $this->assertFalse($log->refresh()->isApproved());
        $this->assertTrue($log->wasReopened());
    }

    /** The diary works with only the spine and this module — no cost ledger, no contracts, no books (§18). */
    public function test_the_diary_works_with_only_the_spine_and_this_module(): void
    {
        CompanyModule::query()
            ->where('company_id', $this->tenant->getKey())
            ->whereIn('module', ['construction_costing', 'construction_contracts', 'accounting', 'invoicing'])
            ->update(['licensed' => false, 'enabled' => false]);
        modules()->flush();

        $log = $this->day();
        $this->logs->addManpower($log, ['trade_label' => 'Steel fixer', 'headcount' => 4, 'hours' => 32]);
        $this->logs->addPlant($log, ['plant_label' => 'EXC-04', 'working_hours' => 8]);
        $this->logs->approve($log->refresh());

        $this->assertSame(32.0, $this->logs->exposureHours($this->job));
        $this->assertTrue($log->refresh()->isApproved());
    }

    /** And an event on the diary can still start §13's clock, because both live in this module. */
    public function test_a_diary_event_can_raise_a_delay_event_from_the_screen(): void
    {
        $log = $this->day('2026-08-20');
        $event = $this->logs->addEvent($log, [
            'kind' => 'delay', 'description' => 'Access withheld to the west bay all morning.',
            'hours_lost' => 4, 'responsibility' => 'employer',
        ]);

        Livewire::test(
            \App\Modules\ConstructionField\Filament\Resources\DailyLogs\RelationManagers\EventsRelationManager::class,
            ['ownerRecord' => $log, 'pageClass' => \App\Modules\ConstructionField\Filament\Resources\DailyLogs\Pages\EditDailyLog::class],
        )
            ->callAction(TestAction::make('raiseDelayEvent')->table($event), ['cause_category' => 'access']);

        $delay = DelayEvent::query()->firstOrFail();

        // Dated the day it happened, not today — the notice period runs from the event.
        $this->assertSame('2026-08-20', $delay->occurred_on->toDateString());
        $this->assertSame($delay->getKey(), $event->refresh()->delay_event_id);
        $this->assertCount(0, $this->logs->unnotifiedEvents($this->job));
    }

    /** A diary event of a kind that cannot be assessed still records — the register is not a claim form. */
    public function test_every_event_kind_is_accepted(): void
    {
        $log = $this->day();

        foreach (array_keys(DailyLogEvent::KINDS) as $kind) {
            $this->assertNotNull($this->logs->addEvent($log, [
                'kind' => $kind, 'description' => "A {$kind} happened.",
            ]));
        }

        $this->assertCount(count(DailyLogEvent::KINDS), $log->refresh()->events);
    }
}
