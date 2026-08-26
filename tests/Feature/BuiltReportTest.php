<?php

namespace Tests\Feature;

use App\Modules\Accounting\Support\ReportPane;
use App\Modules\Core\Filament\Pages\Reports;
use App\Modules\Core\Models\CompanyModule;
use App\Modules\Core\Models\ReportDefinition;
use App\Modules\Employees\Models\Employee;
use App\Modules\Employees\Reporting\EmployeeDataset;
use App\Modules\Invoicing\Models\Contact;
use App\Modules\Invoicing\Models\Invoice;
use App\Modules\Invoicing\Reporting\InvoiceDataset;
use App\Modules\Payroll\Models\Payslip;
use App\Modules\Payroll\Reporting\PayslipDataset;
use App\Support\Reporting\BuiltReport;
use App\Support\Reporting\DatasetColumn;
use App\Support\Reporting\RelativePeriod;
use App\Support\Reporting\ReportCatalogue;
use App\Support\Reporting\ReportExport;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\AccountingTestCase;
use Tests\Concerns\InteractsWithTenant;

/**
 * A saved definition, drawn — `docs/reports-expansion-plan.md` Phase 6, items 4 and 5.
 *
 * Item 4 is a claim about *inheritance* rather than about rendering: a built report is a `table` payload, so
 * it arrives in the pane, on the hub, in the CSV and in the PDF without any of those four being taught what a
 * custom report is. So the tests that matter here are the ones that would fail if it were a renderer of its
 * own — the pane draws it, the hub lists it, the exporter writes it — and then the two things a query
 * assembled from stored state must never do:
 *
 *  - **reach a row the dataset's own query would not.** `EmployeeAccess` is on the base query (item 2), and
 *    the test below is the plan's own example: a payslip report built by somebody with no downline shows
 *    their own row and nobody else's. Every seeded role holds `PayslipView`, so the permission gate does not
 *    refuse this subject to anybody — the row scoping is the whole of the defence, which is why it is tested
 *    here as well as against the dataset.
 *  - **cost whatever it happens to cost.** Item 5's ceiling refuses rather than truncating, and the reason
 *    that distinction earns a test of its own is the record row: a footer under the first thousand of nine
 *    thousand rows is a total that belongs to no visible set of figures.
 *
 * The state's own sanitisation is `ReportDefinitionTest`'s and the registry's boundary is
 * `DatasetRegistryTest`'s. Neither is restated; what is tested here is what the *query* does with a state
 * both of those have already agreed is legal.
 */
class BuiltReportTest extends AccountingTestCase
{
    use InteractsWithTenant;

    /** A Friday in February of the 2026-2027 financial year, so the calendar year differs from the fiscal one. */
    private const AS_OF = '2027-02-19';

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(self::AS_OF.' 09:00:00');

        $this->actingAs($this->makeUser('Administrator', 'builder@test.local'));
        $this->setCurrentTenant();

        foreach (array_keys(config('modules', [])) as $module) {
            CompanyModule::updateOrCreate(
                ['company_id' => $this->tenant->getKey(), 'module' => $module],
                ['licensed' => true, 'enabled' => true],
            );
        }

        modules()->flush();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    // ─────────────────────────────────────────────── the shape it is drawn in ──

    /**
     * The columns are the definition's, in its order, and the payload is a `table`.
     *
     * The order is the assertion rather than the set: `columns` is the report's shape, and a builder that
     * returned the dataset's declaration order instead would look right on every report whose columns happen
     * to be declared in the order somebody picked them.
     */
    public function test_a_definition_is_drawn_as_a_table_in_its_own_column_order(): void
    {
        $this->invoice('INV-1', 12000, ['memo' => 'First']);

        $report = $this->render($this->definition([
            'columns' => ['total', 'invoice_number', 'invoice_date'],
            'period' => RelativePeriod::YEAR_TO_DATE,
        ]));

        $this->assertSame('table', $report['kind']);
        $this->assertSame('A report', $report['title']);
        $this->assertSame(['Total', 'Invoice', 'Date'], $report['columns']);
        $this->assertSame([['12,000', 'INV-1', '10 Aug 2026']], $report['rows']);

        // Figures are right-aligned by index, which is the only thing the view has to go on.
        $this->assertSame([0], $report['numeric']);
    }

    /** Each type is formatted the way the coded reports format it, and a null cell is an em dash. */
    public function test_cells_are_formatted_by_their_declared_type(): void
    {
        $this->invoice('INV-2', 4500.4, ['due_date' => null, 'memo' => null]);

        $report = $this->render($this->definition([
            'columns' => ['invoice_date', 'total', 'due_date', 'memo'],
        ]));

        $this->assertSame([['10 Aug 2026', '4,500', '—', '—']], $report['rows']);
    }

    /**
     * A relative period resolves against the date the pane carries, not against today.
     *
     * Which is what makes a built report linkable in the same way every coded report is: `?asOf=` is the
     * whole of "as at", and a definition that stored two dates instead could not answer to it at all.
     */
    public function test_the_period_resolves_against_the_panes_own_date(): void
    {
        $this->invoice('INV-JAN', 1000, ['invoice_date' => '2027-01-15']);
        $this->invoice('INV-FEB', 2000, ['invoice_date' => '2027-02-15']);

        $definition = $this->definition([
            'columns' => ['invoice_number'],
            'period' => RelativePeriod::LAST_MONTH,
        ]);

        // Read on 19 February, "last month" is January.
        $this->assertSame([['INV-JAN']], $this->render($definition)['rows']);

        // Read on 15 March, the same definition is February's report.
        $this->assertSame([['INV-FEB']], $this->render($definition, '2027-03-15')['rows']);
    }

    /**
     * A period is inclusive of its last day, whatever time of day the row carries.
     *
     * `between '2027-01-01' and '2027-01-31'` excludes everything stamped *during* 31 January on a datetime
     * column, which is half the date columns in this application. This report's own column is a date, so the
     * assertion is that the last day is in — the form that gets it right for both is in `BuiltReport`.
     */
    public function test_the_last_day_of_a_period_is_included(): void
    {
        $this->invoice('INV-LAST', 500, ['invoice_date' => '2027-01-31']);

        $report = $this->render($this->definition([
            'columns' => ['invoice_number'],
            'period' => RelativePeriod::LAST_MONTH,
        ]));

        $this->assertSame([['INV-LAST']], $report['rows']);
    }

    /** A subject with no period says so rather than implying one it did not apply. */
    public function test_a_subject_with_no_period_says_every_row(): void
    {
        $report = $this->render(ReportDefinition::put('Headcount', EmployeeDataset::class, [
            'columns' => ['name'],
            'period' => RelativePeriod::LAST_MONTH,
        ]));

        $this->assertStringContainsString('every row', $report['subtitle']);
        $this->assertStringContainsString('EVERY ROW', $report['note']);
        $this->assertStringNotContainsString('LAST MONTH', $report['note']);
    }

    // ──────────────────────────────────────────────────────────── the filters ──

    /** A stored select narrows the rows, and the note says it did. */
    public function test_a_stored_filter_narrows_the_rows_and_is_named(): void
    {
        $this->invoice('INV-SALE', 1000);
        $this->invoice('INV-BUY', 2000, ['kind' => Invoice::KIND_PURCHASE]);

        $report = $this->render($this->definition([
            'columns' => ['invoice_number'],
            'filters' => ['kind' => Invoice::KIND_SALE],
        ]));

        $this->assertSame([['INV-SALE']], $report['rows']);
        $this->assertStringContainsString('KIND: SALE', $report['note']);
    }

    /** A search looks in every column it declared, which is the point of declaring more than one. */
    public function test_a_search_filter_looks_in_each_of_its_columns(): void
    {
        $this->invoice('INV-AAA', 1000, ['memo' => 'nothing here']);
        $this->invoice('INV-BBB', 2000, ['memo' => 'crane hire']);

        $definition = $this->definition([
            'columns' => ['invoice_number'],
            'filters' => ['find' => 'crane'],
        ]);

        $this->assertSame([['INV-BBB']], $this->render($definition)['rows']);

        // The number as well as the memo, from the same filter.
        $definition = $this->definition([
            'columns' => ['invoice_number'],
            'filters' => ['find' => 'AAA'],
        ], name: 'By number');

        $this->assertSame([['INV-AAA']], $this->render($definition)['rows']);
    }

    /**
     * A second date range is relative too, so a definition never holds a date.
     *
     * Phase 6.2 kept absolute dates out of the *period* because Phase 8 will send these on a schedule. A
     * filter is the same hazard wearing a different label — "invoices due in April" filed in April is a
     * report about April for ever — so the same mechanism answers both.
     */
    public function test_a_date_range_filter_is_relative_as_well(): void
    {
        // Inside "this month", which runs to the day being read rather than to the end of the month.
        $this->invoice('INV-DUE-FEB', 1000, ['invoice_date' => '2026-08-10', 'due_date' => '2027-02-10']);
        $this->invoice('INV-DUE-DEC', 2000, ['invoice_date' => '2026-08-10', 'due_date' => '2026-12-25']);

        $definition = $this->definition([
            'columns' => ['invoice_number'],
            'filters' => ['due' => RelativePeriod::THIS_MONTH],
        ]);

        $this->assertSame([['INV-DUE-FEB']], $this->render($definition)['rows']);

        // And two dates cannot be stored at all — the sanitiser drops the pair rather than filtering on it.
        $this->assertSame([], $this->definition([
            'columns' => ['invoice_number'],
            'filters' => ['due' => ['from' => '2027-02-01', 'to' => '2027-02-28']],
        ], name: 'Two dates')->settings()['filters']);
    }

    // ────────────────────────────────────────────────────────── the record row ──

    /**
     * The record row totals what the database could have totalled, and nothing else.
     *
     * `outstanding` is `total - amount_paid` in PHP — Phase 6.1 declared it derived precisely so that
     * "outstanding by customer" stays the coded ageing report. Adding it up here in PHP is how that refusal
     * would be quietly undone, so the blank cell under it is the assertion.
     */
    public function test_the_record_row_totals_only_the_columns_that_can_be_totalled(): void
    {
        $this->invoice('INV-A', 1000, ['amount_paid' => 400]);
        $this->invoice('INV-B', 2500, ['amount_paid' => 500]);

        $report = $this->render($this->definition([
            'columns' => ['invoice_number', 'total', 'outstanding'],
        ]));

        $this->assertSame(['Total — 2 rows', '3,500', ''], $report['footer']);

        // And the tile leads with the same figure the footer states, rather than a second reading of it.
        $this->assertSame(['ROWS', 'TOTAL'], array_column($report['tiles'], 'label'));
        $this->assertSame(3500.0, $report['tiles'][1]['value']);
    }

    /** No rows, no record row — a total of nothing is not a fact worth stating. */
    public function test_an_empty_report_has_no_record_row(): void
    {
        $report = $this->render($this->definition(['columns' => ['invoice_number', 'total']]));

        $this->assertSame([], $report['rows']);
        $this->assertNull($report['footer']);
        $this->assertStringContainsString('0 ROWS', $report['note']);
    }

    // ─────────────────────────────────────────────────────────── the grouping ──

    /**
     * A grouped report buckets in SQL and prints the related name.
     *
     * Both halves are Phase 6.1's design: the database groups on `contact_id`, because `GROUP BY
     * contacts.name` would need a join this builder does not write, and the pane prints the contact's name
     * because a report of foreign keys answers nothing.
     */
    public function test_a_grouped_report_aggregates_in_sql_and_names_its_buckets(): void
    {
        $acme = $this->customer('Acme');
        $globex = $this->customer('Globex');

        $this->invoice('INV-1', 1000, ['contact_id' => $acme->id]);
        $this->invoice('INV-2', 2000, ['contact_id' => $acme->id]);
        $this->invoice('INV-3', 4000, ['contact_id' => $globex->id]);

        $statements = [];
        DB::listen(function ($query) use (&$statements): void {
            $statements[] = $query->sql;
        });

        $report = $this->render($this->definition([
            'columns' => ['contact', 'total'],
            'group_by' => 'contact',
            'aggregates' => ['total' => DatasetColumn::SUM, 'invoice_number' => DatasetColumn::COUNT],
        ]));

        $this->assertSame(['Contact', 'Total (sum)', 'Invoice (count)'], $report['columns']);
        $this->assertSame([
            ['Acme', '3,000', '2'],
            ['Globex', '4,000', '1'],
        ], $report['rows']);

        $this->assertSame(['Total — 2 groups', '7,000', '3'], $report['footer']);

        // The grouping happened in the database rather than in PHP, which is what item 5 asks for: three rows
        // were never loaded to make two.
        $this->assertTrue(
            collect($statements)->contains(fn (string $sql): bool => str_contains(mb_strtolower($sql), 'group by')),
            'the report did not group in SQL',
        );
    }

    /**
     * A footer adds up sums and counts, and leaves an average alone.
     *
     * A total of averages is not an average of anything. It would be a number, which is the whole danger:
     * nobody reading a record row checks whether the column above it was addable.
     */
    public function test_a_grouped_footer_does_not_add_up_an_average(): void
    {
        $acme = $this->customer('Acme');
        $this->invoice('INV-1', 1000, ['contact_id' => $acme->id]);
        $this->invoice('INV-2', 3000, ['contact_id' => $acme->id]);

        $report = $this->render($this->definition([
            'columns' => ['contact', 'total'],
            'group_by' => 'contact',
            'aggregates' => ['total' => DatasetColumn::AVG],
        ]));

        $this->assertSame([['Acme', '2,000']], $report['rows']);
        $this->assertSame(['Total — 1 group', ''], $report['footer']);
    }

    /** A bucket with nothing in the column is named rather than blank. */
    public function test_an_empty_bucket_is_called_none(): void
    {
        $this->invoice('INV-1', 1000);

        $report = $this->render($this->definition([
            'columns' => ['project', 'total'],
            'group_by' => 'project',
            'aggregates' => ['total' => DatasetColumn::SUM],
        ]));

        $this->assertSame([['None', '1,000']], $report['rows']);
    }

    // ────────────────────────────────────────────── the ceiling, and refusing ──

    /**
     * Over the ceiling the report refuses, and says what to do about it — item 5.
     *
     * Refusing rather than truncating is what keeps the record row honest: the total under a table is the
     * total *of that table*, which is only true because a report that draws at all has drawn every row it
     * matched. So the assertions are both halves — no rows, and no footer either.
     */
    public function test_a_report_over_the_ceiling_refuses_rather_than_truncating(): void
    {
        $this->manyInvoices(BuiltReport::MAX_ROWS + 1);

        $report = $this->render($this->definition(['columns' => ['invoice_number', 'total']]));

        $this->assertSame([], $report['rows']);
        $this->assertNull($report['footer']);
        $this->assertStringContainsString('1,000', $report['empty']);
        $this->assertStringContainsString('Narrow the period', $report['empty']);

        // The pane draws a note it is told is unbalanced in warning colour, which is how a report says
        // something about itself that its rows cannot.
        $this->assertFalse($report['balanced']);

        // The columns are still the ones asked for: this is the report, with an explanation where the rows
        // would be, rather than an error.
        $this->assertSame(['Invoice', 'Total'], $report['columns']);

        // And there is nothing to export, so both buttons hide themselves rather than writing an empty file.
        $this->assertFalse(app(ReportExport::class)->supports($report));
    }

    /** At the ceiling exactly, it draws. */
    public function test_a_report_at_the_ceiling_still_draws(): void
    {
        $this->manyInvoices(BuiltReport::MAX_ROWS);

        $report = $this->render($this->definition(['columns' => ['invoice_number']]));

        $this->assertCount(BuiltReport::MAX_ROWS, $report['rows']);
        $this->assertTrue($report['balanced']);
    }

    /** A definition nobody has finished says so, rather than reading as an empty period. */
    public function test_a_definition_with_no_columns_says_so(): void
    {
        $this->invoice('INV-1', 1000);

        $report = $this->render($this->definition(['columns' => ['nonsense']]));

        $this->assertSame([], $report['columns']);
        $this->assertStringContainsString('no columns yet', $report['empty']);
        $this->assertSame('NOTHING CHOSEN YET', $report['note']);
    }

    // ─────────────────────────────────────────────── what it inherits, unasked ──

    /** The pane draws a built report, which is the whole of item 4. */
    public function test_the_pane_draws_a_built_report(): void
    {
        $this->invoice('INV-1', 1000);

        $definition = $this->definition(['columns' => ['invoice_number', 'total']]);

        $this->assertTrue(ReportPane::supports($definition->reportKey()));

        $pane = app(ReportPane::class)->for($definition->reportKey(), self::AS_OF);

        $this->assertNotNull($pane);
        $this->assertSame('A report', $pane['title']);
        $this->assertSame([['INV-1', '1,000']], $pane['rows']);
    }

    /**
     * And so does a company with no accounting module.
     *
     * `NoReportPane` is what such a company is served, and a custom report belongs to Core — which is always
     * on. A built report that needed the accounting module would be a licensing bug found by the one customer
     * it applies to.
     */
    public function test_a_company_with_no_accounting_draws_a_built_report(): void
    {
        $this->invoice('INV-1', 1000);

        $definition = $this->definition(['columns' => ['invoice_number']]);
        $pane = new \App\Support\Reporting\NoReportPane;

        $this->assertTrue($pane->supportsReport($definition->reportKey()));
        $this->assertSame([['INV-1']], $pane->for($definition->reportKey(), self::AS_OF)['rows']);
    }

    /** The hub lists it, in the one section no module registers into. */
    public function test_the_hub_lists_it_in_the_custom_section(): void
    {
        $definition = $this->definition(['columns' => ['invoice_number']], name: 'My sales');

        $sections = Reports::sections();

        $this->assertArrayHasKey(ReportCatalogue::CUSTOM, $sections);
        $this->assertSame('My sales', $sections[ReportCatalogue::CUSTOM][0]['label']);
        $this->assertSame($definition->reportKey(), $sections[ReportCatalogue::CUSTOM][0]['key']);

        // The section is last, because the coded reports are the ones a company shares.
        $this->assertSame(ReportCatalogue::CUSTOM, array_key_last($sections));

        // In the catalogue, so `select()` accepts it and `ReportPaneTest`'s coverage loop covers it.
        $this->assertArrayHasKey($definition->reportKey(), Reports::catalogue());

        // A definition with no description of its own still reads as a row rather than a gap.
        $this->assertStringContainsString('Invoices', $sections[ReportCatalogue::CUSTOM][0]['description']);
    }

    /** With nothing saved there is no heading, exactly as an unlicensed module leaves none. */
    public function test_a_company_with_no_definitions_has_no_custom_section(): void
    {
        $this->assertArrayNotHasKey(ReportCatalogue::CUSTOM, Reports::sections());
    }

    /** Selecting it in the explorer draws it in the pane, from the key alone. */
    public function test_the_explorer_opens_a_built_report(): void
    {
        $this->invoice('INV-1', 7500);

        $definition = $this->definition(['columns' => ['invoice_number', 'total']], name: 'Sales list');

        Livewire::test(Reports::class, ['asOf' => self::AS_OF])
            ->call('select', $definition->reportKey())
            ->assertSet('selected', $definition->reportKey())
            ->assertSee('Sales list')
            ->assertSee('7,500');
    }

    /** A key naming no definition is refused by the same check that refuses an unknown report. */
    public function test_a_key_that_names_no_definition_is_refused(): void
    {
        Livewire::test(Reports::class)
            ->call('select', 'custom-9999')
            ->assertSet('selected', null);

        $this->assertNull(app(BuiltReport::class)->forKey('custom-9999', self::AS_OF));
        $this->assertNull(app(BuiltReport::class)->forKey('custom-nonsense', self::AS_OF));
        $this->assertNull(ReportDefinition::idFromKey('custom-0'));
    }

    /** Somebody else's unshared report is not in their colleague's hub, and not drawable either. */
    public function test_another_persons_private_report_is_neither_listed_nor_drawn(): void
    {
        $mine = $this->definition(['columns' => ['invoice_number']], name: 'Mine');

        $this->actingAs($this->makeUser('Administrator', 'colleague@test.local'));
        ReportDefinition::forgetReadable();

        $this->assertArrayNotHasKey(ReportCatalogue::CUSTOM, Reports::sections());
        $this->assertArrayNotHasKey($mine->reportKey(), Reports::catalogue());
        $this->assertNull(app(BuiltReport::class)->forKey($mine->reportKey(), self::AS_OF));
    }

    /** A report over a module this company has switched off draws nothing, for anybody. */
    public function test_a_report_over_a_disabled_module_draws_nothing(): void
    {
        $definition = $this->definition(['columns' => ['invoice_number']]);

        CompanyModule::updateOrCreate(
            ['company_id' => $this->tenant->getKey(), 'module' => 'invoicing'],
            ['licensed' => true, 'enabled' => false],
        );
        modules()->flush();
        ReportDefinition::forgetReadable();

        $this->assertNull(app(BuiltReport::class)->forKey($definition->reportKey(), self::AS_OF));
        $this->assertArrayNotHasKey(ReportCatalogue::CUSTOM, Reports::sections());
    }

    /** Phase 4's export writes it, because it is a `table` like every other report. */
    public function test_the_export_writes_a_built_report(): void
    {
        $this->invoice('INV-1', 1000);
        $this->invoice('INV-2', 2000);

        $report = $this->render($this->definition([
            'columns' => ['invoice_number', 'total'],
        ], name: 'Sales list'));

        $export = app(ReportExport::class);

        $this->assertTrue($export->supports($report));

        $csv = $export->csv($report);

        $this->assertStringContainsString('Invoice,Total', $csv);
        $this->assertStringContainsString('INV-1', $csv);
        // The CSV undoes the display formatting, as Phase 4.1 decided: a spreadsheet gets a number.
        $this->assertStringContainsString('2000', $csv);
        $this->assertSame('sales-list-2027-02-19.csv', $export->filename($report, self::AS_OF, 'csv'));
    }

    // ─────────────────────────────────────────────────────── the security half ──

    /**
     * Row-level access is the dataset's, and the builder cannot be the way around it.
     *
     * The plan's own example, and Phase 6.1 found why it is the sharp one: every seeded role that can read a
     * report holds `PayslipView`, so the permission gate refuses payslips to nobody. What keeps a payslip
     * report from being a payroll leak is `EmployeeAccess` on the dataset's base query — and this test is the
     * proof that the *rendered* report inherits it rather than building a query of its own.
     */
    public function test_a_built_report_over_payslips_shows_only_the_readers_own_rows(): void
    {
        $employee = $this->employeeFor('staff@test.local', 'Own record');
        $other = Employee::create([
            'employee_id' => 'E-OTHER',
            'name' => 'Somebody else',
            'gender' => 'Male',
            'is_active' => true,
            'date_of_joining' => '2026-07-01',
        ]);

        $this->payslip($employee, 'January');
        $this->payslip($other, 'January');

        // An Administrator sees both. The column is the employee's name rather than a figure on purpose:
        // what a payslip's net pay comes to is `PayslipTest`'s subject, and this one is about whose rows
        // arrive.
        $definition = ReportDefinition::put('Payslips', PayslipDataset::class, [
            'columns' => ['employee', 'month'],
        ], isPublic: true);

        $this->assertSame(
            [['Own record', 'January'], ['Somebody else', 'January']],
            collect($this->render($definition)['rows'])->sortBy(0)->values()->all(),
        );

        // The employee whose payslip it is sees one row: their own.
        $this->actingAs(Employee::find($employee->getKey())->user);
        ReportDefinition::forgetReadable();

        $report = app(BuiltReport::class)->forKey($definition->reportKey(), self::AS_OF);

        $this->assertNotNull($report, 'the reader holds PayslipView, so the subject must resolve');
        $this->assertSame([['Own record', 'January']], $report['rows']);
    }

    // ─────────────────────────────────────────────────────────────── fixtures ──

    /** @param  array<string, mixed>  $state */
    private function definition(array $state, string $name = 'A report'): ReportDefinition
    {
        $definition = ReportDefinition::put($name, InvoiceDataset::class, $state);

        $this->assertNotNull($definition, 'the definition was refused');

        return $definition;
    }

    /** @return array<string, mixed> */
    private function render(ReportDefinition $definition, ?string $asOf = null): array
    {
        $report = app(BuiltReport::class)->for($definition, $asOf ?? self::AS_OF);

        $this->assertNotNull($report, 'the definition drew nothing');

        return $report;
    }

    private function customer(string $name): Contact
    {
        return Contact::create(['name' => $name, 'kind' => Contact::KIND_CUSTOMER]);
    }

    /** @param  array<string, mixed>  $attributes */
    private function invoice(string $number, float $total, array $attributes = []): Invoice
    {
        return Invoice::create(array_merge([
            'invoice_number' => $number,
            'kind' => Invoice::KIND_SALE,
            'status' => Invoice::STATUS_ISSUED,
            'contact_id' => ($attributes['contact_id'] ?? null) ?: $this->customer('Customer '.$number)->id,
            'invoice_date' => '2026-08-10',
            'subtotal' => $total,
            'total' => $total,
        ], $attributes));
    }

    /**
     * Enough invoices to reach the ceiling, in one statement.
     *
     * Inserted rather than created: a thousand model saves is a thousand statements and this test is about a
     * limit, not about the model's own behaviour, which `InvoiceTest` owns.
     */
    private function manyInvoices(int $count): void
    {
        $contact = $this->customer('Bulk');
        $rows = [];

        for ($i = 1; $i <= $count; $i++) {
            $rows[] = [
                'invoice_number' => 'BULK-'.$i,
                'kind' => Invoice::KIND_SALE,
                'status' => Invoice::STATUS_ISSUED,
                'contact_id' => $contact->id,
                'invoice_date' => '2026-08-10',
                'subtotal' => 100,
                'total' => 100,
                'created_at' => now(),
                'updated_at' => now(),
            ];
        }

        foreach (array_chunk($rows, 250) as $chunk) {
            Invoice::insert($chunk);
        }
    }

    private function employeeFor(string $email, string $name): Employee
    {
        $user = $this->makeUser('Employee', $email);

        return Employee::create([
            'employee_id' => 'E-'.mb_substr(md5($email), 0, 5),
            'name' => $name,
            'gender' => 'Male',
            'is_active' => true,
            'date_of_joining' => '2026-07-01',
            'user_id' => $user->getKey(),
        ]);
    }

    private function payslip(Employee $employee, string $month): Payslip
    {
        return Payslip::create([
            'employee_id' => $employee->getKey(),
            'fiscal_year_id' => $this->fiscalYear->getKey(),
            'month' => $month,
            'total_working_days' => 22,
            'paid_days' => 22,
        ]);
    }
}
