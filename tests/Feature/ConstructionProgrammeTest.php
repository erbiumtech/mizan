<?php

namespace Tests\Feature;

use App\Modules\Construction\Models\Job;
use App\Modules\ConstructionField\Filament\Resources\Activities\Pages\EditActivity;
use App\Modules\ConstructionField\Filament\Resources\Activities\Pages\ListActivities;
use App\Modules\ConstructionField\Filament\Resources\Activities\RelationManagers\PredecessorsRelationManager;
use App\Modules\ConstructionField\Models\DelayEvent;
use App\Modules\ConstructionField\Models\ProgrammeActivity;
use App\Modules\ConstructionField\Models\ProgrammeActivityPredecessor;
use App\Modules\ConstructionField\Models\Rfi;
use App\Modules\ConstructionField\Models\Submittal;
use App\Modules\ConstructionField\Services\DelayEventService;
use App\Modules\ConstructionField\Services\ProgrammeService;
use App\Modules\ConstructionField\Services\RfiService;
use App\Modules\ConstructionField\Services\SubmittalService;
use App\Modules\Core\Models\CompanyModule;
use Filament\Actions\Testing\TestAction;
use InvalidArgumentException;
use Livewire\Livewire;
use ReflectionClass;
use Tests\AccountingTestCase;
use Tests\Concerns\InteractsWithTenant;

/**
 * The programme — §13, Phase 9g.
 *
 * **"Store the programme; never solve it."** The first assertion in this file is a structural one: there is no method
 * anywhere in the programme's own code that turns a relationship and a lag into a date. §13 forbids a forward pass, a
 * backward pass, float derivation and a critical-path solver, and the reason is not effort — it is that the accepted
 * programme lives in P6 and "a second scheduler here that disagreed with the submitted programme is worse than no
 * scheduler at all".
 *
 * So `is_critical` and `total_float_days` are imported columns with a `source` beside them, and the tests below check
 * that nothing writes them except whoever recorded the activity.
 *
 * **What the programme is worth storing for is the exposure**, and it is the fifth instance of this section's shape:
 * days a priced contract milestone is late against the *accepted* programme, less the extension of time actually
 * awarded. Damages accrue against that remainder, and it can only be computed because baseline and planned dates are
 * kept apart and because delay events record what was determined rather than claimed.
 */
class ConstructionProgrammeTest extends AccountingTestCase
{
    use InteractsWithTenant;

    private Job $job;

    private ProgrammeService $programme;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs($this->makeUser('Administrator', 'programme@test.local'));
        $this->setCurrentTenant();

        foreach (['construction', 'construction_field'] as $module) {
            CompanyModule::updateOrCreate(
                ['company_id' => $this->tenant->getKey(), 'module' => $module],
                ['licensed' => true, 'enabled' => true],
            );
        }
        modules()->flush();

        $this->job = Job::create(['code' => 'J-1', 'name' => 'Tower']);
        $this->programme = app(ProgrammeService::class);
    }

    /** @param array<string, mixed> $attributes */
    private function activity(array $attributes = []): ProgrammeActivity
    {
        return $this->programme->record($this->job, array_merge([
            'code' => 'A1000',
            'name' => 'Level 4 slab',
            'baseline_start' => '2026-08-01',
            'baseline_finish' => '2026-08-31',
            'planned_start' => '2026-08-05',
            'planned_finish' => '2026-09-05',
        ], $attributes));
    }

    // ------------------------------------------------------------------ the programme is not solved

    /**
     * **The structural assertion: nothing schedules.**
     *
     * §13 forbids a forward pass, a backward pass, float derivation and critical-path calculation. A test that only
     * checked behaviour would pass on the day somebody added `recalculateDates()`, so this checks the surface: no public
     * method on the service, the activity or the link is named for scheduling.
     */
    public function test_nothing_in_the_programme_calculates_a_date(): void
    {
        $forbidden = '/forward|backward|recalculat|reschedul|critical.?path|levell?ing|solve|derive.?float|compute.?date/i';
        $checked = 0;

        foreach ([ProgrammeService::class, ProgrammeActivity::class, ProgrammeActivityPredecessor::class] as $class) {
            foreach ((new ReflectionClass($class))->getMethods() as $method) {
                // Declared here only. Eloquent's own `resolveCustomBuilderClass` contains "solve", and inheriting the
                // framework is not the thing this test is about.
                if ($method->getDeclaringClass()->getName() !== $class) {
                    continue;
                }

                $checked++;

                $this->assertDoesNotMatchRegularExpression(
                    $forbidden,
                    $method->getName(),
                    "{$class}::{$method->getName()}() reads like a scheduler. §13: store the programme, never solve it."
                );
            }
        }

        // A test that checked nothing would pass forever.
        $this->assertGreaterThan(30, $checked, 'the reflection found the programme\'s own methods');
    }

    /** **Float and criticality are imported, and the source says which tool said so.** */
    public function test_float_and_criticality_are_imported_facts_with_a_source(): void
    {
        $manual = $this->activity();
        $imported = $this->activity([
            'code' => 'A2000',
            'source' => 'p6_xer',
            'external_id' => 'A2000',
            'total_float_days' => -3,
            'is_critical' => true,
        ]);

        $this->assertFalse($manual->isImported());
        $this->assertSame('Entered here', $manual->sourceLabel());
        $this->assertNull($manual->total_float_days, 'nothing derives float');

        $this->assertTrue($imported->isImported());
        $this->assertSame('Primavera XER', $imported->sourceLabel());
        $this->assertSame(-3, $imported->total_float_days, 'negative float is a real P6 figure');
        $this->assertTrue($imported->is_critical);

        // The report that matters: a job whose critical activities were typed here.
        $this->assertSame(['p6_xer' => 1], $this->programme->criticalBySource($this->job));
    }

    /** The import key is unique per job *and* source, so two tools cannot collide on an id. */
    public function test_two_sources_may_carry_the_same_external_id(): void
    {
        $this->activity(['code' => 'A1', 'source' => 'p6_xer', 'external_id' => '1000']);
        $second = $this->activity(['code' => 'A1', 'source' => 'msp_xml', 'external_id' => '1000']);

        $this->assertSame('1000', $second->external_id);
        $this->assertSame(2, ProgrammeActivity::query()->count());
    }

    public function test_an_activity_needs_an_id_and_a_name(): void
    {
        foreach ([['code' => ' '], ['name' => ' ']] as $override) {
            try {
                $this->activity($override);
                $this->fail('An unnameable activity should be refused.');
            } catch (InvalidArgumentException $e) {
                $this->assertStringContainsString('The programme is read against', $e->getMessage());
            }
        }
    }

    public function test_the_date_pairs_that_are_not_facts_are_refused(): void
    {
        foreach ([
            [['actual_start' => '2026-09-10', 'actual_finish' => '2026-09-01'], 'finish before it started'],
            [['baseline_start' => '2026-09-10', 'baseline_finish' => '2026-09-01'], 'baseline cannot finish'],
            [['planned_start' => '2026-09-10', 'planned_finish' => '2026-09-01'], 'planned finish cannot precede'],
        ] as [$override, $expected]) {
            try {
                $this->activity($override);
                $this->fail("Expected a refusal mentioning: {$expected}");
            } catch (InvalidArgumentException $e) {
                $this->assertStringContainsString($expected, $e->getMessage());
            }
        }
    }

    /** An activity finishing before it was due is ordinary and often the point — not an error. */
    public function test_finishing_early_is_not_refused(): void
    {
        $activity = $this->activity(['actual_start' => '2026-08-01', 'actual_finish' => '2026-08-20']);

        $this->assertTrue($activity->isComplete());
        $this->assertSame(0, $activity->daysLateAgainstBaseline());
    }

    // ------------------------------------------------------------------ baseline against plan

    /**
     * **Lateness is measured against the accepted programme, not the current plan.**
     *
     * A job that has re-programmed around its own delays reports zero against its plan and the truth against its
     * baseline — which is why §13 keeps the two pairs of dates apart.
     */
    public function test_lateness_against_the_baseline_and_the_plan_are_different_numbers(): void
    {
        $activity = $this->activity();

        $this->assertSame(15, $activity->daysLateAgainstBaseline('2026-09-15'));
        $this->assertSame(10, $activity->daysLateAgainstPlan('2026-09-15'), 'the plan has already moved');
    }

    /** Once finished, the figure stops moving. */
    public function test_lateness_stops_at_the_actual_finish(): void
    {
        $activity = $this->programme->progress($this->activity(), [
            'actual_start' => '2026-08-05',
            'actual_finish' => '2026-09-10',
            'percent_complete' => 100,
            'data_date' => '2026-09-10',
        ]);

        $this->assertSame(10, $activity->daysLateAgainstBaseline('2026-12-31'));
    }

    public function test_should_have_started_needs_no_network(): void
    {
        $late = $this->activity();
        $future = $this->activity(['code' => 'A2', 'planned_start' => '2027-01-01', 'planned_finish' => '2027-02-01']);

        $this->assertTrue($late->isLateToStart('2026-08-20'));
        $this->assertFalse($future->isLateToStart('2026-08-20'));

        $this->assertSame(
            ['A1000'],
            $this->programme->lateToStart($this->job, '2026-08-20')->pluck('code')->all(),
        );
    }

    /** Behind where the baseline says it should be — percent complete against elapsed time. */
    public function test_progress_is_compared_with_elapsed_baseline_time(): void
    {
        $activity = $this->activity();

        // 2026-08-01 to 2026-08-31 is a 30-day baseline; the 16th is halfway.
        $this->assertSame(50.0, $activity->elapsedBaselinePercent('2026-08-16'));
        $this->assertTrue($activity->isBehindBaseline('2026-08-16'), 'nothing done and half the time gone');

        $activity = $this->programme->progress($activity, [
            'percent_complete' => 60,
            'data_date' => '2026-08-16',
        ]);

        $this->assertFalse($activity->isBehindBaseline('2026-08-16'));
        $this->assertSame(['A1000'], $this->programme->behindBaseline($this->job, '2026-08-31')->pluck('code')->all());
    }

    /** A milestone has no span, so there is no elapsed percentage — an honest "cannot tell". */
    public function test_a_zero_span_baseline_has_no_elapsed_percentage(): void
    {
        $milestone = $this->activity([
            'code' => 'M1',
            'activity_type' => ProgrammeActivity::TYPE_FINISH_MILESTONE,
            'baseline_start' => '2026-08-31',
            'baseline_finish' => '2026-08-31',
            'planned_start' => '2026-08-31',
            'planned_finish' => '2026-08-31',
        ]);

        $this->assertNull($milestone->elapsedBaselinePercent('2026-08-31'));
        $this->assertFalse($milestone->isBehindBaseline('2026-08-31'));
    }

    // ------------------------------------------------------------------ progress

    /** **100% needs a finish, and a finish needs 100%** — a row that says both is half a fact twice over. */
    public function test_progress_refuses_the_combinations_that_are_not_facts(): void
    {
        $activity = $this->activity();

        try {
            $this->programme->progress($activity, ['percent_complete' => 100, 'data_date' => '2026-09-01']);
            $this->fail('100% with no finish date should be refused.');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('needs an actual finish date', $e->getMessage());
        }

        try {
            $this->programme->progress($activity, [
                'actual_start' => '2026-08-05',
                'actual_finish' => '2026-09-01',
                'percent_complete' => 80,
                'data_date' => '2026-09-01',
            ]);
            $this->fail('A finished activity at 80% should be refused.');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('cannot be at 80%', $e->getMessage());
        }

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('needs a data date');

        $this->programme->progress($activity, ['percent_complete' => 40]);
    }

    public function test_progress_is_recorded_as_at_its_data_date(): void
    {
        $activity = $this->programme->progress($this->activity(), [
            'actual_start' => '2026-08-05',
            'percent_complete' => 40,
            'data_date' => '2026-08-20',
        ]);

        $this->assertTrue($activity->hasStarted());
        $this->assertFalse($activity->isComplete());
        $this->assertSame('2026-08-20', $activity->data_date->toDateString());
        $this->assertSame(40.0, (float) $activity->percent_complete);
    }

    // ------------------------------------------------------------------ predecessors, stored not solved

    /** **Adding a link moves nothing.** The dates on both activities are exactly as they were. */
    public function test_a_predecessor_link_changes_no_date(): void
    {
        $first = $this->activity(['code' => 'A1']);
        $second = $this->activity([
            'code' => 'A2', 'planned_start' => '2026-08-02', 'planned_finish' => '2026-08-10',
        ]);

        $dates = fn (ProgrammeActivity $a): array => collect(
            ['planned_start', 'planned_finish', 'baseline_start', 'baseline_finish']
        )->mapWithKeys(fn (string $key): array => [$key => $a->{$key}?->toDateString()])->all();

        $before = $dates($second);

        $link = $this->programme->addPredecessor($second, $first, ProgrammeActivityPredecessor::FINISH_TO_START, 5);

        $this->assertSame('FS +5 days', $link->describe());
        $this->assertSame(
            $before,
            $dates($second->refresh()),
            'a link is stored, never solved — nothing recalculates a successor'
        );
    }

    public function test_a_negative_lag_is_a_lead(): void
    {
        $first = $this->activity(['code' => 'A1']);
        $second = $this->activity(['code' => 'A2']);

        $link = $this->programme->addPredecessor($second, $first, ProgrammeActivityPredecessor::START_TO_START, -3);

        $this->assertTrue($link->isLead());
        $this->assertSame('SS -3 days', $link->describe());
    }

    public function test_a_link_is_refused_to_itself_across_jobs_or_with_an_unknown_relationship(): void
    {
        $activity = $this->activity(['code' => 'A1']);
        $other = $this->programme->record(
            Job::create(['code' => 'J-2', 'name' => 'Annexe']),
            ['code' => 'B1', 'name' => 'Elsewhere'],
        );

        foreach ([
            [fn () => $this->programme->addPredecessor($activity, $activity), 'cannot precede itself'],
            [fn () => $this->programme->addPredecessor($activity, $other), 'same job'],
            [fn () => $this->programme->addPredecessor($activity, $this->activity(['code' => 'A2']), 'xx'), 'P6 exports'],
        ] as [$attempt, $expected]) {
            try {
                $attempt();
                $this->fail("Expected a refusal mentioning: {$expected}");
            } catch (InvalidArgumentException $e) {
                $this->assertStringContainsString($expected, $e->getMessage());
            }
        }
    }

    /** **What is blocking**, which is what the links are read for. */
    public function test_blocked_by_lists_the_unfinished_predecessors(): void
    {
        $done = $this->activity(['code' => 'A1']);
        $running = $this->activity(['code' => 'A2']);
        $cladding = $this->activity(['code' => 'A3']);

        $this->programme->addPredecessor($cladding, $done);
        $this->programme->addPredecessor($cladding, $running);

        $this->programme->progress($done, [
            'actual_start' => '2026-08-01', 'actual_finish' => '2026-08-20',
            'percent_complete' => 100, 'data_date' => '2026-08-20',
        ]);

        $blocking = $this->programme->blockedBy($cladding);

        $this->assertSame(['A2'], $blocking->pluck('code')->all());
    }

    public function test_an_activity_with_no_predecessors_is_blocked_by_nothing(): void
    {
        $this->assertCount(0, $this->programme->blockedBy($this->activity()));
    }

    // ------------------------------------------------------------------ the look-ahead

    /** Read off the stored planned start — walking predecessors would be a forward pass in all but name. */
    public function test_the_look_ahead_reads_the_planned_start(): void
    {
        $this->activity(['code' => 'A1', 'planned_start' => '2026-08-25', 'planned_finish' => '2026-09-01']);
        $this->activity(['code' => 'A2', 'planned_start' => '2026-09-30', 'planned_finish' => '2026-10-10']);

        $started = $this->activity(['code' => 'A3', 'planned_start' => '2026-08-26', 'planned_finish' => '2026-09-02']);
        $this->programme->progress($started, [
            'actual_start' => '2026-08-26', 'percent_complete' => 10, 'data_date' => '2026-08-26',
        ]);

        $ahead = $this->programme->lookAhead($this->job, '2026-08-20', 21);

        $this->assertSame(['A1'], $ahead->pluck('code')->all(), 'started and far-future are both out');
    }

    // ------------------------------------------------------------------ the exposure

    /**
     * **The liquidated-damages exposure** — the figure a programme is worth storing for.
     *
     * Late against the accepted programme, less the extension of time actually awarded.
     */
    public function test_unexcused_lateness_is_lateness_less_the_extension_of_time_awarded(): void
    {
        $milestone = $this->activity([
            'code' => 'M1',
            'name' => 'Sectional completion — tower',
            'activity_type' => ProgrammeActivity::TYPE_FINISH_MILESTONE,
            'is_contract_milestone' => true,
            'ld_applies' => true,
            'baseline_start' => '2026-08-31',
            'baseline_finish' => '2026-08-31',
            'planned_start' => '2026-08-31',
            'planned_finish' => '2026-08-31',
        ]);

        $this->assertSame(30, $milestone->daysLateAgainstBaseline('2026-09-30'));
        $this->assertSame(30, $milestone->load('delayEvents')->unexcusedLateDays('2026-09-30'));

        // An extension of time, determined — not merely claimed.
        $events = app(DelayEventService::class);
        $event = $events->raise($this->job, [
            'title' => 'Access withheld',
            'cause_category' => 'access',
            'occurred_on' => '2026-08-01',
            'activity_id' => $milestone->getKey(),
            'claimed_days' => 25,
        ]);
        $events->giveNotice($event, '2026-08-05');
        $events->determine($event->refresh(), 18, null, 'Partly concurrent.');

        $milestone->refresh()->load('delayEvents');

        $this->assertSame(18, $milestone->awardedDays());
        $this->assertSame(12, $milestone->unexcusedLateDays('2026-09-30'), '30 late, 18 excused');

        $exposure = $this->programme->ldExposure($this->job, '2026-09-30');

        $this->assertCount(1, $exposure);
        $this->assertSame(30, $exposure[0]['late_days']);
        $this->assertSame(18, $exposure[0]['awarded_days']);
        $this->assertSame(12, $exposure[0]['unexcused_days']);
    }

    /** **A claimed extension of time is not an awarded one**, and only the determination counts. */
    public function test_a_claimed_but_undetermined_extension_excuses_nothing(): void
    {
        $milestone = $this->pricedMilestone();

        $event = app(DelayEventService::class)->raise($this->job, [
            'title' => 'Late drawings',
            'cause_category' => 'late_information',
            'occurred_on' => '2026-08-01',
            'activity_id' => $milestone->getKey(),
            'claimed_days' => 40,
        ]);

        $this->assertSame(DelayEvent::STATUS_OPEN, $event->status);

        $milestone->refresh()->load('delayEvents');

        $this->assertSame(0, $milestone->awardedDays(), 'claimed is not awarded');
        $this->assertSame(30, $milestone->unexcusedLateDays('2026-09-30'));
    }

    /**
     * **`ld_applies` is separate from `is_contract_milestone`**, because a contract names dates it does not price.
     */
    public function test_a_contract_milestone_without_damages_carries_no_exposure(): void
    {
        $unpriced = $this->activity([
            'code' => 'M2',
            'is_contract_milestone' => true,
            'ld_applies' => false,
            'baseline_start' => '2026-08-31',
            'baseline_finish' => '2026-08-31',
        ]);

        $this->assertSame(30, $unpriced->daysLateAgainstBaseline('2026-09-30'));
        $this->assertSame(0, $unpriced->load('delayEvents')->unexcusedLateDays('2026-09-30'));
        $this->assertCount(0, $this->programme->ldExposure($this->job, '2026-09-30'));
    }

    /** A milestone met on time is off the exposure list entirely. */
    public function test_a_milestone_met_on_time_has_no_exposure(): void
    {
        $milestone = $this->pricedMilestone();

        $this->programme->progress($milestone, [
            'actual_start' => '2026-08-30', 'actual_finish' => '2026-08-30',
            'percent_complete' => 100, 'data_date' => '2026-08-30',
        ]);

        $this->assertCount(0, $this->programme->ldExposure($this->job, '2026-09-30'));
    }

    public function test_the_milestone_register_is_ordered_by_the_accepted_date(): void
    {
        $this->activity(['code' => 'M2', 'is_contract_milestone' => true, 'baseline_finish' => '2027-01-31']);
        $this->activity(['code' => 'M1', 'is_contract_milestone' => true, 'baseline_finish' => '2026-08-31']);
        $this->activity(['code' => 'A1']);

        $this->assertSame(['M1', 'M2'], $this->programme->milestones($this->job)->pluck('code')->all());
    }

    // ------------------------------------------------------------------ what the registers point at

    /**
     * **The activity identifier §13 says an RFI, a submittal and a delay event must be able to point at.**
     *
     * Left out of 9d and 9e deliberately, and wired here in one migration with real foreign keys.
     */
    public function test_the_registers_can_point_at_an_activity(): void
    {
        $activity = $this->activity();

        $rfi = app(RfiService::class)->raise($this->job, [
            'subject' => 'Lintel size', 'question' => 'Which governs?', 'ball_in_court' => 'architect',
            'activity_id' => $activity->getKey(),
        ]);

        $submittal = app(SubmittalService::class)->register($this->job, [
            'spec_section' => '05 12 00', 'title' => 'Steel shop drawings', 'type' => 'shop_drawing',
            'activity_id' => $activity->getKey(),
        ]);

        $event = app(DelayEventService::class)->raise($this->job, [
            'title' => 'Access withheld', 'cause_category' => 'access', 'occurred_on' => '2026-08-10',
            'activity_id' => $activity->getKey(),
        ]);

        $this->assertSame($activity->getKey(), $rfi->refresh()->activity_id);
        $this->assertSame($activity->getKey(), $submittal->refresh()->activity_id);
        $this->assertSame($activity->getKey(), $event->refresh()->activity_id);

        $this->assertSame('A1000', $rfi->activity->code);
        $this->assertSame('A1000', $submittal->activity->code);
        $this->assertSame('A1000', $event->activity->code);

        $activity->refresh();
        $this->assertCount(1, $activity->rfis);
        $this->assertCount(1, $activity->submittals);
        $this->assertCount(1, $activity->delayEvents);
    }

    /**
     * **Punch items deliberately have no activity link.**
     *
     * A correction to a note left at 9c: §16.4 does not ask for one, and the reason holds — a snag is located in
     * *space*, which is what its location and grid reference are for, and the activity that built the thing is finished
     * by definition.
     */
    public function test_punch_items_carry_no_activity_column(): void
    {
        $this->assertFalse(
            \Illuminate\Support\Facades\Schema::hasColumn('construction_punch_items', 'activity_id'),
            'a snag is located in space, not in the programme'
        );

        foreach (['construction_rfis', 'construction_submittals', 'construction_delay_events'] as $table) {
            $this->assertTrue(
                \Illuminate\Support\Facades\Schema::hasColumn($table, 'activity_id'),
                "{$table} needs to be able to point at an activity"
            );
        }
    }

    // ------------------------------------------------------------------ the screens

    public function test_the_register_shows_the_exposure_and_the_source(): void
    {
        $milestone = $this->pricedMilestone();
        $this->activity(['code' => 'A9', 'source' => 'p6_xer', 'external_id' => 'A9', 'is_critical' => true]);

        Livewire::test(ListActivities::class)
            ->assertCanSeeTableRecords([$milestone])
            ->assertSee('M1')
            ->assertSee('Primavera XER');
    }

    public function test_the_screen_records_progress(): void
    {
        $activity = $this->activity();

        Livewire::test(ListActivities::class)
            ->callAction(TestAction::make('progress')->table($activity), [
                'actual_start' => '2026-08-05',
                'percent_complete' => 45,
                'data_date' => '2026-08-20',
            ]);

        $activity->refresh();

        $this->assertSame(45.0, (float) $activity->percent_complete);
        $this->assertSame('2026-08-20', $activity->data_date->toDateString());
    }

    public function test_the_screen_surfaces_a_progress_refusal(): void
    {
        $activity = $this->activity();

        Livewire::test(ListActivities::class)
            ->callAction(TestAction::make('progress')->table($activity), [
                'percent_complete' => 100,
                'data_date' => '2026-08-20',
            ]);

        $this->assertSame(0.0, (float) $activity->refresh()->percent_complete, 'the refusal held');
    }

    public function test_the_predecessors_tab_records_a_link_and_shows_what_is_blocking(): void
    {
        $first = $this->activity(['code' => 'A1']);
        $cladding = $this->activity(['code' => 'A3']);

        Livewire::test(PredecessorsRelationManager::class, [
            'ownerRecord' => $cladding,
            'pageClass' => EditActivity::class,
        ])
            ->callAction(TestAction::make('create')->table(), [
                'predecessor_activity_id' => $first->getKey(),
                'relationship' => ProgrammeActivityPredecessor::FINISH_TO_START,
                'lag_days' => 0,
            ]);

        $link = ProgrammeActivityPredecessor::query()->firstOrFail();

        $this->assertSame($first->getKey(), $link->predecessor_activity_id);

        Livewire::test(PredecessorsRelationManager::class, [
            'ownerRecord' => $cladding->refresh(),
            'pageClass' => EditActivity::class,
        ])
            ->assertCanSeeTableRecords([$link])
            ->assertSee('not started');
    }

    /** An imported activity cannot be deleted — the next import would silently put it back. */
    public function test_an_imported_activity_is_not_deletable(): void
    {
        $imported = $this->activity(['source' => 'p6_xml', 'external_id' => 'X1']);
        $manual = $this->activity(['code' => 'A2']);

        // The policy directly: an Administrator passes through `Gate::before`, which is a different question from
        // whether the rule is written.
        $policy = app(\App\Modules\ConstructionField\Policies\ProgrammeActivityPolicy::class);
        $user = auth()->user();

        $this->assertFalse($policy->delete($user, $imported));
        $this->assertTrue($policy->delete($user, $manual));
    }

    /** The programme works with only the spine and this module — §18. */
    public function test_the_programme_works_with_only_the_spine_and_this_module(): void
    {
        CompanyModule::query()
            ->where('company_id', $this->tenant->getKey())
            ->whereIn('module', ['construction_costing', 'construction_contracts', 'invoicing', 'accounting'])
            ->update(['licensed' => false, 'enabled' => false]);
        modules()->flush();

        $milestone = $this->pricedMilestone();

        $this->assertSame(30, $milestone->load('delayEvents')->unexcusedLateDays('2026-09-30'));
        $this->assertCount(1, $this->programme->ldExposure($this->job, '2026-09-30'));
    }

    private function pricedMilestone(): ProgrammeActivity
    {
        return $this->activity([
            'code' => 'M1',
            'name' => 'Sectional completion — tower',
            'activity_type' => ProgrammeActivity::TYPE_FINISH_MILESTONE,
            'is_contract_milestone' => true,
            'ld_applies' => true,
            'baseline_start' => '2026-08-31',
            'baseline_finish' => '2026-08-31',
            'planned_start' => '2026-08-31',
            'planned_finish' => '2026-08-31',
        ]);
    }
}
