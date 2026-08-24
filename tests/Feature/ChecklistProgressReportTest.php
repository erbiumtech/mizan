<?php

namespace Tests\Feature;

use App\Modules\Core\Models\CompanyModule;
use App\Modules\Core\Models\User;
use App\Modules\Employees\Models\Employee;
use App\Modules\Lifecycle\Filament\Pages\ChecklistProgress;
use App\Modules\Lifecycle\Models\ChecklistTemplate;
use App\Modules\Lifecycle\Models\EmployeeChecklist;
use App\Modules\Lifecycle\Models\EmployeeChecklistItem;
use App\Support\Reporting\ReportPaneRenderer;
use App\Support\Reporting\ReportRenderers;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;
use Tests\Concerns\InteractsWithTenant;
use Tests\TestCase;

/**
 * Onboarding / offboarding progress — `docs/reports-expansion-plan.md` Phase 3.9.
 *
 * The plan asks for "items overdue by owner role", and the tests are mostly about the two things that phrasing
 * would leave out:
 *
 *  - **an item with no due date**, which can never *become* overdue, so an overdue-only report is precisely
 *    the report it will never appear on;
 *  - **an item with no owner role**, which nobody has been asked to do.
 *
 * Plus the one with a security edge — an exit item still open for somebody who has already left — and the
 * ordering, which is where "by owner role" is actually delivered: the role holding up the most sorts first.
 */
class ChecklistProgressReportTest extends TestCase
{
    use InteractsWithTenant;
    use RefreshDatabase;

    private const AS_OF = '2027-02-20';

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs(User::factory()->create(['status' => 1]));
        $this->setCurrentTenant();

        foreach (['lifecycle', 'employees', 'leave', 'advances'] as $module) {
            CompanyModule::updateOrCreate(
                ['company_id' => $this->tenant->getKey(), 'module' => $module],
                ['licensed' => true, 'enabled' => true],
            );
        }
        modules()->flush();
    }

    // ────────────────────────────────────────────────────────────── fixtures ──

    private function employee(string $name, ?string $leftOn = null): Employee
    {
        return Employee::create([
            'employee_id' => 'EMP-'.mb_substr(md5($name), 0, 4),
            'name' => $name,
            'date_of_joining' => '2026-08-01',
            'left_on' => $leftOn,
            'status' => $leftOn === null ? 1 : 0,
        ]);
    }

    private function checklist(Employee $employee, string $kind = ChecklistTemplate::KIND_ONBOARDING): EmployeeChecklist
    {
        return EmployeeChecklist::create([
            'employee_id' => $employee->getKey(),
            'kind' => $kind,
            'started_on' => '2026-08-01',
        ]);
    }

    private function item(EmployeeChecklist $checklist, array $attributes = []): EmployeeChecklistItem
    {
        return EmployeeChecklistItem::create(array_merge([
            'employee_checklist_id' => $checklist->getKey(),
            'title' => 'Issue a laptop',
            'owner_role' => 'IT',
            'due_on' => '2026-08-08',
        ], $attributes));
    }

    /** @return array<string, mixed> */
    private function report(?string $asOf = null): array
    {
        $payload = ReportRenderers::render('ChecklistProgress', $asOf ?? self::AS_OF, false, []);

        $this->assertNotNull($payload, 'no renderer is registered for ChecklistProgress');

        return $payload;
    }

    private function cell(array $payload, string $column, int $row = 0): string
    {
        $index = array_search($column, $payload['columns'], true);
        $this->assertNotFalse($index, "there is no {$column} column");

        return $payload['rows'][$row][$index];
    }

    // ─────────────────────────────────────────────── what is listed at all ──

    /** A completed item is not outstanding. */
    public function test_a_completed_item_is_not_listed(): void
    {
        $this->item($this->checklist($this->employee('Ayesha')), ['completed_at' => '2026-08-05 09:00:00']);

        $payload = $this->report();

        $this->assertSame([], $payload['rows']);
        $this->assertSame('EVERY CHECKLIST ITEM IS DONE', $payload['note']);
    }

    /** An outstanding one is, with the task, the owner and the person it is for. */
    public function test_an_outstanding_item_is_listed_with_its_owner(): void
    {
        $this->item($this->checklist($this->employee('Ayesha')), [
            'title' => 'Create a payroll record',
            'owner_role' => 'HR',
        ]);

        $payload = $this->report();

        $this->assertCount(1, $payload['rows']);
        $this->assertStringContainsString('Ayesha', $this->cell($payload, 'Employee'));
        $this->assertSame('Onboarding', $this->cell($payload, 'Checklist'));
        $this->assertSame('Create a payroll record', $this->cell($payload, 'Item'));
        $this->assertSame('HR', $this->cell($payload, 'Owner role'));
    }

    /** An exit checklist is labelled as one — the two kinds are read very differently. */
    public function test_an_exit_checklist_is_labelled_as_one(): void
    {
        $this->item($this->checklist($this->employee('Danish'), ChecklistTemplate::KIND_EXIT));

        $this->assertSame('Exit', $this->cell($this->report(), 'Checklist'));
    }

    // ──────────────────────────────────────────────────────── overdue ──

    /** Past its due date is overdue, and the lateness is counted to the date being read. */
    public function test_an_overdue_item_states_how_late_it_is(): void
    {
        $this->item($this->checklist($this->employee('Ayesha')), ['due_on' => '2026-08-08']);

        $payload = $this->report();

        // 8 August 2026 to 20 February 2027.
        $this->assertSame('Overdue', $this->cell($payload, 'Standing'));
        $this->assertSame('196', $this->cell($payload, 'Days late'));
        $this->assertSame(1.0, $payload['tiles'][0]['value']);
    }

    /** Counted to the date being read, not to today — the report is an as-at. */
    public function test_lateness_is_counted_to_the_date_being_read(): void
    {
        $this->item($this->checklist($this->employee('Ayesha')), ['due_on' => '2026-08-08']);

        $this->assertSame('9', $this->cell($this->report('2026-08-17'), 'Days late'));
    }

    /** An item not yet due is outstanding but is not late, and says which. */
    public function test_an_item_not_yet_due_is_not_overdue(): void
    {
        $this->item($this->checklist($this->employee('Ayesha')), ['due_on' => '2027-03-15']);

        $payload = $this->report();

        $this->assertSame('Not yet due', $this->cell($payload, 'Standing'));
        $this->assertSame('—', $this->cell($payload, 'Days late'));
        $this->assertSame(0.0, $payload['tiles'][0]['value']);
        $this->assertStringContainsString('NOTHING IS OVERDUE', $payload['note']);
    }

    /** An item due on the date being read is not yet late — a day's grace, not a day's default. */
    public function test_an_item_due_today_is_not_yet_overdue(): void
    {
        $this->item($this->checklist($this->employee('Ayesha')), ['due_on' => self::AS_OF]);

        $this->assertSame('Not yet due', $this->cell($this->report(), 'Standing'));
    }

    // ───────────────────────────────── an item that can never be overdue ──

    /**
     * An item with no due date can never become overdue.
     *
     * The finding, and the reason this is a progress report rather than an overdue list. `due_on` is nullable,
     * so an item without one sits outstanding forever and no report anybody reads for lateness will ever
     * mention it. It is called out as its own standing rather than lumped in with the not-yet-due.
     */
    public function test_an_item_with_no_due_date_is_called_out(): void
    {
        $this->item($this->checklist($this->employee('Ayesha')), ['due_on' => null]);

        $payload = $this->report();

        $this->assertSame('No due date', $this->cell($payload, 'Standing'));
        $this->assertSame('—', $this->cell($payload, 'Due'));
        $this->assertSame(0.0, $payload['tiles'][0]['value'], 'undated is not overdue');
        $this->assertStringContainsString(
            '1 WITH NO DUE DATE, SO NOTHING WILL EVER CHASE IT',
            $payload['note'],
        );
    }

    /** A dated item is not reported as undated. */
    public function test_a_dated_item_is_not_reported_as_undated(): void
    {
        $this->item($this->checklist($this->employee('Ayesha')));

        $this->assertStringNotContainsString('NO DUE DATE', $this->report()['note']);
    }

    /** Undated sorts above not-yet-due: it is a finding, not a future task. */
    public function test_an_undated_item_sorts_above_one_not_yet_due(): void
    {
        $checklist = $this->checklist($this->employee('Ayesha'));
        $this->item($checklist, ['title' => 'Later', 'due_on' => '2027-03-15']);
        $this->item($checklist, ['title' => 'Never', 'due_on' => null]);

        $payload = $this->report();

        $this->assertSame('Never', $this->cell($payload, 'Item', 0));
        $this->assertSame('Later', $this->cell($payload, 'Item', 1));
    }

    // ──────────────────────────────────── an item nobody owns ──

    /**
     * An item with no owner role is one nobody has been asked to do.
     *
     * `ChecklistItem` already names the worry — a template "pointing at somebody who left is a checklist
     * nobody owns". The cell says *Nobody* rather than being blank, because an empty cell reads as a
     * rendering gap rather than as the state of the record.
     */
    public function test_an_item_with_no_owner_is_called_out(): void
    {
        $this->item($this->checklist($this->employee('Ayesha')), ['owner_role' => null]);

        $payload = $this->report();

        $this->assertSame('Nobody', $this->cell($payload, 'Owner role'));
        $this->assertStringContainsString('1 OWNED BY NOBODY', $payload['note']);
    }

    /** An empty string is as unowned as a null — both are what a blank form field leaves behind. */
    public function test_an_empty_owner_role_counts_as_unowned(): void
    {
        $this->item($this->checklist($this->employee('Ayesha')), ['owner_role' => '']);

        $payload = $this->report();

        $this->assertSame('Nobody', $this->cell($payload, 'Owner role'));
        $this->assertStringContainsString('1 OWNED BY NOBODY', $payload['note']);
    }

    /** An owned item is not reported as unowned. */
    public function test_an_owned_item_is_not_reported_as_unowned(): void
    {
        $this->item($this->checklist($this->employee('Ayesha')));

        $this->assertStringNotContainsString('OWNED BY NOBODY', $this->report()['note']);
    }

    // ──────────────────────── an exit item open after somebody left ──

    /**
     * An exit item still open for somebody who has left is a door still unlocked.
     *
     * Exit checklists are where access cards, accounts and keys get revoked. Nothing else in the application
     * puts "this person has gone" next to "their access has not been removed".
     */
    public function test_an_exit_item_open_after_somebody_left_is_called_out(): void
    {
        $danish = $this->employee('Danish', leftOn: '2026-12-31');
        $this->item($this->checklist($danish, ChecklistTemplate::KIND_EXIT), ['title' => 'Revoke building access']);

        $payload = $this->report();

        $this->assertStringContainsString('LEFT', $this->cell($payload, 'Employee'));
        $this->assertStringContainsString(
            '1 EXIT ITEM STILL OPEN FOR SOMEBODY WHO HAS LEFT',
            $payload['note'],
        );
    }

    /** An onboarding item for somebody who has left is odd, but it is not the exit finding. */
    public function test_an_onboarding_item_for_a_leaver_is_not_the_exit_finding(): void
    {
        $danish = $this->employee('Danish', leftOn: '2026-12-31');
        $this->item($this->checklist($danish, ChecklistTemplate::KIND_ONBOARDING));

        $payload = $this->report();

        $this->assertStringContainsString('LEFT', $this->cell($payload, 'Employee'), 'still worth marking');
        $this->assertStringNotContainsString('EXIT ITEM STILL OPEN', $payload['note']);
    }

    /** An exit item for somebody who has not left yet is an exit in progress, not a failure. */
    public function test_an_exit_item_for_somebody_still_employed_is_not_called_out(): void
    {
        $this->item($this->checklist($this->employee('Ayesha'), ChecklistTemplate::KIND_EXIT));

        $payload = $this->report();

        $this->assertStringNotContainsString('LEFT', $this->cell($payload, 'Employee'));
        $this->assertStringNotContainsString('EXIT ITEM STILL OPEN', $payload['note']);
    }

    /**
     * Somebody's last day is not yet a leaver.
     *
     * The same reading `HeadcountReports::headcountAt()` uses and that Phase 3.7 was corrected to. Three
     * reports agreeing on what `left_on` means is worth more than each choosing for itself.
     */
    public function test_the_last_day_of_employment_is_not_yet_a_leaver(): void
    {
        $danish = $this->employee('Danish', leftOn: '2026-12-31');
        $this->item($this->checklist($danish, ChecklistTemplate::KIND_EXIT));

        $this->assertStringNotContainsString('LEFT', $this->cell($this->report('2026-12-31'), 'Employee'));
        $this->assertStringContainsString('LEFT', $this->cell($this->report('2027-01-01'), 'Employee'));
    }

    // ──────────────────────────────────────── by owner role ──

    /**
     * The role holding up the most work sorts first.
     *
     * This is where "by owner role" is actually delivered. Grouping the rows by role would answer whose queue
     * is longest and lose which task for whom, so instead the worst-blocked role's items rise to the top of a
     * report whose rows are still individually actionable.
     */
    public function test_the_role_with_the_most_overdue_work_sorts_first(): void
    {
        $checklist = $this->checklist($this->employee('Ayesha'));

        // HR has one item, overdue longest — so a sort on lateness alone would put it first.
        $this->item($checklist, ['title' => 'HR task', 'owner_role' => 'HR', 'due_on' => '2026-07-01']);
        $this->item($checklist, ['title' => 'IT one', 'owner_role' => 'IT', 'due_on' => '2026-08-01']);
        $this->item($checklist, ['title' => 'IT two', 'owner_role' => 'IT', 'due_on' => '2026-08-02']);

        $payload = $this->report();

        $this->assertSame('IT', $this->cell($payload, 'Owner role', 0));
        $this->assertSame('IT', $this->cell($payload, 'Owner role', 1));
        $this->assertSame('HR', $this->cell($payload, 'Owner role', 2));
    }

    /** Within a role, the longest-overdue item is first. */
    public function test_within_a_role_the_longest_overdue_item_is_first(): void
    {
        $checklist = $this->checklist($this->employee('Ayesha'));
        $this->item($checklist, ['title' => 'Newer', 'due_on' => '2026-09-01']);
        $this->item($checklist, ['title' => 'Older', 'due_on' => '2026-07-01']);

        $payload = $this->report();

        $this->assertSame('Older', $this->cell($payload, 'Item', 0));
        $this->assertSame('Newer', $this->cell($payload, 'Item', 1));
    }

    /**
     * The note splits the overdue count by role, biggest queue first, and names the worst delay.
     *
     * **HR is created first and IT second on purpose.** Counting into an array leaves it in insertion order,
     * so a fixture whose largest queue happens to be inserted first cannot tell a sorted split from an
     * unsorted one — which is exactly what the first version of this test did, and a surviving mutation said
     * so. Here the order only comes out IT-then-HR if something sorted it.
     */
    public function test_the_note_splits_the_overdue_count_by_role_biggest_first(): void
    {
        $checklist = $this->checklist($this->employee('Ayesha'));
        $this->item($checklist, ['owner_role' => 'HR', 'due_on' => '2026-07-01']);
        $this->item($checklist, ['owner_role' => 'IT', 'due_on' => '2026-08-01']);
        $this->item($checklist, ['owner_role' => 'IT', 'due_on' => '2026-08-02']);

        $note = $this->report()['note'];

        $this->assertStringContainsString('3 OVERDUE', $note);
        $this->assertStringContainsString('IT 2, HR 1', $note);
    }

    /**
     * A long role list is truncated, and says that it is.
     *
     * A note is one line, so a company with many roles would push everything after it off the end. Truncating
     * silently would read as the whole answer, which on a report about who is blocking what is worse than a
     * longer line.
     */
    public function test_a_long_role_split_is_truncated_and_says_so(): void
    {
        $checklist = $this->checklist($this->employee('Ayesha'));

        foreach (['IT', 'HR', 'Finance', 'Line manager', 'Facilities'] as $role) {
            $this->item($checklist, ['owner_role' => $role, 'due_on' => '2026-08-01']);
        }

        // Created last and holding the most, so it can only be named if the split is sorted before it is cut.
        $this->item($checklist, ['owner_role' => 'Payroll', 'due_on' => '2026-08-01']);
        $this->item($checklist, ['owner_role' => 'Payroll', 'due_on' => '2026-08-02']);

        $note = $this->report()['note'];

        $this->assertStringContainsString('PAYROLL 2', $note);
        $this->assertStringContainsString('AND 3 MORE ROLES', $note);
    }

    // ─────────────────────────────────────────── progress and the footer ──

    /**
     * Progress is measured against the checklists that still have work, not against all of history.
     *
     * A completion figure diluted by years of finished onboardings would sit near 100% permanently. Against
     * the live checklists it moves, which is the only version of the figure anybody can act on.
     */
    public function test_progress_counts_only_the_checklists_that_still_have_work(): void
    {
        $live = $this->checklist($this->employee('Ayesha'));
        $this->item($live, ['title' => 'Outstanding']);
        $this->item($live, ['title' => 'Done', 'completed_at' => '2026-08-05 09:00:00']);

        // A finished checklist from an earlier starter, which must not dilute the figure.
        $finished = $this->checklist($this->employee('Bilal'));
        $this->item($finished, ['title' => 'Also done', 'completed_at' => '2026-08-05 09:00:00']);

        $this->assertStringContainsString('1 OF 2 DONE', $this->report()['note']);
    }

    /** The footer carries the count, the worst delay and how many are overdue. */
    public function test_the_footer_states_the_overdue_count_and_worst_delay(): void
    {
        $checklist = $this->checklist($this->employee('Ayesha'));
        $this->item($checklist, ['due_on' => '2026-08-01']);
        $this->item($checklist, ['due_on' => '2027-03-15']);

        $payload = $this->report();

        $this->assertSame('Total — 2 items outstanding', $payload['footer'][0]);
        $this->assertStringContainsString('1 overdue', $payload['footer'][6]);
        $this->assertStringContainsString('worst 203', $payload['footer'][5]);
    }

    /** With nothing overdue the footer says so rather than leaving the cell blank. */
    public function test_the_footer_says_none_overdue_when_nothing_is(): void
    {
        $this->item($this->checklist($this->employee('Ayesha')), ['due_on' => '2027-03-15']);

        $this->assertSame('none overdue', $this->report()['footer'][6]);
    }

    // ──────────────────────────────────────────── the page and the pane ──

    /** Two screens, one payload. */
    public function test_the_report_states_the_same_thing_on_its_page_as_in_the_pane(): void
    {
        Gate::before(fn () => true);
        $this->item($this->checklist($this->employee('Ayesha')));

        $onThePage = Livewire::test(ChecklistProgress::class, ['asOf' => self::AS_OF])
            ->assertSuccessful()
            ->instance()
            ->statement();

        $this->assertSame(
            $onThePage,
            app(ReportPaneRenderer::class)->for('ChecklistProgress', self::AS_OF, false, []),
        );
    }

    /** And on `ReportView`. */
    public function test_the_report_is_gated_on_report_view(): void
    {
        $this->assertFalse(ChecklistProgress::canAccess());
    }
}
