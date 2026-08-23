<?php

namespace Tests\Feature;

use App\Modules\Core\Models\CompanyModule;
use App\Modules\Core\Models\User;
use App\Modules\Employees\Models\Employee;
use App\Modules\Invoicing\Models\Contact;
use App\Modules\Projects\Models\Project;
use App\Modules\Timesheets\Filament\Pages\UnbilledWip as UnbilledWipPage;
use App\Modules\Timesheets\Models\TimesheetEntry;
use App\Modules\Timesheets\Services\TimesheetService;
use App\Support\Reporting\ReportPaneRenderer;
use App\Support\Reporting\ReportRenderers;
use App\Support\TenantSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;
use Tests\Concerns\InteractsWithTenant;
use Tests\TestCase;

/**
 * Unbilled WIP — `docs/reports-expansion-plan.md` Phase 2.3.
 *
 * Four claims, each a way this report could be plausibly and expensively wrong:
 *
 *  - **It is a balance, not a period.** An hour booked five months ago and never billed is the one worth
 *    seeing, and a month-scoped query is the one shape that would hide it while looking correct.
 *  - **It prices hours the way a billing run prices them**, or the balance sheet figure is not the money.
 *  - **It never guesses a rate.** Unpriceable hours are named and left out of the value, because a made-up
 *    rate makes this a wrong figure rather than an incomplete one — and `BillableHours` already holds that
 *    line for invoices.
 *  - **The gate closes when any module it reads is off** — which turns out to need no code, because the
 *    manifest's requirement walk already does it. Pinned anyway, since the report's cross-module safety now
 *    rests entirely on that recursion.
 *
 * The arithmetic of rates and approval belongs to `TimesheetTest`; none of it is restated here.
 */
class UnbilledWipReportTest extends TestCase
{
    use InteractsWithTenant;
    use RefreshDatabase;

    private const AS_OF = '2026-08-20';

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs(User::factory()->create(['status' => 1]));
        $this->setCurrentTenant();

        foreach (['timesheets', 'projects', 'employees', 'invoicing'] as $module) {
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

    private function employee(string $code, ?float $rate = null): Employee
    {
        return Employee::create([
            'employee_id' => $code,
            'name' => $code,
            'gender' => 'Male',
            'is_active' => true,
            'date_of_joining' => '2020-01-01',
            'hourly_rate' => $rate,
        ]);
    }

    private function project(string $name, ?float $rate = 5_000, ?int $contactId = null): Project
    {
        return Project::create([
            'name' => $name,
            'code' => strtoupper(substr(md5($name), 0, 6)),
            'hourly_rate' => $rate,
            'contact_id' => $contactId,
        ]);
    }

    private function customer(string $name): Contact
    {
        return Contact::create(['name' => $name, 'kind' => Contact::KIND_CUSTOMER]);
    }

    /** Approved and unbilled by default, which is what WIP is made of. */
    private function book(Employee $employee, Project $project, int $minutes, string $date, array $attributes = []): TimesheetEntry
    {
        return TimesheetEntry::create(array_merge([
            'employee_id' => $employee->id,
            'project_id' => $project->id,
            'date' => $date,
            'minutes' => $minutes,
            'is_billable' => true,
            'approved_at' => now(),
        ], $attributes));
    }

    /** @return array<string, mixed> */
    private function report(?string $asOf = null): array
    {
        $payload = ReportRenderers::render('UnbilledWip', $asOf ?? self::AS_OF, false, []);

        $this->assertNotNull($payload, 'no renderer is registered for UnbilledWip');

        return $payload;
    }

    private function cells(array $payload): string
    {
        return collect($payload['rows'])->flatten()->implode(' | ');
    }

    // ────────────────────────────────────────────────────────── the balance ──

    /**
     * Old hours are the point of the report, not an edge case.
     *
     * `billableFor()` answers per project per month, which is right for a billing run and would have made
     * this report show only the current month — quietly, and while looking entirely correct.
     */
    public function test_it_includes_hours_from_months_long_past(): void
    {
        $employee = $this->employee('EMP-1');
        $project = $this->project('Warehouse');

        $this->book($employee, $project, 600, '2026-03-04');   // 10 hours, five months old
        $this->book($employee, $project, 120, '2026-08-18');   // 2 hours, this month

        $payload = $this->report();

        $this->assertSame(12.0, $payload['tiles'][1]['value'], 'both months belong to the balance');
        $this->assertSame(60_000.0, $payload['tiles'][0]['value'], '12 hours at 5,000');
    }

    /** And nothing dated after the date it is read on. */
    public function test_it_stops_at_the_date(): void
    {
        $employee = $this->employee('EMP-1');
        $project = $this->project('Warehouse');

        $this->book($employee, $project, 120, '2026-08-18');
        $this->book($employee, $project, 600, '2026-09-01');

        $this->assertSame(2.0, $this->report()['tiles'][1]['value']);
    }

    /** An hour already on an invoice is not WIP. */
    public function test_a_billed_hour_is_not_wip(): void
    {
        $employee = $this->employee('EMP-1');
        $project = $this->project('Warehouse');

        $this->book($employee, $project, 120, '2026-08-01');
        $this->book($employee, $project, 600, '2026-08-02', ['locked_at' => now()]);

        $this->assertSame(2.0, $this->report()['tiles'][1]['value']);
    }

    /** Nor is non-billable time: it was never going to be invoiced. */
    public function test_non_billable_time_is_not_wip(): void
    {
        $employee = $this->employee('EMP-1');
        $project = $this->project('Warehouse');

        $this->book($employee, $project, 120, '2026-08-01');
        $this->book($employee, $project, 600, '2026-08-02', ['is_billable' => false]);

        $this->assertSame(2.0, $this->report()['tiles'][1]['value']);
    }

    /**
     * The approval rule is the company's, not this report's.
     *
     * With approval required, unapproved time cannot be billed — so a WIP figure including it would be a
     * figure no invoice could ever realise. With the requirement off, the same hours are billable and
     * belong in it. The report has to follow the setting either way.
     */
    public function test_it_follows_the_companys_approval_requirement(): void
    {
        $employee = $this->employee('EMP-1');
        $project = $this->project('Warehouse');

        $this->book($employee, $project, 600, '2026-08-01', ['approved_at' => null]);

        app(TenantSettings::class)->set('timesheets.require_approval_to_bill', true);
        $this->assertSame(0.0, $this->report()['tiles'][1]['value'], 'unapproved time cannot be billed');

        app(TenantSettings::class)->set('timesheets.require_approval_to_bill', false);
        $this->assertSame(10.0, $this->report()['tiles'][1]['value'], 'with approval not required it is billable');
    }

    // ─────────────────────────────────────────────────────────── the pricing ──

    /**
     * Hours with no rate are named and left out of the value.
     *
     * `BillableHours` holds this line for invoices — "named, never silently dropped and never billed at a
     * guess" — and a balance sheet figure has more to lose from a guess, not less. The hours still count as
     * hours, because they were worked.
     */
    public function test_unpriceable_hours_are_counted_as_hours_and_excluded_from_the_value(): void
    {
        $priced = $this->project('Warehouse', rate: 5_000);
        $unpriced = $this->project('Portal', rate: null);
        $employee = $this->employee('EMP-1', rate: null);

        $this->book($employee, $priced, 120, '2026-08-01');    // 2h at 5,000
        $this->book($employee, $unpriced, 300, '2026-08-02');  // 5h, no rate anywhere

        $payload = $this->report();

        $this->assertSame(7.0, $payload['tiles'][1]['value'], 'seven hours were worked');
        $this->assertSame(10_000.0, $payload['tiles'][0]['value'], 'only the priced two are worth anything');
        $this->assertStringContainsString('5.0 HOURS HAVE NO RATE AND ARE NOT IN THE VALUE', $payload['note']);
    }

    /** And the unpriced hours are attributed to the project they are on. */
    public function test_unpriceable_hours_are_shown_against_their_project(): void
    {
        $unpriced = $this->project('Portal', rate: null);
        $this->book($this->employee('EMP-1', rate: null), $unpriced, 300, '2026-08-02');

        $payload = $this->report();
        $row = collect($payload['rows'])->firstWhere(0, 'Portal');

        $this->assertNotNull($row);
        $this->assertSame('5.0', $row[2], 'hours');
        $this->assertSame('5.0', $row[3], 'unpriced hours');
        $this->assertSame('—', $row[4], 'no value, rather than a nought');
    }

    /** The rate chain is the billing one: the project first, then the employee, then the default. */
    public function test_it_prices_by_the_same_chain_a_billing_run_uses(): void
    {
        $employee = $this->employee('EMP-1', rate: 1_000);
        $withProjectRate = $this->project('Warehouse', rate: 5_000);
        $withoutProjectRate = $this->project('Portal', rate: null);

        $this->book($employee, $withProjectRate, 60, '2026-08-01');
        $this->book($employee, $withoutProjectRate, 60, '2026-08-02');

        $payload = $this->report();

        // The project's rate wins where it has one; the employee's is the fallback.
        $this->assertSame(6_000.0, $payload['tiles'][0]['value']);

        // And the service agrees, asked directly — so the report is not doing its own arithmetic.
        $this->assertSame(6_000.0, app(TimesheetService::class)->unbilledWip(self::AS_OF)['amount']);
    }

    /** A whole balance in one grouped query, not one per project. */
    public function test_it_does_not_query_per_project(): void
    {
        $employee = $this->employee('EMP-1');

        foreach (range(1, 10) as $i) {
            $this->book($employee, $this->project('Project '.$i), 60, '2026-08-0'.min(9, $i));
        }

        $queries = 0;
        DB::listen(function () use (&$queries): void {
            $queries++;
        });

        $payload = $this->report();

        $this->assertCount(10, $payload['rows']);
        $this->assertLessThanOrEqual(
            8,
            $queries,
            "the report ran {$queries} queries for ten projects, which is per-project rather than aggregate",
        );
    }

    // ────────────────────────────────────────────────────────── the customer ──

    /** The customer is named, read without importing Invoicing's model. */
    public function test_it_names_the_customer_behind_a_project(): void
    {
        $customer = $this->customer('Karachi Textiles');
        $project = $this->project('Warehouse', contactId: $customer->id);

        $this->book($this->employee('EMP-1'), $project, 120, '2026-08-01');

        $this->assertStringContainsString('Karachi Textiles', $this->cells($this->report()));
    }

    /** An internal project says so rather than leaving the cell blank. */
    public function test_an_internal_project_is_named_as_internal(): void
    {
        $this->book($this->employee('EMP-1'), $this->project('Website rebuild'), 120, '2026-08-01');

        $row = collect($this->report()['rows'])->firstWhere(0, 'Website rebuild');

        $this->assertSame('Internal', $row[1]);
    }

    /**
     * Without invoicing the report still works and the customer reads as an id.
     *
     * Invoicing is deliberately *not* a required module: a company may invoice elsewhere and still want to
     * know what is outstanding. Degrading here rather than gating is the difference between a shorter answer
     * and no answer.
     */
    public function test_without_invoicing_the_report_still_works(): void
    {
        $customer = $this->customer('Karachi Textiles');
        $project = $this->project('Warehouse', contactId: $customer->id);
        $this->book($this->employee('EMP-1'), $project, 120, '2026-08-01');

        $this->setModule('invoicing', false);

        $payload = $this->report();

        $this->assertSame(10_000.0, $payload['tiles'][0]['value'], 'the value is unaffected');
        $this->assertStringContainsString('Customer #'.$customer->id, $this->cells($payload));
        $this->assertStringNotContainsString('Karachi Textiles', $this->cells($payload));
    }

    // ──────────────────────────────────────────────────────── the ledger tie ──

    /** There is none, and the report says so. */
    public function test_it_says_the_balance_is_posted_nowhere(): void
    {
        $this->book($this->employee('EMP-1'), $this->project('Warehouse'), 120, '2026-08-01');

        $this->assertStringContainsString('NOT POSTED TO ANY ACCOUNT', $this->report()['note']);
    }

    /** Nothing outstanding is a sentence, not an empty grid. */
    public function test_it_says_when_nothing_is_waiting_to_be_billed(): void
    {
        $payload = $this->report();

        $this->assertSame([], $payload['rows']);
        $this->assertSame('NOTHING IS WAITING TO BE BILLED', $payload['note']);
    }

    /** The record row foots the rows above it. */
    public function test_the_record_row_foots_the_rows(): void
    {
        $employee = $this->employee('EMP-1');
        $this->book($employee, $this->project('Warehouse'), 120, '2026-08-01');
        $this->book($employee, $this->project('Portal', rate: 2_000), 300, '2026-08-02');

        $payload = $this->report();

        foreach ([2, 4] as $column) {
            $rows = array_sum(array_map(
                fn (array $row): float => (float) str_replace([',', '—'], ['', '0'], $row[$column]),
                $payload['rows'],
            ));

            $this->assertLessThanOrEqual(
                count($payload['rows']),
                abs((float) str_replace([',', '—'], ['', '0'], $payload['footer'][$column]) - $rows),
                "column {$column} does not add up the rows above it",
            );
        }
    }

    // ──────────────────────────────────────────────── the cross-module gate ──

    /**
     * The gate covers every module it reads, and the manifest is what makes that true.
     *
     * The plan's risk list asks that a cross-module report "gate on *every* module it reads, not just the
     * one it lives in", and this report was first built with a `$alsoRequires` list to do it. The list was
     * redundant, and this test is what established that: `Modules::enabledFor()` walks a module's declared
     * requirements recursively, so disabling Projects disables Timesheets, which closes the gate on its own.
     *
     * Pinned here rather than taken on trust, because the whole of the report's cross-module safety now
     * rests on it: if that recursion were ever removed, this page would open against a module the company
     * does not have and nothing else would notice.
     */
    public function test_the_gate_closes_when_a_module_it_reads_is_disabled(): void
    {
        Gate::before(fn () => true);

        $this->assertTrue(UnbilledWipPage::canAccess());

        // Its own module.
        $this->setModule('timesheets', false);
        $this->assertFalse(UnbilledWipPage::canAccess());
        $this->setModule('timesheets', true);

        // And the one it reads through a declared requirement, which is the case the risk list worried about
        // and the manifest already answers.
        $this->setModule('projects', false);
        $this->assertFalse(UnbilledWipPage::canAccess(), 'a report about projects must not open without them');
        $this->assertFalse(
            modules()->enabled('timesheets'),
            'and the reason is the requirement walk, not a check on the page',
        );
    }

    // ──────────────────────────────────────────── the page and the pane ──

    /** Two screens, one payload. */
    public function test_the_report_states_the_same_thing_on_its_page_as_in_the_pane(): void
    {
        Gate::before(fn () => true);
        $this->book($this->employee('EMP-1'), $this->project('Warehouse'), 120, '2026-08-01');

        $onThePage = Livewire::test(UnbilledWipPage::class, ['asOf' => self::AS_OF])
            ->assertSuccessful()
            ->instance()
            ->statement();

        $this->assertSame($onThePage, app(ReportPaneRenderer::class)->for('UnbilledWip', self::AS_OF, false, []));
    }

    /** And on `ReportView`. */
    public function test_the_report_is_gated_on_report_view(): void
    {
        $this->assertFalse(UnbilledWipPage::canAccess());
    }
}
