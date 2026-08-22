<?php

namespace Tests\Feature;

use App\Modules\Construction\Models\Job;
use App\Modules\ConstructionQhse\Filament\Resources\Incidents\Pages\ListIncidents;
use App\Modules\ConstructionQhse\Models\Incident;
use App\Modules\ConstructionQhse\Services\IncidentService;
use App\Modules\Core\Models\CompanyModule;
use Filament\Actions\Testing\TestAction;
use InvalidArgumentException;
use Livewire\Livewire;
use Tests\AccountingTestCase;
use Tests\Concerns\InteractsWithTenant;

/**
 * Incidents, to ISO 45001 — §17.3, Phase 10d.
 *
 * **Near miss is a kind, not a checkbox**, and the reason is arithmetic rather than taxonomy: §17.3 says "near-misses
 * reported per lost-time injury is the leading indicator that predicts the next one", and a near miss has no injury
 * record to hang a flag on — nobody was hurt. Stored as a checkbox it cannot be counted, and the ratio cannot exist.
 *
 * Four more properties:
 *
 *  - **`occurred_at` is a datetime**, because shift timing is half the analysis. Hour ten of a twelve-hour shift is a
 *    finding; the 14th of August is not.
 *  - **The reporting delay is computed and is itself a metric.** Kept from `reported_at` rather than `created_at`,
 *    because an incident typed up a week later from a paper form was *reported* when it was reported.
 *  - **The injured person's name works on its own.** A subcontractor's labourer is in no table here.
 *  - **A reportable incident nobody has reported is the exposure**, and it is the only clock in this module that is
 *    statutory rather than contractual.
 */
class ConstructionIncidentTest extends AccountingTestCase
{
    use InteractsWithTenant;

    private Job $job;

    private IncidentService $incidents;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs($this->makeUser('Administrator', 'incident@test.local'));
        $this->setCurrentTenant();

        foreach (['construction', 'construction_qhse'] as $module) {
            CompanyModule::updateOrCreate(
                ['company_id' => $this->tenant->getKey(), 'module' => $module],
                ['licensed' => true, 'enabled' => true],
            );
        }
        modules()->flush();

        $this->job = Job::create(['code' => 'J-1', 'name' => 'Tower']);
        $this->incidents = app(IncidentService::class);
    }

    /** @param array<string, mixed> $attributes */
    private function incident(array $attributes = []): Incident
    {
        return $this->incidents->report($this->job, array_merge([
            'kind' => Incident::KIND_NEAR_MISS,
            'description' => 'Scaffold board slipped as a labourer stepped on it.',
            'occurred_at' => '2026-08-20 17:30',
            'reported_at' => '2026-08-20 18:00',
        ], $attributes));
    }

    // ------------------------------------------------------------------ near miss is a kind

    /**
     * **The point of the register**: a near miss is a row in the same table as a lost-time injury, so the ratio exists.
     */
    public function test_a_near_miss_is_a_first_class_row(): void
    {
        $nearMiss = $this->incident();

        $this->assertSame('INC-1', $nearMiss->incident_number);
        $this->assertTrue($nearMiss->isNearMiss());
        $this->assertTrue($nearMiss->hurtNobody());
        $this->assertFalse($nearMiss->isRecordable());
        $this->assertFalse($nearMiss->isLostTime());
        $this->assertSame('Nobody hurt', $nearMiss->personName());
    }

    /**
     * **The ratio §17.3 calls the leading indicator that predicts the next injury.**
     *
     * And null with no lost-time injury to divide by, because that is the *good* state and must not read as a bad ratio.
     */
    public function test_the_near_miss_ratio_is_null_with_no_lost_time_injury(): void
    {
        foreach (range(1, 12) as $i) {
            $this->incident(['description' => "Near miss {$i}"]);
        }

        $ratio = $this->incidents->nearMissRatio($this->job, '2026-08-01', '2026-08-31');

        $this->assertSame(12, $ratio['near_misses']);
        $this->assertSame(0, $ratio['lost_time']);
        $this->assertNull($ratio['ratio'], 'no injury to divide by is the good state, not a bad ratio');

        $this->incident([
            'kind' => Incident::KIND_LOST_TIME,
            'description' => 'Labourer fell from the third lift.',
            'injured_person_type' => 'subcontractor',
            'injured_person_name' => 'A. Labourer',
        ]);

        $ratio = $this->incidents->nearMissRatio($this->job, '2026-08-01', '2026-08-31');

        $this->assertSame(1, $ratio['lost_time']);
        $this->assertSame(12.0, $ratio['ratio']);
    }

    /** Unsafe acts and conditions count with near misses: all three hurt nobody. */
    public function test_unsafe_acts_and_conditions_count_as_nobody_hurt(): void
    {
        $this->incident(['kind' => 'unsafe_act', 'description' => 'Working at height without a harness.']);
        $this->incident(['kind' => 'unsafe_condition', 'description' => 'Missing handrail on the east stair.']);

        $ratio = $this->incidents->nearMissRatio($this->job, '2026-08-01', '2026-08-31');

        $this->assertSame(2, $ratio['near_misses']);
    }

    /** First aid is deliberately *not* recordable — including it is how a rate becomes incomparable. */
    public function test_first_aid_is_not_recordable_but_medical_treatment_is(): void
    {
        $firstAid = $this->incident([
            'kind' => Incident::KIND_FIRST_AID,
            'injured_person_type' => 'employee',
            'injured_person_name' => 'A. Fitter',
        ]);
        $medical = $this->incident([
            'kind' => Incident::KIND_MEDICAL_TREATMENT,
            'description' => 'Cut requiring stitches.',
            'injured_person_type' => 'employee',
            'injured_person_name' => 'A. Fitter',
        ]);

        $this->assertFalse($firstAid->isRecordable());
        $this->assertTrue($medical->isRecordable());
    }

    // ------------------------------------------------------------------ the time and the delay

    /** **A datetime, because shift timing is half the analysis.** */
    public function test_the_hour_of_the_day_survives(): void
    {
        $incident = $this->incident(['occurred_at' => '2026-08-20 17:45']);

        $this->assertSame(17, $incident->hourOfDay());
        $this->assertSame('2026-08-20 17:45', $incident->occurred_at->format('Y-m-d H:i'));
    }

    public function test_an_incident_needs_a_time_and_cannot_be_in_the_future(): void
    {
        foreach ([
            [['occurred_at' => null], 'not just the date'],
            [['occurred_at' => now()->addDay()->toDateTimeString()], 'in the future'],
            [['occurred_at' => '2026-08-20 18:00', 'reported_at' => '2026-08-20 17:00'], 'before it happened'],
            [['kind' => 'stubbed_toe'], 'needs a kind'],
            [['description' => ' '], 'needs describing'],
        ] as [$override, $expected]) {
            try {
                $this->incident($override);
                $this->fail("Expected a refusal mentioning: {$expected}");
            } catch (InvalidArgumentException $e) {
                $this->assertStringContainsString($expected, $e->getMessage());
            }
        }
    }

    /**
     * **The reporting delay is a safety metric, so it is computed and reported.**
     *
     * §17.3: "a site that takes four days to report a first-aid case is a site where the next one is not reported at
     * all."
     */
    public function test_the_reporting_delay_is_computed_and_late_reports_are_named(): void
    {
        $prompt = $this->incident(['reported_at' => '2026-08-20 18:00']);
        $late = $this->incident([
            'description' => 'Board slipped again.',
            'occurred_at' => '2026-08-20 08:00',
            'reported_at' => '2026-08-24 09:00',
        ]);

        $this->assertSame(0, $prompt->reportingDelayHours(), 'half an hour rounds to none');
        $this->assertFalse($prompt->wasReportedLate());

        $this->assertSame(97, $late->reportingDelayHours());
        $this->assertTrue($late->wasReportedLate());

        $this->assertCount(1, $this->incidents->reportedLate($this->job));
        $this->assertSame(48.5, $this->incidents->averageReportingDelayHours($this->job));
    }

    /** The threshold is policy, so it is configuration. */
    public function test_what_counts_as_late_is_configuration(): void
    {
        $incident = $this->incident([
            'occurred_at' => '2026-08-20 08:00',
            'reported_at' => '2026-08-20 20:00',
        ]);

        $this->assertFalse($incident->wasReportedLate(), 'twelve hours is inside the default day');

        config()->set('construction.qhse.report_within_hours', 2);

        $this->assertTrue($incident->wasReportedLate());
    }

    /** Null rather than zero where nothing has a report stamp — the flattering wrong answer refused again. */
    public function test_the_average_delay_is_null_with_nothing_to_average(): void
    {
        $this->assertNull($this->incidents->averageReportingDelayHours($this->job));
    }

    // ------------------------------------------------------------------ who it happened to

    /** **The name works on its own.** Most people on most sites are somebody else's employees. */
    public function test_the_injured_persons_name_works_without_an_employee_record(): void
    {
        $incident = $this->incident([
            'kind' => Incident::KIND_LOST_TIME,
            'description' => 'Fell from the third lift.',
            'injured_person_type' => 'subcontractor',
            'injured_person_name' => 'A. Labourer',
            'injured_person_employer' => 'Scaffolding Co',
        ]);

        $this->assertSame('A. Labourer (Scaffolding Co)', $incident->personName());
        $this->assertNull($incident->employee_id);
    }

    /**
     * **Lost time is never inferred from a day count.**
     *
     * A lost-time injury where nobody yet knows how long somebody is off is the ordinary state for a fortnight, and
     * deriving the flag would classify it as a medical-treatment case for exactly as long as the reportable clock runs.
     */
    public function test_lost_time_is_set_by_the_kind_and_not_by_the_day_count(): void
    {
        $noDaysYet = $this->incident([
            'kind' => Incident::KIND_LOST_TIME,
            'injured_person_type' => 'employee',
            'injured_person_name' => 'A. Fitter',
        ]);

        $this->assertTrue($noDaysYet->is_lost_time);
        $this->assertTrue($noDaysYet->isLostTime());
        $this->assertNull($noDaysYet->days_lost, 'nobody knows yet, and that does not change what it is');

        // And a restricted-work case with days against it is not lost time.
        $restricted = $this->incident([
            'kind' => Incident::KIND_RESTRICTED_WORK,
            'description' => 'Light duties for a fortnight.',
            'injured_person_type' => 'employee',
            'injured_person_name' => 'A. Fitter',
            'restricted_days' => 14,
        ]);

        $this->assertFalse($restricted->is_lost_time);
        $this->assertTrue($restricted->isRecordable());
    }

    /** Changing the kind to a lost-time one sets the flag; the reverse is never inferred. */
    public function test_reclassifying_to_lost_time_sets_the_flag(): void
    {
        $incident = $this->incident([
            'kind' => Incident::KIND_MEDICAL_TREATMENT,
            'injured_person_type' => 'employee',
            'injured_person_name' => 'A. Fitter',
        ]);

        $this->assertFalse($incident->is_lost_time);

        $incident = $this->incidents->update($incident, ['kind' => Incident::KIND_LOST_TIME, 'days_lost' => 9]);

        $this->assertTrue($incident->is_lost_time);
        $this->assertSame(9, $incident->days_lost);
    }

    // ------------------------------------------------------------------ witnesses

    /** A name is enough, and the date the statement was taken is what gives it weight. */
    public function test_a_witness_needs_only_a_name_and_the_statement_is_dated(): void
    {
        $incident = $this->incident();

        try {
            $this->incidents->addWitness($incident, ['employer' => 'Scaffolding Co']);
            $this->fail('A nameless witness should be refused.');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('needs a name', $e->getMessage());
        }

        $sameDay = $this->incidents->addWitness($incident, [
            'name' => 'B. Watcher',
            'employer' => 'Scaffolding Co',
            'statement' => 'The board was not tied.',
            'statement_taken_on' => '2026-08-20',
        ]);
        $later = $this->incidents->addWitness($incident, [
            'name' => 'C. Passerby',
            'statement' => 'I heard it go.',
            'statement_taken_on' => '2026-09-15',
        ]);
        $noStatement = $this->incidents->addWitness($incident, ['name' => 'D. Foreman']);

        $this->assertSame(0, $sameDay->daysAfterIncident(), 'taken on the day');
        $this->assertSame(26, $later->daysAfterIncident());
        $this->assertFalse($noStatement->hasStatement());
        $this->assertNull($noStatement->statement_taken_on);
        $this->assertSame('B. Watcher (Scaffolding Co)', $sameDay->displayName());
    }

    /** A statement typed with no date is dated on entry — an undated statement is what the column exists to prevent. */
    public function test_a_statement_with_no_date_is_dated_on_entry(): void
    {
        $witness = $this->incidents->addWitness($this->incident(), [
            'name' => 'B. Watcher',
            'statement' => 'It was not tied.',
        ]);

        $this->assertNotNull($witness->statement_taken_on);
        $this->assertNotNull($witness->taken_by);
    }

    public function test_a_photograph_needs_a_file_and_a_caption(): void
    {
        $incident = $this->incident();

        foreach ([
            [['caption' => 'The board', 'file_path' => null], 'needs a file'],
            [['caption' => ' ', 'file_path' => 'a/b.jpg'], 'needs a caption'],
        ] as [$attributes, $expected]) {
            try {
                $this->incidents->addPhoto($incident, $attributes);
                $this->fail("Expected a refusal mentioning: {$expected}");
            } catch (InvalidArgumentException $e) {
                $this->assertStringContainsString($expected, $e->getMessage());
            }
        }

        $photo = $this->incidents->addPhoto($incident, [
            'caption' => 'The board, in place', 'file_path' => 'construction/incidents/board.jpg',
        ]);

        // Dated from the incident, not from the upload.
        $this->assertSame('2026-08-20 17:30', $photo->taken_at->format('Y-m-d H:i'));
    }

    // ------------------------------------------------------------------ the authority, and closing

    /**
     * **Reportable and unreported** — the one statutory clock in this module.
     */
    public function test_a_reportable_incident_nobody_has_reported_is_the_exposure(): void
    {
        $reportable = $this->incident([
            'kind' => Incident::KIND_LOST_TIME,
            'description' => 'Fell from the third lift.',
            'injured_person_type' => 'subcontractor',
            'injured_person_name' => 'A. Labourer',
            'reportable_to_authority' => true,
        ]);
        // Not reportable: no exposure.
        $this->incident(['description' => 'Board slipped.']);

        $this->assertTrue($reportable->reportableAndUnreported());
        $this->assertCount(1, $this->incidents->reportableAndUnreported($this->job));

        $reported = $this->incidents->reportToAuthority($reportable, 'HSE', 'F2508-88123', '2026-08-21');

        $this->assertSame('HSE', $reported->authority_name);
        $this->assertSame('2026-08-21', $reported->reported_to_authority_on->toDateString());
        $this->assertFalse($reported->reportableAndUnreported());
        $this->assertCount(0, $this->incidents->reportableAndUnreported($this->job));
    }

    public function test_an_authority_report_against_a_non_reportable_incident_is_refused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('not marked as reportable');

        $this->incidents->reportToAuthority($this->incident(), 'HSE');
    }

    /** **Closing needs a cause** — an incident closed without one is a lesson nobody learned. */
    public function test_closing_needs_a_cause(): void
    {
        $incident = $this->incident();

        try {
            $this->incidents->close($incident);
            $this->fail('An incident with no cause should not close.');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('a lesson nobody learned', $e->getMessage());
        }

        $incident = $this->incidents->investigate($incident, [
            'immediate_cause' => 'The board was not tied.',
            'root_cause' => 'No scaffold inspection since the lift was raised.',
            'root_cause_method' => 'Five whys',
        ]);

        $this->assertSame(Incident::STATUS_UNDER_INVESTIGATION, $incident->status);
        $this->assertNotNull($incident->investigated_by);

        $closed = $this->incidents->close($incident, '2026-08-25');

        $this->assertTrue($closed->isClosed());
        $this->assertSame('2026-08-25', $closed->closed_on->toDateString());
    }

    /** **And closing is refused while a statutory duty is outstanding.** */
    public function test_closing_is_refused_while_a_reportable_incident_is_unreported(): void
    {
        $incident = $this->incidents->investigate(
            $this->incident(['reportable_to_authority' => true]),
            ['root_cause' => 'No inspection.'],
        );

        try {
            $this->incidents->close($incident);
            $this->fail('Closing over an unreported statutory duty should be refused.');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('file a statutory duty as finished', $e->getMessage());
        }

        $this->incidents->reportToAuthority($incident, 'HSE', null, '2026-08-21');

        $this->assertTrue($this->incidents->close($incident->refresh())->isClosed());
    }

    /** Reopening exists, with a reason kept on the record — new facts do come out. */
    public function test_reopening_keeps_the_reason(): void
    {
        $incident = $this->incidents->close(
            $this->incidents->investigate($this->incident(), ['root_cause' => 'No inspection.']),
        );

        try {
            $this->incidents->reopen($incident, '  ');
            $this->fail('A reopen with no reason should be refused.');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('needs a reason', $e->getMessage());
        }

        $reopened = $this->incidents->reopen($incident, 'The labourer is now signed off for three weeks.');

        $this->assertSame(Incident::STATUS_UNDER_INVESTIGATION, $reopened->status);
        $this->assertNull($reopened->closed_on);
        $this->assertStringContainsString('signed off for three weeks', $reopened->notes);
    }

    public function test_a_closed_incident_takes_no_edits(): void
    {
        $incident = $this->incidents->close(
            $this->incidents->investigate($this->incident(), ['root_cause' => 'No inspection.']),
        );

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('reopen it');

        $this->incidents->update($incident, ['description' => 'Something else']);
    }

    // ------------------------------------------------------------------ counts

    /** Counted by when they *happened*, so a rate for August contains what happened in August. */
    public function test_counts_are_by_occurrence_not_by_when_they_were_typed(): void
    {
        $this->incident(['occurred_at' => '2026-07-30 09:00', 'reported_at' => '2026-08-02 09:00']);
        $this->incident(['occurred_at' => '2026-08-15 09:00', 'reported_at' => '2026-08-15 10:00']);

        $august = $this->incidents->countsByKind($this->job, '2026-08-01', '2026-08-31');

        $this->assertSame([Incident::KIND_NEAR_MISS => 1], $august, 'the July one is not August, however it was typed');
    }

    // ------------------------------------------------------------------ the screens

    public function test_the_register_shows_the_delay_and_the_statutory_exposure(): void
    {
        $incident = $this->incident([
            'kind' => Incident::KIND_LOST_TIME,
            'description' => 'Fell from the third lift.',
            'occurred_at' => '2026-08-20 08:00',
            'reported_at' => '2026-08-24 09:00',
            'injured_person_type' => 'subcontractor',
            'injured_person_name' => 'A. Labourer',
            'reportable_to_authority' => true,
        ]);

        Livewire::test(ListIncidents::class)
            ->assertCanSeeTableRecords([$incident])
            ->assertSee('INC-1')
            ->assertSee('Lost-time injury')
            ->assertSee('97 h later')
            ->assertSee('not reported');
    }

    public function test_the_screen_investigates_then_closes(): void
    {
        $incident = $this->incident();

        Livewire::test(ListIncidents::class)
            ->callAction(TestAction::make('investigate')->table($incident), [
                'immediate_cause' => 'The board was not tied.',
                'root_cause' => 'No scaffold inspection since the lift was raised.',
                'root_cause_method' => 'Five whys',
                'investigation_completed_on' => '2026-08-22',
            ]);

        $this->assertSame(Incident::STATUS_UNDER_INVESTIGATION, $incident->refresh()->status);

        Livewire::test(ListIncidents::class)->callAction(TestAction::make('close')->table($incident));

        $this->assertTrue($incident->refresh()->isClosed());
    }

    public function test_the_close_action_surfaces_the_missing_cause_refusal(): void
    {
        $incident = $this->incident();

        Livewire::test(ListIncidents::class)->callAction(TestAction::make('close')->table($incident));

        $this->assertFalse($incident->refresh()->isClosed(), 'the refusal held');
    }

    /** The register works with only the spine and this module — §18. */
    public function test_the_register_works_with_only_the_spine_and_this_module(): void
    {
        CompanyModule::query()
            ->where('company_id', $this->tenant->getKey())
            ->whereIn('module', ['employees', 'invoicing', 'accounting', 'construction_contracts'])
            ->update(['licensed' => false, 'enabled' => false]);
        modules()->flush();

        $incident = $this->incident([
            'kind' => Incident::KIND_LOST_TIME,
            'injured_person_type' => 'subcontractor',
            'injured_person_name' => 'A. Labourer',
            'injured_person_employer' => 'Scaffolding Co',
        ]);

        // The free-text name is the whole of it, which is §17.3's requirement rather than a fallback.
        $this->assertSame('A. Labourer (Scaffolding Co)', $incident->personName());
        $this->assertSame(1, $this->incidents->nearMissRatio($this->job, '2026-08-01', '2026-08-31')['lost_time']);
    }
}
