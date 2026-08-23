<?php

namespace Tests\Feature;

use App\Modules\Core\Models\CompanyModule;
use App\Modules\Core\Models\User;
use App\Modules\Employees\Models\Employee;
use App\Modules\Projects\Models\Project;
use App\Modules\Timesheets\Filament\Pages\PlanVersusActual;
use App\Modules\Timesheets\Filament\Pages\TimesheetUtilisation;
use App\Modules\Timesheets\Models\TimesheetEntry;
use App\Support\Reporting\ReportPaneRenderer;
use App\Support\Reporting\ReportRenderers;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;
use Tests\Concerns\InteractsWithTenant;
use Tests\TestCase;

/**
 * The two timesheet reports — `docs/reports-expansion-plan.md` Phase 1.4.
 *
 * `TimesheetTest` owns the per-employee methods these are built beside. What this file asserts is what the
 * company-wide form adds, and the three claims are the ones that would each be invisible in a passing
 * render:
 *
 *  - **It does not loop.** `utilisationFor()` reaches `AttendanceCalendar::summarise()`, which walks every
 *    day of the month doing a holiday and a shift-pattern lookup per day. Called once per employee that is
 *    hundreds of queries for one screen, and it is the risk this plan names for these reports by name — so
 *    the query count is asserted rather than hoped for.
 *  - **It states no capacity, and no percentage against one.** The module refuses to say what hours were
 *    expected; a report is exactly where an invented denominator would be read as fact.
 *  - **The matrix shows every pairing either side knows about**, and its row total covers the columns it
 *    had to drop. A capped report whose totals only add up the visible columns disagrees with the
 *    timesheet it came from, and looks right.
 */
class TimesheetReportsTest extends TestCase
{
    use InteractsWithTenant;
    use RefreshDatabase;

    private const AS_OF = '2026-08-20';

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs(User::factory()->create(['status' => 1]));
        $this->setCurrentTenant();

        foreach (['timesheets', 'employees', 'projects'] as $module) {
            $this->setModule($module, true);
        }
    }

    // ────────────────────────────────────────────────────────────── fixtures ──

    private function setModule(string $module, bool $on): void
    {
        CompanyModule::updateOrCreate(
            ['company_id' => $this->tenant->getKey(), 'module' => $module],
            ['licensed' => $on, 'enabled' => $on],
        );

        modules()->flush();
    }

    private function employee(string $id): Employee
    {
        return Employee::create([
            'employee_id' => $id,
            'name' => $id,
            'gender' => 'Male',
            'is_active' => true,
            'date_of_joining' => '2020-01-01',
        ]);
    }

    private function project(string $name): Project
    {
        return Project::create([
            'name' => $name,
            'code' => strtoupper(substr(md5($name), 0, 6)),
        ]);
    }

    private function book(Employee $employee, Project $project, int $minutes, string $date, bool $billable = true): TimesheetEntry
    {
        return TimesheetEntry::create([
            'employee_id' => $employee->id,
            'project_id' => $project->id,
            'date' => $date,
            'minutes' => $minutes,
            'is_billable' => $billable,
        ]);
    }

    private function allocate(Employee $employee, Project $project, ?float $pct, string $from = '2026-01-01', ?string $to = null): void
    {
        DB::table('project_employee')->insert([
            'project_id' => $project->id,
            'employee_id' => $employee->id,
            'allocation_pct' => $pct,
            'from_date' => $from,
            'to_date' => $to,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /** @return array<string, mixed> */
    private function report(string $key, ?string $asOf = null): array
    {
        $payload = ReportRenderers::render($key, $asOf ?? self::AS_OF, false, []);

        $this->assertNotNull($payload, "no renderer is registered for {$key}");

        return $payload;
    }

    private function cells(array $payload): string
    {
        return collect($payload['rows'])->flatten()->implode(' | ');
    }

    private function row(array $payload, string $contains): array
    {
        foreach ($payload['rows'] as $row) {
            if (str_contains($row[0], $contains)) {
                return $row;
            }
        }

        $this->fail("no row for [{$contains}] in:\n".$this->cells($payload));
    }

    // ─────────────────────────────────────────────────────────── utilisation ──

    /** Billable and non-billable are kept apart, and the share is of what was recorded. */
    public function test_utilisation_splits_billable_from_non_billable(): void
    {
        $ali = $this->employee('EMP-1');
        $project = $this->project('Warehouse');

        $this->book($ali, $project, 360, '2026-08-03');            // 6h billable
        $this->book($ali, $project, 120, '2026-08-04', false);     // 2h not

        $payload = $this->report('TimesheetUtilisation');
        $row = $this->row($payload, 'EMP-1');

        $this->assertSame('6.0', $row[1]);
        $this->assertSame('2.0', $row[2]);
        $this->assertSame('8.0', $row[3]);
        $this->assertSame('75.0%', $row[4]);
        $this->assertSame(8.0, $payload['tiles'][0]['value']);
        $this->assertSame(6.0, $payload['tiles'][1]['value']);
    }

    /**
     * No capacity, and no percentage against one.
     *
     * The module refuses to state expected hours, and a report is where an invented denominator would be
     * read as fact. The column that exists is a share of recorded time — which is why "capacity" and
     * "expected" appear nowhere on the report's face.
     */
    public function test_utilisation_states_no_capacity_figure(): void
    {
        $this->book($this->employee('EMP-1'), $this->project('Warehouse'), 480, '2026-08-03');

        $payload = $this->report('TimesheetUtilisation');

        // The columns, which is where a capacity figure would have to appear to be read as one.
        $this->assertSame(['Employee', 'Billable', 'Non-billable', 'Booked', 'Billable share', 'Projects'], $payload['columns']);
        $this->assertStringNotContainsString('CAPACITY', mb_strtoupper($payload['note']));
    }

    /** The month of the date. A timesheet is reviewed monthly, and a year averages a bad month away. */
    public function test_utilisation_covers_the_month_of_the_date(): void
    {
        $ali = $this->employee('EMP-1');
        $project = $this->project('Warehouse');

        $this->book($ali, $project, 300, '2026-08-31');
        $this->book($ali, $project, 999, '2026-07-31');
        $this->book($ali, $project, 999, '2026-09-01');

        $payload = $this->report('TimesheetUtilisation');

        $this->assertStringContainsString('August 2026', $payload['subtitle']);
        $this->assertSame('5.0', $this->row($payload, 'EMP-1')[3]);
    }

    /** Projects touched, distinct — two entries against one project is one project. */
    public function test_utilisation_counts_distinct_projects(): void
    {
        $ali = $this->employee('EMP-1');
        $one = $this->project('Warehouse');
        $two = $this->project('Portal');

        $this->book($ali, $one, 60, '2026-08-03');
        $this->book($ali, $one, 60, '2026-08-04');
        $this->book($ali, $two, 60, '2026-08-05');

        $this->assertSame('2', $this->row($this->report('TimesheetUtilisation'), 'EMP-1')[5]);
    }

    /** The record row foots the rows above it. */
    public function test_utilisation_foots_to_the_rows_above_it(): void
    {
        $project = $this->project('Warehouse');
        $this->book($this->employee('EMP-1'), $project, 300, '2026-08-03');
        $this->book($this->employee('EMP-2'), $project, 180, '2026-08-04', false);

        $payload = $this->report('TimesheetUtilisation');

        $this->assertSame('Total — 2 people', $payload['footer'][0]);
        $this->assertSame('5.0', $payload['footer'][1]);
        $this->assertSame('3.0', $payload['footer'][2]);
        $this->assertSame('8.0', $payload['footer'][3]);
        // 5 of 8 hours billable.
        $this->assertSame('62.5%', $payload['footer'][4]);
    }

    /**
     * A grouped aggregation, not a loop over employees.
     *
     * The number is a ceiling rather than an exact figure — what it forbids is a query count that climbs
     * with the headcount, which is what looping `utilisationFor()` would have produced. Ten employees over
     * three projects is thirty pairings here.
     */
    public function test_utilisation_does_not_query_per_employee(): void
    {
        $projects = collect(['Warehouse', 'Portal', 'Ledger'])->map(fn (string $name): Project => $this->project($name));

        foreach (range(1, 10) as $i) {
            $employee = $this->employee('EMP-'.$i);

            foreach ($projects as $project) {
                $this->book($employee, $project, 60 * $i, '2026-08-0'.min(9, $i));
            }
        }

        $queries = 0;
        DB::listen(function () use (&$queries): void {
            $queries++;
        });

        $payload = $this->report('TimesheetUtilisation');

        $this->assertCount(10, $payload['rows']);
        $this->assertLessThanOrEqual(
            6,
            $queries,
            "the report ran {$queries} queries for ten employees, which is a loop rather than an aggregate",
        );
    }

    /** A month nobody booked in says so. */
    public function test_utilisation_says_when_nobody_booked_time(): void
    {
        $payload = $this->report('TimesheetUtilisation');

        $this->assertSame([], $payload['rows']);
        $this->assertSame('NOBODY BOOKED TIME IN THIS MONTH', $payload['note']);
    }

    // ────────────────────────────────────────────────────── plan vs actual ──

    /**
     * Both figures per cell, and every pairing either side knows about.
     *
     * Three cases, and each is a different problem: allocated with nothing booked, booked with no
     * allocation, and neither. A report showing only the pairings where both exist would always agree with
     * itself and never be worth opening.
     */
    public function test_plan_versus_actual_shows_hours_over_allocation(): void
    {
        $ali = $this->employee('EMP-1');
        $sara = $this->employee('EMP-2');
        $warehouse = $this->project('Warehouse');
        $portal = $this->project('Portal');

        // Allocated and booked: the ordinary case.
        $this->allocate($ali, $warehouse, 50);
        $this->book($ali, $warehouse, 750, '2026-08-03');

        // Allocated, nothing booked: the row worth looking at.
        $this->allocate($ali, $portal, 25);

        // Booked with no allocation: the second case.
        $this->book($sara, $portal, 120, '2026-08-04');

        $payload = $this->report('PlanVersusActual');
        $columns = $payload['columns'];

        $this->assertSame('Employee', $columns[0]);
        $this->assertSame('Booked', $columns[count($columns) - 1]);

        $aliRow = $this->row($payload, 'EMP-1');
        $this->assertContains('12.5h / 50%', $aliRow);
        $this->assertContains('— / 25%', $aliRow);

        $saraRow = $this->row($payload, 'EMP-2');
        $this->assertContains('2.0h', $saraRow);
        // No allocation on that pairing, so no percentage is stated for it.
        $this->assertNotContains('2.0h / ', $saraRow);
        // And the pairing neither side knows about.
        $this->assertContains('—', $saraRow);
    }

    /**
     * The row total covers the projects that lost their column.
     *
     * A capped report whose totals add up only the visible columns disagrees with the person's own
     * timesheet — and looks entirely right, which is what makes it worth a test of its own.
     */
    public function test_plan_versus_actual_totals_every_project_including_the_dropped_columns(): void
    {
        $ali = $this->employee('EMP-1');

        // Fourteen projects, two more than the twelve columns the matrix draws.
        foreach (range(1, 14) as $i) {
            $this->book($ali, $this->project('Project '.$i), 60, '2026-08-03');
        }

        $payload = $this->report('PlanVersusActual');
        $row = $this->row($payload, 'EMP-1');

        // Twelve project columns, plus the label and the total.
        $this->assertCount(14, $payload['columns']);
        // Fourteen hours booked, not the twelve that have columns.
        $this->assertSame('14.0', $row[count($row) - 1]);
        $this->assertSame(14.0, $payload['tiles'][0]['value']);
    }

    /** And says out loud that it dropped them. A silent cap reads as a complete report. */
    public function test_plan_versus_actual_says_what_it_left_out(): void
    {
        $ali = $this->employee('EMP-1');

        foreach (range(1, 14) as $i) {
            $this->book($ali, $this->project('Project '.$i), 60, '2026-08-03');
        }

        $this->assertStringContainsString(
            '2 QUIETER PROJECTS ARE NOT SHOWN AS COLUMNS',
            $this->report('PlanVersusActual')['note'],
        );
    }

    /** And says nothing about a cap when it did not apply one. */
    public function test_plan_versus_actual_is_quiet_when_it_dropped_nothing(): void
    {
        $this->book($this->employee('EMP-1'), $this->project('Warehouse'), 60, '2026-08-03');

        $this->assertStringNotContainsString('NOT SHOWN', $this->report('PlanVersusActual')['note']);
    }

    /**
     * The matrix declares itself wide, which is what makes it scroll rather than be clipped.
     *
     * `.fi-explorer-statement` is `overflow: clip`, so before Phase 0.2 a report with more columns than the
     * pane simply lost them — silently. This is the one report in the application that would have hit it,
     * so it is the one that asserts the flag, the wrapper and the rule together.
     */
    public function test_plan_versus_actual_declares_itself_wide_and_the_wrapper_exists(): void
    {
        $this->book($this->employee('EMP-1'), $this->project('Warehouse'), 60, '2026-08-03');

        $this->assertTrue($this->report('PlanVersusActual')['wide']);
        // And the narrow one does not pay for it: inside a scroll container the sticky header and total
        // stop following the reader down the page.
        $this->assertFalse($this->report('TimesheetUtilisation')['wide']);

        $partial = File::get(resource_path('views/filament/partials/report-table.blade.php'));
        $this->assertStringContainsString("\$statement['wide']", $partial);
        $this->assertStringContainsString('fi-explorer-scroll', $partial);

        $theme = File::get(resource_path('css/filament/admin/theme.css'));
        $this->assertStringContainsString('.fi-explorer-scroll {', $theme);
        $this->assertStringContainsString('overflow-x: auto', $theme);
        // Without this the grid shrinks to fit and wraps every figure instead of scrolling.
        $this->assertStringContainsString('min-width: max-content', $theme);
    }

    /** An assignment with no end date is an open one and counts for every month from its start. */
    public function test_plan_versus_actual_counts_an_open_assignment(): void
    {
        $ali = $this->employee('EMP-1');
        $this->allocate($ali, $this->project('Warehouse'), 40, from: '2026-01-01', to: null);
        // And one that ended before the month.
        $this->allocate($ali, $this->project('Portal'), 60, from: '2026-01-01', to: '2026-06-30');

        $payload = $this->report('PlanVersusActual');

        $this->assertStringContainsString('40%', $this->cells($payload));
        $this->assertStringNotContainsString('60%', $this->cells($payload));
    }

    /** Nobody allocated and nobody booking is a sentence, not an empty grid. */
    public function test_plan_versus_actual_says_when_there_is_nothing_to_compare(): void
    {
        $payload = $this->report('PlanVersusActual');

        $this->assertSame([], $payload['rows']);
        $this->assertStringContainsString('Nobody is allocated', $payload['empty']);
    }

    // ──────────────────────────────────────────── the page and the pane ──

    /** Two screens, one payload. */
    public function test_both_reports_state_the_same_thing_on_their_page_as_in_the_pane(): void
    {
        Gate::before(fn () => true);
        $ali = $this->employee('EMP-1');
        $project = $this->project('Warehouse');
        $this->allocate($ali, $project, 50);
        $this->book($ali, $project, 300, '2026-08-03');

        foreach ([TimesheetUtilisation::class, PlanVersusActual::class] as $page) {
            $key = class_basename($page);

            $onThePage = Livewire::test($page, ['asOf' => self::AS_OF])
                ->assertSuccessful()
                ->instance()
                ->statement();

            $this->assertSame(
                $onThePage,
                app(ReportPaneRenderer::class)->for($key, self::AS_OF, false, []),
                "{$key} draws differently on its page than in the pane",
            );
        }
    }

    // ─────────────────────────────────────────────────────────────── gating ──

    /** Both disappear with the module. */
    public function test_the_reports_are_gated_on_the_timesheets_module(): void
    {
        Gate::before(fn () => true);

        foreach ([TimesheetUtilisation::class, PlanVersusActual::class] as $page) {
            $this->assertTrue($page::canAccess(), class_basename($page).' is unreachable with Timesheets enabled');
        }

        $this->setModule('timesheets', false);

        foreach ([TimesheetUtilisation::class, PlanVersusActual::class] as $page) {
            $this->assertFalse($page::canAccess(), class_basename($page).' survives Timesheets being disabled');
        }
    }

    /** And on `ReportView`. */
    public function test_the_reports_are_gated_on_report_view(): void
    {
        foreach ([TimesheetUtilisation::class, PlanVersusActual::class] as $page) {
            $this->assertFalse($page::canAccess(), class_basename($page).' is reachable without ReportView');
        }
    }
}
