<?php

namespace Tests\Feature;

use App\Modules\Construction\Models\CostCode;
use App\Modules\Construction\Models\Job;
use App\Modules\ConstructionCosting\Filament\Resources\LabourRecords\Pages\ListLabourRecords;
use App\Modules\ConstructionCosting\Models\LabourRecord;
use App\Modules\ConstructionCosting\Models\Trade;
use App\Modules\ConstructionCosting\Models\Worker;
use App\Modules\ConstructionCosting\Services\LabourRateService;
use App\Modules\ConstructionCosting\Services\LabourRecordService;
use App\Modules\ConstructionCosting\Services\TimesheetLabourImport;
use App\Modules\Core\Models\CompanyModule;
use App\Modules\Employees\Models\Employee;
use App\Modules\Projects\Models\Project;
use App\Modules\Timesheets\Models\TimesheetEntry;
use App\Support\ModuleMap;
use Filament\Actions\Testing\TestAction;
use InvalidArgumentException;
use Livewire\Livewire;
use Tests\AccountingTestCase;
use Tests\Concerns\InteractsWithTenant;

/**
 * Timesheet entries into labour records — §7.1, Phase 7d.
 *
 * **The direction is the decision.** §7.1: "Where Timesheets *is* licensed, its entries import into labour records
 * rather than being read in place." The reason is finding #5 of this plan: `timesheet_entries` bills and never costs.
 * Its rate ladder resolves what time is *billed* at, so costing a job from those figures prices it at charge-out rates
 * and overstates every margin by the mark-up. Billing keeps its ladder; costing gets its own.
 *
 * Five properties carry the file:
 *
 *  - **Copied, not read.** An entry becomes a draft labour record that §7's own ladder prices at approval.
 *  - **Drafts, never approved.** An import that booked cost would bypass the approval that snapshots the rate, and
 *    would let a timesheet entry reach a job with nobody having read it.
 *  - **Idempotent on the source morph**, because running the same fortnight twice is how somebody checks they got
 *    everything.
 *  - **A row that cannot be imported is named**, one sentence each, and the rest of the file still goes in — the
 *    precedent Attendance's importer set.
 *  - **Nothing is invented.** No worker is created, no cost code is guessed, and no overtime split is inferred.
 */
class ConstructionTimesheetImportTest extends AccountingTestCase
{
    use InteractsWithTenant;

    private Job $job;

    private Project $project;

    private CostCode $labourCode;

    private Trade $engineer;

    private Worker $bilal;

    private Employee $employee;

    private TimesheetLabourImport $import;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs($this->makeUser('Administrator', 'tsimport@test.local'));
        $this->setCurrentTenant();

        foreach (['construction', 'construction_costing', 'accounting', 'employees', 'projects', 'timesheets'] as $module) {
            CompanyModule::updateOrCreate(
                ['company_id' => $this->tenant->getKey(), 'module' => $module],
                ['licensed' => true, 'enabled' => true],
            );
        }
        modules()->flush();

        $this->project = Project::create(['name' => 'Tower fit-out', 'code' => 'P-1']);

        $this->job = Job::create([
            'code' => 'J-1', 'name' => 'Tower', 'project_id' => $this->project->getKey(),
        ]);

        $this->labourCode = CostCode::create([
            'code' => '01.100', 'name' => 'Site supervision', 'cost_type' => CostCode::TYPE_LABOUR, 'unit' => 'hr',
        ]);

        $this->engineer = Trade::create([
            'code' => 'ENG', 'name' => 'Site engineer', 'default_cost_code_id' => $this->labourCode->getKey(),
        ]);

        $this->employee = $this->makeEmployee('bilal@test.local');

        $this->bilal = Worker::create([
            'code' => 'W-001', 'name' => 'Bilal',
            'engagement' => Worker::ENGAGEMENT_EMPLOYEE,
            'employee_id' => $this->employee->getKey(),
            'trade_id' => $this->engineer->getKey(),
        ]);

        app(LabourRateService::class)->set([], 900, '2026-01-01', ['burden_percent' => 15]);

        $this->import = app(TimesheetLabourImport::class);
    }

    private function makeEmployee(string $email): Employee
    {
        $user = $this->makeUser('Employee', $email);

        return Employee::create([
            'user_id' => $user->getKey(),
            'employee_id' => 'EMP-'.$user->getKey(),
            'joining_date' => '2026-01-01',
        ]);
    }

    /** @param array<string, mixed> $attributes */
    private function entry(array $attributes = []): TimesheetEntry
    {
        return TimesheetEntry::create(array_merge([
            'employee_id' => $this->employee->getKey(),
            'project_id' => $this->project->getKey(),
            'date' => '2026-08-10',
            'minutes' => 480,
            'is_billable' => true,
            'task' => 'Setting out the west bay',
            'approved_at' => now(),
            'approved_by' => auth()->id(),
        ], $attributes));
    }

    // ------------------------------------------------------------------ the happy path

    /** **The exit condition of this sub-phase.** An approved entry becomes a draft site sheet. */
    public function test_an_approved_entry_becomes_a_draft_labour_record(): void
    {
        $entry = $this->entry();

        $summary = $this->import->import('2026-08-01', '2026-08-31');

        $this->assertSame(1, $summary->imported);
        $this->assertSame([], $summary->skipped);

        $record = LabourRecord::query()->firstOrFail();

        $this->assertTrue($record->isDraft(), 'the import never approves — approval is a person');
        $this->assertSame($this->bilal->getKey(), $record->worker_id);
        $this->assertSame($this->job->getKey(), $record->job_id);
        $this->assertSame($this->labourCode->getKey(), $record->cost_code_id, 'from the worker\'s trade');
        $this->assertSame(480, $record->normal_minutes);
        $this->assertSame(0, $record->overtime_minutes);
        $this->assertSame('Setting out the west bay', $record->description);
    }

    /** The morph links the two, which is what makes the import traceable and idempotent. */
    public function test_the_record_names_the_entry_it_came_from(): void
    {
        $entry = $this->entry();

        $this->import->import('2026-08-01', '2026-08-31');

        $record = LabourRecord::query()->firstOrFail();

        $this->assertSame(ModuleMap::alias(TimesheetEntry::class), $record->source_type);
        $this->assertSame($entry->getKey(), (int) $record->source_id);
    }

    /**
     * **Costing gets its own ladder**, which is the whole reason the entry is copied rather than read.
     *
     * The timesheet's own rate ladder is charge-out; approving the imported record prices it at the construction cost
     * rate of §7.2 — 900 an hour here, with 15% burden — and never at what the client is billed.
     */
    public function test_the_imported_record_is_priced_from_the_construction_ladder(): void
    {
        $this->entry();
        $this->import->import('2026-08-01', '2026-08-31');

        $record = app(LabourRecordService::class)->approve(LabourRecord::query()->firstOrFail());

        $this->assertEquals(900, $record->cost_rate_per_hour);
        $this->assertEquals(7_200, $record->labour_amount);
        $this->assertEquals(1_080, $record->burden_amount);
    }

    /** Non-billable time still cost the company, so it is imported like any other. */
    public function test_non_billable_time_is_still_cost(): void
    {
        $this->entry(['is_billable' => false]);

        $this->assertSame(1, $this->import->import('2026-08-01', '2026-08-31')->imported);
    }

    /** Every minute arrives as normal time: `timesheet_entries` has no overtime split to read. */
    public function test_all_minutes_arrive_as_normal_time(): void
    {
        $this->entry(['minutes' => 660]);

        $this->import->import('2026-08-01', '2026-08-31');

        $record = LabourRecord::query()->firstOrFail();

        $this->assertSame(660, $record->normal_minutes);
        $this->assertSame(0, $record->overtime_minutes, 'inventing overtime from a threshold would price hours at a multiple nobody agreed');
    }

    // ------------------------------------------------------------------ what it will not touch

    /** An unapproved entry is time nobody has agreed, so it is not cost. */
    public function test_an_unapproved_entry_is_not_imported(): void
    {
        $this->entry(['approved_at' => null, 'approved_by' => null]);

        $summary = $this->import->import('2026-08-01', '2026-08-31');

        $this->assertSame(0, $summary->considered());
        $this->assertSame(0, LabourRecord::query()->count());
    }

    /** An entry outside the period is left for the run that covers it. */
    public function test_entries_outside_the_period_are_left_alone(): void
    {
        $this->entry(['date' => '2026-07-31']);
        $this->entry(['date' => '2026-09-01']);

        $this->assertSame(0, $this->import->import('2026-08-01', '2026-08-31')->considered());
    }

    /** An entry on a project no job names belongs to somebody else's work. */
    public function test_an_entry_on_an_unclaimed_project_is_not_imported(): void
    {
        $other = Project::create(['name' => 'Office refit', 'code' => 'P-2']);
        $this->entry(['project_id' => $other->getKey()]);

        $this->assertSame(0, $this->import->import('2026-08-01', '2026-08-31')->considered());
    }

    /**
     * A person with no worker record is **reported, not created**.
     *
     * A worker register that fills itself from timesheets is a register nobody chose the contents of, and §7.1's whole
     * argument is about who belongs in it.
     */
    public function test_an_employee_with_no_worker_record_is_reported(): void
    {
        $stranger = $this->makeEmployee('stranger@test.local');
        $this->entry(['employee_id' => $stranger->getKey()]);

        $summary = $this->import->import('2026-08-01', '2026-08-31');

        $this->assertSame(0, $summary->imported);
        $this->assertCount(1, $summary->skipped);
        $this->assertStringContainsString('worker register', $summary->skipped[0]);
        $this->assertSame(1, Worker::query()->count(), 'nobody was added to the register');
    }

    /** A worker with no trade has no cost code to book to, and the message says which is missing. */
    public function test_a_worker_with_no_trade_is_reported(): void
    {
        $this->bilal->update(['trade_id' => null]);
        $this->entry();

        $summary = $this->import->import('2026-08-01', '2026-08-31');

        $this->assertSame(0, $summary->imported);
        $this->assertStringContainsString('no trade is set', $summary->skipped[0]);
    }

    /** And a trade with no usual code is the other half of the same absence. */
    public function test_a_trade_with_no_cost_code_is_reported(): void
    {
        $this->engineer->update(['default_cost_code_id' => null]);
        $this->entry();

        $summary = $this->import->import('2026-08-01', '2026-08-31');

        $this->assertSame(0, $summary->imported);
        $this->assertStringContainsString('no usual cost code', $summary->skipped[0]);
    }

    /**
     * **One bad row does not lose the file** — Attendance's importer precedent.
     *
     * Two entries, one for somebody with no worker record. The good one goes in and the other is named.
     */
    public function test_one_unimportable_row_does_not_stop_the_others(): void
    {
        $stranger = $this->makeEmployee('stranger2@test.local');

        $this->entry();
        $this->entry(['employee_id' => $stranger->getKey(), 'date' => '2026-08-11']);

        $summary = $this->import->import('2026-08-01', '2026-08-31');

        $this->assertSame(1, $summary->imported);
        $this->assertCount(1, $summary->skipped);
        $this->assertSame(2, $summary->considered());
    }

    /**
     * A day already full from a site sheet is a real conflict, reported per row rather than abandoning the run.
     *
     * This is `LabourRecordService`'s day-length guard reaching the import, which is exactly what it is for: the same
     * day entered on a site sheet and on a timesheet is double-counted labour.
     */
    public function test_a_day_already_full_is_reported_rather_than_double_booked(): void
    {
        app(LabourRecordService::class)->record($this->bilal, $this->job, $this->labourCode, [
            'worked_on' => '2026-08-10', 'normal_minutes' => 1_100,
        ]);

        $this->entry(['minutes' => 480]);

        $summary = $this->import->import('2026-08-01', '2026-08-31');

        $this->assertSame(0, $summary->imported);
        $this->assertStringContainsString('more than a day', $summary->skipped[0]);
    }

    // ------------------------------------------------------------------ running it twice

    /** Running the same fortnight again imports nothing, and says so without calling it a fault. */
    public function test_a_second_run_imports_nothing(): void
    {
        $this->entry();

        $this->assertSame(1, $this->import->import('2026-08-01', '2026-08-31')->imported);

        $second = $this->import->import('2026-08-01', '2026-08-31');

        $this->assertSame(0, $second->imported);
        $this->assertSame(1, $second->alreadyImported);
        $this->assertSame([], $second->skipped);
        $this->assertSame(1, LabourRecord::query()->count());
    }

    /**
     * A record somebody **reversed** does not come back on the next import.
     *
     * Re-importing it would undo a decision and look like the import working, which is the worst combination
     * available.
     */
    public function test_a_reversed_record_is_not_re_imported(): void
    {
        $this->entry();
        $this->import->import('2026-08-01', '2026-08-31');

        $records = app(LabourRecordService::class);
        $record = $records->approve(LabourRecord::query()->firstOrFail());
        $records->reverse($record, 'Booked against the wrong job.');

        $second = $this->import->import('2026-08-01', '2026-08-31');

        $this->assertSame(0, $second->imported);
        $this->assertSame(1, $second->alreadyImported);
    }

    // ------------------------------------------------------------------ preview and scoping

    /** The preview writes nothing, which is the property that makes it worth having. */
    public function test_the_preview_writes_nothing(): void
    {
        $this->entry();

        $summary = $this->import->preview('2026-08-01', '2026-08-31');

        $this->assertSame(1, $summary->imported);
        $this->assertTrue($summary->previewOnly);
        $this->assertSame(0, LabourRecord::query()->count());
        $this->assertStringContainsString('Would import', $summary->summary());
    }

    /** Scoped to one job, entries on another job's project are left alone. */
    public function test_the_import_can_be_scoped_to_one_job(): void
    {
        $otherProject = Project::create(['name' => 'Annexe', 'code' => 'P-3']);
        $otherJob = Job::create([
            'code' => 'J-2', 'name' => 'Annexe', 'project_id' => $otherProject->getKey(),
        ]);

        $this->entry();
        $this->entry(['project_id' => $otherProject->getKey(), 'date' => '2026-08-11']);

        $summary = $this->import->import('2026-08-01', '2026-08-31', $otherJob);

        $this->assertSame(1, $summary->imported);
        $this->assertSame($otherJob->getKey(), LabourRecord::query()->firstOrFail()->job_id);
    }

    /** A job with no project cannot match anything, and the message says what to fix. */
    public function test_a_job_with_no_project_is_reported(): void
    {
        $unlinked = Job::create(['code' => 'J-9', 'name' => 'Depot']);

        $summary = $this->import->import('2026-08-01', '2026-08-31', $unlinked);

        $this->assertSame(0, $summary->imported);
        $this->assertStringContainsString('names no project', $summary->skipped[0]);
    }

    public function test_a_period_that_ends_before_it_starts_is_refused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('ends before it starts');

        $this->import->import('2026-08-31', '2026-08-01');
    }

    // ------------------------------------------------------------------ guarded, not required (§18.1)

    /**
     * Without Timesheets the import refuses in one sentence, and the register is unchanged.
     *
     * §18.1's row: the absence leaves "site sheets only, which is the primary path anyway" — most site labour is not on
     * a timesheet and never will be.
     */
    public function test_the_import_is_unavailable_without_the_timesheets_module(): void
    {
        CompanyModule::query()
            ->where('company_id', $this->tenant->getKey())
            ->where('module', 'timesheets')
            ->update(['licensed' => false, 'enabled' => false]);
        modules()->flush();

        $this->assertFalse($this->import->isAvailable());

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('needs the Timesheets module');

        $this->import->import('2026-08-01', '2026-08-31');
    }

    // ------------------------------------------------------------------ the screen

    /** The action is offered on the site-sheet register and brings the entries in. */
    public function test_the_import_action_brings_the_entries_in(): void
    {
        $this->entry();

        Livewire::test(ListLabourRecords::class)
            ->callAction(TestAction::make('importTimesheets'), [
                'from' => '2026-08-01',
                'to' => '2026-08-31',
            ]);

        $this->assertSame(1, LabourRecord::query()->count());
        $this->assertTrue(LabourRecord::query()->firstOrFail()->isDraft());
    }

    /** And it is absent altogether without the module, rather than failing when pressed. */
    public function test_the_import_action_is_absent_without_the_module(): void
    {
        Livewire::test(ListLabourRecords::class)
            ->assertActionVisible(TestAction::make('importTimesheets'));

        CompanyModule::query()
            ->where('company_id', $this->tenant->getKey())
            ->where('module', 'timesheets')
            ->update(['licensed' => false, 'enabled' => false]);
        modules()->flush();

        Livewire::test(ListLabourRecords::class)
            ->assertActionHidden(TestAction::make('importTimesheets'));
    }
}
