<?php

namespace Tests\Feature;

use App\Modules\Construction\Models\Job;
use App\Modules\ConstructionField\Models\ProgrammeActivity;
use App\Modules\ConstructionField\Models\ProgrammeActivityPredecessor;
use App\Modules\ConstructionField\Services\ProgrammeImport;
use App\Modules\Core\Models\CompanyModule;
use InvalidArgumentException;
use Tests\AccountingTestCase;
use Tests\Concerns\InteractsWithTenant;

/**
 * Importing a programme — §13, Phase 9h.
 *
 * §13 asks for three formats into one set of tables, keyed on an `external_id`, and nothing more. What the tests below
 * are mostly about is the decision that carries the money:
 *
 * **An update import must not overwrite the baseline.** A contractor sends a P6 file every month. If each one rewrote
 * the accepted dates, every re-programme would silently retire the entitlement it was caused by — which is the exact
 * failure §13 keeps two pairs of dates apart to prevent. So `MODE_UPDATE` writes planned dates, actuals, progress, float
 * and criticality and leaves the baseline alone; `MODE_BASELINE` writes it deliberately, and the summary says in words
 * which of the two happened.
 *
 * The rest is units and honesty. **P6 counts float and lag in hours, MS Project counts slack in tenths of a minute**, and
 * both become days — a conversion that is silently a factor of 4,800 out if the second is mistaken for the first. And a
 * row this importer cannot use is **skipped and counted**, because the failure mode of an importer is not throwing: it
 * is importing four hundred activities out of five hundred and reporting success.
 */
class ConstructionProgrammeImportTest extends AccountingTestCase
{
    use InteractsWithTenant;

    private Job $job;

    private ProgrammeImport $import;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs($this->makeUser('Administrator', 'import@test.local'));
        $this->setCurrentTenant();

        foreach (['construction', 'construction_field'] as $module) {
            CompanyModule::updateOrCreate(
                ['company_id' => $this->tenant->getKey(), 'module' => $module],
                ['licensed' => true, 'enabled' => true],
            );
        }
        modules()->flush();

        $this->job = Job::create(['code' => 'J-1', 'name' => 'Tower']);
        $this->import = app(ProgrammeImport::class);
    }

    /**
     * A minimal but real XER: header, a TASK table read by column name, and a TASKPRED.
     *
     * Deliberately with the columns in an *unusual order* and one extra column P6 writes that this importer does not
     * read — because reading by position rather than by name is how an importer puts a date in a float column, and the
     * column set genuinely differs between P6 versions.
     */
    private function xer(string $actualFinish = '', string $percent = '0'): string
    {
        return implode("\n", [
            "ERMHDR\t19.12\t2026-08-01\tProject\tadmin\tdbxDatabaseNoName\tProject Management\tUSD",
            '%T	PROJWBS',
            '%F	wbs_id	wbs_short_name',
            '%R	500	TOWER',
            '%T	TASK',
            // task_name before task_code, and a trailing column nothing here reads.
            '%F	task_id	task_name	task_code	task_type	target_start_date	target_end_date	act_start_date	act_end_date	total_float_hr_cnt	target_drtn_hr_cnt	phys_complete_pct	driving_path_flag	rsrc_id',
            "%R	1001	Level 4 slab	A1000	TT_Task	2026-08-01 08:00	2026-08-31 17:00		{$actualFinish}	-16	240	{$percent}	Y	77",
            '%R	1002	Sectional completion	M1000	TT_FinMile	2026-09-30 17:00	2026-09-30 17:00			80	0	0	N	',
            '%T	TASKPRED',
            '%F	task_pred_id	task_id	pred_task_id	pred_type	lag_hr_cnt',
            '%R	1	1002	1001	PR_FS	40',
            '%E',
        ]);
    }

    private function p6Xml(): string
    {
        return <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<APIBusinessObjects>
  <Project>
    <Activity>
      <ObjectId>2001</ObjectId>
      <Id>B1000</Id>
      <Name>Cladding to east elevation</Name>
      <Type>Task Dependent</Type>
      <PlannedStartDate>2026-10-01T08:00:00</PlannedStartDate>
      <PlannedFinishDate>2026-11-15T17:00:00</PlannedFinishDate>
      <PercentComplete>0.25</PercentComplete>
      <TotalFloat>24</TotalFloat>
      <PlannedDuration>240</PlannedDuration>
      <LongestPath>true</LongestPath>
    </Activity>
    <Activity>
      <ObjectId>2002</ObjectId>
      <Id>B2000</Id>
      <Name>Practical completion</Name>
      <Type>Finish Milestone</Type>
      <PlannedStartDate>2026-12-01T17:00:00</PlannedStartDate>
      <PlannedFinishDate>2026-12-01T17:00:00</PlannedFinishDate>
      <PercentComplete>0</PercentComplete>
      <TotalFloat>0</TotalFloat>
      <LongestPath>true</LongestPath>
    </Activity>
    <Relationship>
      <SuccessorActivityObjectId>2002</SuccessorActivityObjectId>
      <PredecessorActivityObjectId>2001</PredecessorActivityObjectId>
      <Type>Finish to Start</Type>
      <Lag>0</Lag>
    </Relationship>
  </Project>
</APIBusinessObjects>
XML;
    }

    private function mspXml(): string
    {
        return <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<Project xmlns="http://schemas.microsoft.com/project">
  <Tasks>
    <Task>
      <UID>0</UID>
      <ID>0</ID>
      <Name>Tower — summary</Name>
    </Task>
    <Task>
      <UID>1</UID>
      <ID>1</ID>
      <Name>Piling</Name>
      <Start>2026-08-03T08:00:00</Start>
      <Finish>2026-08-28T17:00:00</Finish>
      <PercentComplete>60</PercentComplete>
      <Duration>PT160H0M0S</Duration>
      <TotalSlack>4800</TotalSlack>
      <Critical>0</Critical>
      <Milestone>0</Milestone>
    </Task>
    <Task>
      <UID>2</UID>
      <ID>2</ID>
      <Name>Pile caps complete</Name>
      <Start>2026-09-04T17:00:00</Start>
      <Finish>2026-09-04T17:00:00</Finish>
      <PercentComplete>0</PercentComplete>
      <TotalSlack>0</TotalSlack>
      <Critical>1</Critical>
      <Milestone>1</Milestone>
      <PredecessorLink>
        <PredecessorUID>1</PredecessorUID>
        <Type>1</Type>
        <LinkLag>2400</LinkLag>
      </PredecessorLink>
    </Task>
  </Tasks>
</Project>
XML;
    }

    // ------------------------------------------------------------------ XER

    /** Read by **column name**, so an unusual column order and an unread extra column both survive. */
    public function test_an_xer_is_read_by_column_name(): void
    {
        $summary = $this->import->import($this->job, $this->xer());

        $this->assertSame('p6_xer', $summary->source);
        $this->assertSame(2, $summary->created);
        $this->assertSame(1, $summary->links);

        $slab = ProgrammeActivity::query()->where('code', 'A1000')->firstOrFail();

        $this->assertSame('Level 4 slab', $slab->name);
        $this->assertSame('1001', $slab->external_id);
        $this->assertSame(ProgrammeActivity::TYPE_TASK, $slab->activity_type);
        $this->assertSame('2026-08-01', $slab->planned_start->toDateString());
        $this->assertSame('2026-08-31', $slab->planned_finish->toDateString());
        // 240 hours at eight to the day.
        $this->assertSame(30, $slab->original_duration_days);

        $milestone = ProgrammeActivity::query()->where('code', 'M1000')->firstOrFail();

        $this->assertSame(ProgrammeActivity::TYPE_FINISH_MILESTONE, $milestone->activity_type);
    }

    /**
     * **Float arrives in hours and is stored in days, and a negative one survives.**
     *
     * Negative float is P6 saying the programme is already impossible, so clamping it to zero would delete the most
     * important number on the file.
     */
    public function test_float_is_converted_from_hours_and_may_be_negative(): void
    {
        $this->import->import($this->job, $this->xer());

        $slab = ProgrammeActivity::query()->where('code', 'A1000')->firstOrFail();
        $milestone = ProgrammeActivity::query()->where('code', 'M1000')->firstOrFail();

        $this->assertSame(-2, $slab->total_float_days, '-16 hours at eight to the day');
        $this->assertTrue($slab->is_critical, 'P6 marks the longest path');

        $this->assertSame(10, $milestone->total_float_days);
        $this->assertFalse($milestone->is_critical);
    }

    /** The working-day length is configuration, because a ten-hour shift changes every float figure. */
    public function test_the_working_day_length_is_configuration(): void
    {
        config()->set('construction.programme.hours_per_day', 10);

        $this->import->import($this->job, $this->xer());

        $this->assertSame(24, ProgrammeActivity::query()->where('code', 'A1000')->value('original_duration_days'));
    }

    /** Lag comes in hours too, and it is stored on the link rather than used to move anything. */
    public function test_a_relationship_carries_its_lag_in_days(): void
    {
        $this->import->import($this->job, $this->xer());

        $link = ProgrammeActivityPredecessor::query()->firstOrFail();

        $this->assertSame('fs', $link->relationship);
        $this->assertSame(5, $link->lag_days, '40 hours at eight to the day');
        $this->assertSame('FS +5 days', $link->describe());
    }

    // ------------------------------------------------------------------ P6 XML and MS Project

    public function test_a_p6_xml_export_is_read(): void
    {
        $summary = $this->import->import($this->job, $this->p6Xml());

        $this->assertSame('p6_xml', $summary->source);
        $this->assertSame(2, $summary->created);
        $this->assertSame(1, $summary->links);

        $cladding = ProgrammeActivity::query()->where('code', 'B1000')->firstOrFail();

        $this->assertSame('2026-10-01', $cladding->planned_start->toDateString());
        // A fraction, which is what some P6 exports write — 0.25 is a quarter done, not a quarter of a per cent.
        $this->assertSame(25.0, (float) $cladding->percent_complete);
        $this->assertSame(3, $cladding->total_float_days);
        $this->assertTrue($cladding->is_critical);

        $this->assertSame(
            ProgrammeActivity::TYPE_FINISH_MILESTONE,
            ProgrammeActivity::query()->where('code', 'B2000')->value('activity_type'),
        );
    }

    /**
     * **MS Project's slack is tenths of a minute** — a factor of 4,800 if mistaken for hours.
     *
     * 4,800 tenths of a minute is eight hours, which is one working day.
     */
    public function test_ms_project_slack_is_tenths_of_a_minute(): void
    {
        $summary = $this->import->import($this->job, $this->mspXml());

        $this->assertSame('msp_xml', $summary->source);
        // UID 0 is the project summary row, not work.
        $this->assertSame(2, $summary->created);

        $piling = ProgrammeActivity::query()->where('code', '1')->firstOrFail();

        $this->assertSame(1, $piling->total_float_days, '4,800 tenths of a minute is one eight-hour day');
        $this->assertSame(20, $piling->original_duration_days, 'PT160H is twenty days');
        $this->assertSame(60.0, (float) $piling->percent_complete);
        $this->assertFalse($piling->is_critical);

        $link = ProgrammeActivityPredecessor::query()->firstOrFail();

        $this->assertSame('fs', $link->relationship, 'MSPDI type 1 is finish-to-start');
        // 2,400 tenths of a minute is four hours — half a working day — and days are what this column stores, so it
        // rounds up. Sub-day lags are lossy by construction; rounding up keeps a lag from vanishing, which is the safer
        // direction for something a round-trip has to carry.
        $this->assertSame(1, $link->lag_days);
    }

    /** The format is detected from the content, because an extension is what a mail client called the file. */
    public function test_the_format_is_detected_from_the_contents(): void
    {
        $this->assertSame('p6_xer', $this->import->import($this->job, $this->xer())->source);
        $this->assertSame('p6_xml', $this->import->import($this->job, $this->p6Xml())->source);
        $this->assertSame('msp_xml', $this->import->import($this->job, $this->mspXml())->source);
    }

    // ------------------------------------------------------------------ the baseline decision

    /**
     * **The first import sets the baseline**, because a job with nothing to measure against has nothing to protect.
     */
    public function test_the_first_import_sets_the_baseline(): void
    {
        $summary = $this->import->import($this->job, $this->xer());

        $slab = ProgrammeActivity::query()->where('code', 'A1000')->firstOrFail();

        $this->assertSame('2026-08-31', $slab->baseline_finish->toDateString());
        $this->assertFalse($summary->baselineWritten, 'this was an update; the baseline was seeded because it was empty');
    }

    /**
     * **A monthly update never moves the baseline** — the whole reason §13 keeps two pairs of dates.
     *
     * The second file says the slab now finishes six weeks later. The plan moves; the accepted date does not; and the
     * lateness the register reports is against the accepted date.
     */
    public function test_an_update_import_leaves_the_baseline_alone(): void
    {
        $this->import->import($this->job, $this->xer());

        $slipped = str_replace('2026-08-31 17:00', '2026-10-12 17:00', $this->xer());
        $summary = $this->import->import($this->job, $slipped);

        $this->assertSame(2, $summary->updated);
        $this->assertSame(0, $summary->created, 'keyed on the external id, so nothing is duplicated');
        $this->assertFalse($summary->baselineWritten);

        $slab = ProgrammeActivity::query()->where('code', 'A1000')->firstOrFail();

        $this->assertSame('2026-10-12', $slab->planned_finish->toDateString(), 'the plan moved');
        $this->assertSame('2026-08-31', $slab->baseline_finish->toDateString(), 'the accepted programme did not');
        $this->assertSame(42, $slab->daysLateAgainstBaseline('2026-10-12'));
        $this->assertSame(0, $slab->daysLateAgainstPlan('2026-10-12'), 'against the plan it is on time — which is why the baseline exists');
    }

    /** **A baseline import moves it deliberately**, and records which revision did. */
    public function test_a_baseline_import_writes_the_baseline_and_records_the_revision(): void
    {
        $this->import->import($this->job, $this->xer());

        $slipped = str_replace('2026-08-31 17:00', '2026-10-12 17:00', $this->xer());
        $summary = $this->import->import($this->job, $slipped, ProgrammeImport::MODE_BASELINE, 'Rev C');

        $this->assertTrue($summary->baselineWritten);
        $this->assertStringContainsString('the baseline was set from this file', $summary->describe());

        $slab = ProgrammeActivity::query()->where('code', 'A1000')->firstOrFail();

        $this->assertSame('2026-10-12', $slab->baseline_finish->toDateString());
        $this->assertSame('Rev C', $slab->baseline_revision);
        $this->assertSame(0, $slab->daysLateAgainstBaseline('2026-10-12'));
    }

    /** The summary always says which of the two happened, in words. */
    public function test_the_summary_says_whether_the_baseline_moved(): void
    {
        $update = $this->import->import($this->job, $this->xer());

        $this->assertStringContainsString('the baseline was left alone', $update->describe());
        $this->assertStringContainsString('2 added', $update->describe());
        $this->assertStringContainsString('1 links', $update->describe());
    }

    /** Progress and actuals come through an update — that is what an update is for. */
    public function test_an_update_import_writes_progress_and_actuals(): void
    {
        $this->import->import($this->job, $this->xer());
        $this->import->import($this->job, $this->xer('2026-09-05 17:00', '100'));

        $slab = ProgrammeActivity::query()->where('code', 'A1000')->firstOrFail();

        $this->assertSame('2026-09-05', $slab->actual_finish->toDateString());
        $this->assertSame(100.0, (float) $slab->percent_complete);
        $this->assertTrue($slab->isComplete());
    }

    // ------------------------------------------------------------------ idempotence and keying

    /** Re-importing the same file changes nothing and duplicates nothing. */
    public function test_importing_the_same_file_twice_is_idempotent(): void
    {
        $this->import->import($this->job, $this->xer());
        $second = $this->import->import($this->job, $this->xer());

        $this->assertSame(0, $second->created);
        $this->assertSame(2, $second->updated);
        $this->assertSame(2, ProgrammeActivity::query()->count());
        $this->assertSame(1, ProgrammeActivityPredecessor::query()->count(), 'the link is not duplicated either');
    }

    /**
     * Two tools' files coexist, because the key is `(job, source, external_id)`.
     *
     * The accepted programme from P6 and a subcontractor's fragment from MS Project can both use activity id 1, meaning
     * different things.
     */
    public function test_two_sources_coexist_on_one_job(): void
    {
        $this->import->import($this->job, $this->xer());
        $this->import->import($this->job, $this->mspXml());

        $this->assertSame(4, ProgrammeActivity::query()->count());
        $this->assertSame(2, ProgrammeActivity::query()->where('source', 'p6_xer')->count());
        $this->assertSame(2, ProgrammeActivity::query()->where('source', 'msp_xml')->count());
    }

    /** Another job's programme is its own. */
    public function test_an_import_is_scoped_to_its_job(): void
    {
        $annexe = Job::create(['code' => 'J-2', 'name' => 'Annexe']);

        $this->import->import($this->job, $this->xer());
        $this->import->import($annexe, $this->xer());

        $this->assertSame(2, ProgrammeActivity::query()->where('job_id', $this->job->getKey())->count());
        $this->assertSame(2, ProgrammeActivity::query()->where('job_id', $annexe->getKey())->count());
    }

    // ------------------------------------------------------------------ what it refuses, and what it warns about

    public function test_a_file_that_is_not_a_programme_is_refused(): void
    {
        foreach ([
            ['', 'That file is empty.'],
            ["id,name\n1,Something", 'not a programme this application reads'],
        ] as [$contents, $expected]) {
            try {
                $this->import->import($this->job, $contents);
                $this->fail("Expected a refusal mentioning: {$expected}");
            } catch (InvalidArgumentException $e) {
                $this->assertStringContainsString($expected, $e->getMessage());
            }
        }
    }

    /** An export with relationships and no activities is a real mistake, and it says so. */
    public function test_a_file_with_no_activities_is_refused_with_the_likely_cause(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('No activities were found');

        $this->import->import($this->job, implode("\n", [
            'ERMHDR	19.12',
            '%T	TASKPRED',
            '%F	task_pred_id	task_id	pred_task_id	pred_type	lag_hr_cnt',
            '%R	1	1002	1001	PR_FS	0',
            '%E',
        ]));
    }

    /**
     * **A link naming an activity outside the file is warned about, not dropped silently.**
     *
     * An export filtered to one WBS branch legitimately references activities outside it, and a reader needs to know the
     * network stored here is partial.
     */
    public function test_a_dangling_relationship_is_counted_and_named(): void
    {
        $dangling = str_replace('%R	1	1002	1001	PR_FS	40', '%R	1	1002	9999	PR_FS	40', $this->xer());

        $summary = $this->import->import($this->job, $dangling);

        $this->assertSame(2, $summary->created);
        $this->assertSame(0, $summary->links);
        $this->assertTrue($summary->hasWarnings());
        $this->assertStringContainsString('9999', $summary->warnings[0]);
        $this->assertStringContainsString('1 row(s) skipped', $summary->describe());
    }

    /** An unparseable date becomes null rather than today — a guessed date is a guessed entitlement. */
    public function test_an_unreadable_date_becomes_null_rather_than_today(): void
    {
        $broken = str_replace('2026-09-30 17:00	2026-09-30 17:00', 'not a date	not a date', $this->xer());

        $this->import->import($this->job, $broken);

        $milestone = ProgrammeActivity::query()->where('code', 'M1000')->firstOrFail();

        $this->assertNull($milestone->planned_start);
        $this->assertNull($milestone->baseline_finish);
    }

    public function test_an_unknown_mode_is_refused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('not an import mode');

        $this->import->import($this->job, $this->xer(), 'whatever');
    }

    /**
     * An imported programme is still not solved.
     *
     * The links arrive and are stored; nothing recalculates the successor's dates from them, which a P6 round-trip
     * depends on and §13 requires.
     */
    public function test_importing_a_network_moves_no_date(): void
    {
        $this->import->import($this->job, $this->xer());

        $milestone = ProgrammeActivity::query()->where('code', 'M1000')->firstOrFail();

        // The predecessor finishes 31 Aug with a five-day lag; a scheduler would have moved this to 7 Sep.
        $this->assertSame('2026-09-30', $milestone->planned_finish->toDateString());
    }

    /** It works with only the spine and this module — §18. */
    public function test_the_import_works_with_only_the_spine_and_this_module(): void
    {
        CompanyModule::query()
            ->where('company_id', $this->tenant->getKey())
            ->whereIn('module', ['construction_costing', 'construction_contracts', 'invoicing', 'accounting'])
            ->update(['licensed' => false, 'enabled' => false]);
        modules()->flush();

        $this->assertSame(2, $this->import->import($this->job, $this->xer())->created);
    }
}
