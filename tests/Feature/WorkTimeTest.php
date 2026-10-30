<?php

namespace Tests\Feature;

use App\Modules\Core\Models\CompanyModule;
use App\Modules\Employees\Models\Employee;
use App\Modules\Invoicing\Models\Contact;
use App\Modules\Projects\Models\Project;
use App\Modules\Timesheets\Models\TimesheetEntry;
use App\Modules\Timesheets\Services\BillableHours;
use App\Modules\Work\Models\WorkItem;
use App\Modules\Work\Models\WorkSprint;
use App\Modules\Work\Models\WorkWorkflow;
use App\Modules\Work\Services\WorkItems;
use App\Modules\Work\Services\WorkTime;
use App\Support\Contracts\LabourCost;
use Database\Seeders\WorkWorkflowSeeder;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Tests\AccountingTestCase;
use Tests\Concerns\InteractsWithTenant;

/**
 * Time on a work item — docs/project-management-plan.md §9.
 *
 * The rule under test is the plan's own sentence: an hour exists in the ledger exactly once, and
 * the ledger is the timesheet. Logging from an item writes a timesheet row and nothing else; the
 * item's figures are read back from those rows; a row that came from a sync is edited where it was
 * made; and an invoice line carries the keys of what the hours were for.
 */
class WorkTimeTest extends AccountingTestCase
{
    use InteractsWithTenant;

    private Project $project;

    private Employee $employee;

    protected function setUp(): void
    {
        parent::setUp();

        $user = $this->makeUser('Administrator', 'time@test.local');
        $this->actingAs($user);
        $company = $this->setCurrentTenant();

        foreach (['projects', 'work', 'timesheets'] as $module) {
            CompanyModule::updateOrCreate(
                ['company_id' => $company->getKey(), 'module' => $module],
                ['licensed' => true, 'enabled' => true],
            );
        }

        modules()->flush();
        $this->seed(WorkWorkflowSeeder::class);

        $this->employee = Employee::create([
            'employee_id' => 'EMP-1',
            'name' => 'Ali Raza',
            'gender' => 'Male',
            'is_active' => true,
            'user_id' => $user->getKey(),
            'hourly_rate' => 8000,
        ]);

        $this->project = Project::create([
            'code' => 'PRJ-WEB-01',
            'name' => 'The website',
            'status' => 'active',
            'work_workflow_id' => WorkWorkflow::default()->getKey(),
            'contact_id' => Contact::create(['name' => 'Acme Textiles', 'kind' => Contact::KIND_CUSTOMER, 'is_active' => true])->getKey(),
        ]);
    }

    private function item(array $attributes = []): WorkItem
    {
        return app(WorkItems::class)->create($this->project, ['title' => 'A thing', ...$attributes], auth()->user());
    }

    /** Logging writes one timesheet row, against the item, and nothing else. */
    public function test_logging_time_writes_one_timesheet_entry_against_the_item(): void
    {
        $item = $this->item(['estimate_minutes' => 240]);

        $entry = app(WorkTime::class)->log($item, auth()->user(), 90, '2026-11-02', 'Fixed the export');

        $this->assertSame(1, TimesheetEntry::query()->count());
        $this->assertSame((int) $item->getKey(), (int) $entry->work_item_id);
        $this->assertSame((int) $this->project->getKey(), (int) $entry->project_id);
        $this->assertSame((int) $this->employee->getKey(), (int) $entry->employee_id, 'my time, on my timesheet');
        $this->assertSame('manual', $entry->source);
        $this->assertSame($item->key, $entry->task);
        $this->assertTrue($entry->is_billable);

        $summary = app(WorkTime::class)->summaryFor($item);
        $this->assertSame(90, $summary['logged']);
        $this->assertSame(150, $summary['remaining']);
        $this->assertNull($summary['cost'], 'no payroll, so nothing can say what the hour cost');
        $this->assertSame(1, $summary['uncosted']);
    }

    public function test_over_the_estimate_reads_as_a_negative_remaining(): void
    {
        $item = $this->item(['estimate_minutes' => 60]);

        app(WorkTime::class)->log($item, auth()->user(), 120, '2026-11-02');

        $this->assertSame(-60, app(WorkTime::class)->summaryFor($item)['remaining']);
        $this->assertSame('−1h 00m', WorkTime::duration(-60));
    }

    /** The timesheet's own rule applies: more than a day is hours typed where minutes were meant. */
    public function test_the_timesheets_own_rules_still_apply(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('more than a day');

        app(WorkTime::class)->log($this->item(), auth()->user(), 2000, '2026-11-02');
    }

    public function test_without_a_linked_employee_there_is_nobody_to_book_to(): void
    {
        $this->employee->update(['user_id' => 999999]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('not linked to an employee');

        app(WorkTime::class)->log($this->item(), auth()->user(), 30, '2026-11-02');
    }

    /** Cost is the LabourCost contract over the entries, and says how many it could not price. */
    public function test_cost_rolls_up_through_the_labour_cost_contract(): void
    {
        $this->app->bind(LabourCost::class, fn () => new class implements LabourCost
        {
            public function hourlyFor(int|string $employeeId, string $on): ?float
            {
                // Priced in November, unpriced in December: one row of each below.
                return str_starts_with($on, '2026-11') ? 1200.0 : null;
            }
        });

        $item = $this->item();
        app(WorkTime::class)->log($item, auth()->user(), 90, '2026-11-02');
        app(WorkTime::class)->log($item, auth()->user(), 60, '2026-12-02');

        $summary = app(WorkTime::class)->summaryFor($item);

        $this->assertSame(150, $summary['logged']);
        $this->assertSame(1800.0, $summary['cost'], '1.5 hours at 1,200; the December hour is not guessed at');
        $this->assertSame(1, $summary['uncosted']);
    }

    /** A sprint's figures are the sum over its items; an Epic's include its children. */
    public function test_a_sprint_and_an_epic_show_the_same_figures_in_sum(): void
    {
        $sprint = WorkSprint::create(['project_id' => $this->project->getKey(), 'name' => 'Sprint 1']);
        $epic = $this->item(['title' => 'Epic', 'type' => 'Epic']);
        $one = $this->item(['title' => 'One', 'sprint_id' => $sprint->getKey(), 'parent_id' => $epic->getKey(), 'estimate_minutes' => 120]);
        $two = $this->item(['title' => 'Two', 'sprint_id' => $sprint->getKey(), 'estimate_minutes' => 60]);
        $this->item(['title' => 'Elsewhere']);

        app(WorkTime::class)->log($one, auth()->user(), 30, '2026-11-02');
        app(WorkTime::class)->log($two, auth()->user(), 45, '2026-11-02');

        $sprintTime = app(WorkTime::class)->summaryForSprint($sprint);
        $this->assertSame(75, $sprintTime['logged']);
        $this->assertSame(180, $sprintTime['estimate']);
        $this->assertSame(105, $sprintTime['remaining']);

        $epicTime = app(WorkTime::class)->summaryForEpic($epic);
        $this->assertSame(30, $epicTime['logged']);
        $this->assertSame(120, $epicTime['estimate']);
    }

    /** A row that came from a sync is edited where it was made. */
    public function test_a_synced_row_is_read_only_on_the_timesheet(): void
    {
        $item = $this->item();
        $entry = app(WorkTime::class)->log($item, auth()->user(), 60, '2026-11-02');
        $entry->forceFill(['source' => WorkTime::CLOCKIFY, 'external_ref' => 'ck_1'])->saveQuietly();

        $this->assertFalse(WorkTime::isEditable($entry->fresh()));

        try {
            $entry->fresh()->update(['minutes' => 90]);
            $this->fail('a Clockify row should not take a hand edit');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('came from Clockify', $e->getMessage());
        }

        $this->assertSame(60, $entry->fresh()->minutes);

        // The hand-made row is still the person's to change.
        $manual = app(WorkTime::class)->log($item, auth()->user(), 20, '2026-11-03');
        $manual->update(['minutes' => 25]);
        $this->assertSame(25, $manual->fresh()->minutes);
    }

    /** The invoice line names what the hours were for — §9's last paragraph. */
    public function test_the_invoice_line_carries_the_item_keys(): void
    {
        $one = $this->item(['title' => 'One']);
        $two = $this->item(['title' => 'Two']);

        foreach ([$one, $two, $one] as $item) {
            $entry = app(WorkTime::class)->log($item, auth()->user(), 60, '2026-11-02');
            $entry->forceFill(['approved_at' => now(), 'approved_by' => auth()->id()])->saveQuietly();
        }

        $lines = app(BillableHours::class)->linesFor((int) $this->project->contact_id, 2026, 11);

        $this->assertCount(1, $lines, 'one line per employee per project');
        $this->assertStringContainsString('3 hours at 8,000.00 (WEB-1, WEB-2)', $lines[0]['description']);
        $this->assertSame(24000.0, $lines[0]['amount']);
    }
}
