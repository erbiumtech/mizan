<?php

namespace Tests\Feature;

use App\Modules\Core\Models\CompanyModule;
use App\Modules\Core\Models\FiscalYear;
use App\Modules\Core\Models\ReportDefinition;
use App\Modules\Crm\Reporting\OpportunityDataset;
use App\Modules\Employees\Reporting\EmployeeDataset;
use App\Modules\Invoicing\Reporting\InvoiceDataset;
use App\Modules\Payroll\Reporting\PayslipDataset;
use App\Support\Reporting\DatasetColumn;
use App\Support\Reporting\RelativePeriod;
use Illuminate\Support\Carbon;
use Tests\AccountingTestCase;
use Tests\Concerns\InteractsWithTenant;

/**
 * Saved report definitions — `docs/reports-expansion-plan.md` Phase 6, items 3 and 6.
 *
 * Three things are worth testing here and the third is the one that matters:
 *
 *  - **the state is sanitised against the dataset**, on write and on read. A definition is a query, so the
 *    set of definitions that can exist has to be the set of queries the registry can answer — a column key
 *    the subject does not declare, an aggregate the column does not allow, a sort direction that is not one
 *    of two words: all dropped;
 *  - **the period is relative and never two dates**, which is Phase 4.5's lesson applied one level up. A
 *    definition holding "1 April to 30 June" answers last quarter's question for ever, and Phase 8 will
 *    *send* these on a schedule, where that is not a wrong number but a wrong report;
 *  - **sharing cannot leak**, which is item 6's sharp end. Reading a definition resolves its subject through
 *    the *reader's* module licence and permission, so a shared report over a subject somebody cannot open
 *    resolves to nothing for them — no columns, no query. That is a stronger guarantee than being careful
 *    with the toggle.
 *
 * And a finding that changes how item 6 should be read: **payslips, the example the plan names, are the one
 * subject that gate does not protect.** Every seeded role that can read a report at all holds `PayslipView`,
 * so a shared payslip report passes the reader gate for all of them — and what makes it safe is entirely
 * Phase 6.1's item 2, the `EmployeeAccess` scoping on the dataset's own query. The two halves are not
 * overlapping defences; for most subjects the permission refuses, and for the one the plan was worried about
 * it is the row scoping and nothing else.
 */
class ReportDefinitionTest extends AccountingTestCase
{
    use InteractsWithTenant;

    /** A Friday in the third quarter of a July-starting financial year. */
    private const TODAY = '2027-02-19';

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(self::TODAY.' 09:00:00');

        $this->actingAs($this->makeUser('Administrator', 'author@test.local'));
        $this->setCurrentTenant();

        foreach (array_keys(config('modules', [])) as $module) {
            CompanyModule::updateOrCreate(
                ['company_id' => $this->tenant->getKey(), 'module' => $module],
                ['licensed' => true, 'enabled' => true],
            );
        }

        modules()->flush();

        FiscalYear::firstOrCreate(
            ['name' => '2026-2027'],
            ['start_date' => '2026-07-01', 'end_date' => '2027-06-30', 'is_active' => true],
        );
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    // ─────────────────────────────── what a definition is ──

    /** A definition stores the dataset's alias, never its class name. */
    public function test_the_dataset_is_stored_as_an_alias(): void
    {
        $definition = $this->definition(['columns' => ['invoice_number', 'total']]);

        $this->assertSame(InvoiceDataset::key(), $definition->dataset);
        $this->assertNotSame(InvoiceDataset::class, $definition->dataset, 'the class name was stored');
        $this->assertSame(InvoiceDataset::class, $definition->dataset());
    }

    /** Saving over a name replaces it rather than growing a second report called the same thing. */
    public function test_saving_over_a_name_replaces_it(): void
    {
        $this->definition(['columns' => ['invoice_number']], name: 'Sales');
        $this->definition(['columns' => ['total']], name: 'Sales');

        $this->assertSame(1, ReportDefinition::query()->count());
        $this->assertSame(['total'], ReportDefinition::query()->first()->settings()['columns']);
    }

    // ─────────────────────────────── the state is the dataset's ──

    /** A column the dataset does not declare is dropped. */
    public function test_an_undeclared_column_is_dropped(): void
    {
        $settings = $this->definition([
            'columns' => ['invoice_number', 'salary', 'contact_id', 'total'],
        ])->settings();

        // `contact_id` is a real database column and not a declared key, which is the distinction that makes
        // this a boundary rather than a spelling check.
        $this->assertSame(['invoice_number', 'total'], $settings['columns']);
    }

    /** The order is kept, because the order is the report's shape, and repeats are not. */
    public function test_columns_keep_their_order_without_repeats(): void
    {
        $settings = $this->definition([
            'columns' => ['total', 'invoice_number', 'total', 'contact'],
        ])->settings();

        $this->assertSame(['total', 'invoice_number', 'contact'], $settings['columns']);
    }

    /** A filter the dataset does not offer is dropped, and so is one with nothing in it. */
    public function test_filters_are_the_datasets_own_and_not_blank(): void
    {
        $settings = $this->definition([
            'columns' => ['total'],
            'filters' => ['status' => 'paid', 'nonsense' => 'x', 'kind' => '', 'find' => null],
        ])->settings();

        $this->assertSame(['status' => 'paid'], $settings['filters']);
    }

    /**
     * A group-by must be a column the database can group on.
     *
     * Which rules out anything derived and anything reached through a relation without a local key —
     * `outstanding` is `total - amount_paid` in PHP, so grouping by it would be grouping by a column that does
     * not exist.
     */
    public function test_a_group_by_must_be_groupable(): void
    {
        $this->assertNull($this->definition([
            'columns' => ['total'],
            'group_by' => 'outstanding',
        ])->settings()['group_by']);

        $this->assertSame('contact', $this->definition([
            'columns' => ['total'],
            'group_by' => 'contact',
        ], name: 'By customer')->settings()['group_by']);
    }

    /** An aggregate the column does not allow is dropped. */
    public function test_an_aggregate_must_be_one_the_column_allows(): void
    {
        $settings = $this->definition([
            'columns' => ['total', 'status'],
            'aggregates' => [
                'total' => DatasetColumn::SUM,
                'status' => DatasetColumn::SUM,        // a sum of statuses
                'outstanding' => DatasetColumn::SUM,   // derived, so nothing but a count
                'total' => DatasetColumn::SUM,
            ],
        ])->settings();

        $this->assertSame(['total' => DatasetColumn::SUM], $settings['aggregates']);
    }

    /** A derived column can still be counted, which is the one aggregate it offers. */
    public function test_a_derived_column_can_be_counted(): void
    {
        $settings = $this->definition([
            'columns' => ['outstanding'],
            'aggregates' => ['outstanding' => DatasetColumn::COUNT],
        ])->settings();

        $this->assertSame(['outstanding' => DatasetColumn::COUNT], $settings['aggregates']);
    }

    /**
     * A sort direction is one of two words.
     *
     * The only part of a definition that reaches SQL structure directly — `orderBy` is where Eloquent will
     * pass a string through — so it is the one place where "whatever was stored" would be a real hazard rather
     * than a wrong report.
     */
    public function test_a_sort_direction_is_one_of_two_words(): void
    {
        $this->assertSame(
            ['column' => 'total', 'direction' => 'asc'],
            $this->definition([
                'columns' => ['total'],
                'sort' => ['column' => 'total', 'direction' => 'desc; drop table invoices'],
            ])->settings()['sort'],
        );

        $this->assertSame(
            ['column' => 'total', 'direction' => 'desc'],
            $this->definition([
                'columns' => ['total'],
                'sort' => ['column' => 'total', 'direction' => 'desc'],
            ], name: 'Biggest first')->settings()['sort'],
        );
    }

    /** A sort on a column the dataset does not declare is no sort at all. */
    public function test_a_sort_on_an_undeclared_column_is_dropped(): void
    {
        $this->assertNull($this->definition([
            'columns' => ['total'],
            'sort' => ['column' => 'id', 'direction' => 'asc'],
        ])->settings()['sort']);
    }

    /** Nothing outside the six keys survives. */
    public function test_only_the_declared_keys_are_stored(): void
    {
        $definition = $this->definition([
            'columns' => ['total'],
            'limit' => 100000,
            'raw' => 'select * from payslips',
        ]);

        $this->assertSame(ReportDefinition::KEYS, array_keys($definition->settings()));
    }

    /**
     * The state is sanitised on the way out as well as in.
     *
     * A row outlives the code that wrote it: a definition naming a column since removed from its dataset must
     * lose that column rather than hand it to a query builder. Written past the model to prove the read path
     * cleans up rather than only the write path.
     */
    public function test_the_state_is_sanitised_on_read(): void
    {
        $definition = $this->definition(['columns' => ['total']]);

        $definition->forceFill(['state' => [
            'columns' => ['total', 'a_column_that_was_deleted'],
            'sort' => ['column' => 'gone', 'direction' => 'desc'],
            'period' => 'the_reign_of_edward_vii',
        ]])->saveQuietly();

        $settings = $definition->fresh()->settings();

        $this->assertSame(['total'], $settings['columns']);
        $this->assertNull($settings['sort']);
        $this->assertSame(RelativePeriod::YEAR_TO_DATE, $settings['period']);
    }

    // ─────────────────────────────── the period is relative ──

    /**
     * A definition holds a relative span, and reading it on any day gives that day's answer.
     *
     * Phase 4.5 kept the date out of a saved view because "a view holding 30 June would open on 30 June for
     * ever and nobody would notice for a while". A definition is worse, because Phase 8 will send it.
     */
    public function test_the_period_is_resolved_when_it_is_read(): void
    {
        $definition = $this->definition([
            'columns' => ['total'],
            'period' => RelativePeriod::LAST_MONTH,
        ]);

        $this->assertSame(
            ['from' => '2027-01-01', 'to' => '2027-01-31'],
            $definition->range(),
        );

        // The same definition, read in a different month, is a different span.
        $this->assertSame(
            ['from' => '2027-06-01', 'to' => '2027-06-30'],
            $definition->range('2027-07-14'),
        );
    }

    /** There is no way to store two dates, which is what makes the above true. */
    public function test_a_definition_cannot_hold_absolute_dates(): void
    {
        $settings = $this->definition([
            'columns' => ['total'],
            'period' => ['from' => '2026-04-01', 'to' => '2026-06-30'],
        ])->settings();

        $this->assertSame(RelativePeriod::YEAR_TO_DATE, $settings['period']);
    }

    /** Quarters and years are financial, because the accounts are. */
    public function test_the_spans_are_financial(): void
    {
        $this->assertSame(
            ['from' => '2027-01-01', 'to' => self::TODAY],
            RelativePeriod::range(RelativePeriod::THIS_QUARTER, self::TODAY),
        );

        $this->assertSame(
            ['from' => '2026-10-01', 'to' => '2026-12-31'],
            RelativePeriod::range(RelativePeriod::LAST_QUARTER, self::TODAY),
        );

        $this->assertSame(
            ['from' => '2026-07-01', 'to' => self::TODAY],
            RelativePeriod::range(RelativePeriod::YEAR_TO_DATE, self::TODAY),
        );

        $this->assertSame(
            ['from' => '2025-07-01', 'to' => '2026-06-30'],
            RelativePeriod::range(RelativePeriod::LAST_YEAR, self::TODAY),
        );
    }

    /**
     * Every span is bounded, deliberately.
     *
     * Half of item 5's cost guard arriving for free: there is no "all time" to choose, because an unbounded
     * report is the one thing a builder must not be able to ask for and a list of choices is the honest place
     * to prevent it.
     */
    public function test_no_span_is_unbounded(): void
    {
        foreach (array_keys(RelativePeriod::PERIODS) as $period) {
            $range = RelativePeriod::range($period, self::TODAY);

            $this->assertNotEmpty($range['from'], $period.' has no start');
            $this->assertNotEmpty($range['to'], $period.' has no end');
            $this->assertTrue(
                Carbon::parse($range['from'])->lte(Carbon::parse($range['to'])),
                $period.' runs backwards',
            );
        }
    }

    /**
     * Last quarter on a **short first financial year** is the quarter the company actually had.
     *
     * `FiscalYear` enforces a 30 June end, so a company that joined in November has an eight-month first year
     * — and its quarters are still July–September, October–December and so on, because the quarter grid is a
     * property of the year end rather than of when somebody signed up. Read in February, "this quarter" starts
     * on 1 January and "last quarter" is 1 November to 31 December: their short first quarter, clamped to the
     * day they arrived.
     *
     * Written because subtracting three months looks equivalent and is not: it would answer 1 October, a month
     * before this company existed. A mutation survived until this test existed.
     */
    public function test_last_quarter_on_a_short_first_year_starts_when_the_company_did(): void
    {
        // Shortened rather than replaced: the seeded years are referenced by other seeded rows, so deleting
        // them fails a foreign key. Moving the start is the state under test anyway — a company that joined
        // in November keeps the 30 June end `FiscalYear` enforces.
        FiscalYear::query()
            ->where('name', '2026-2027')
            ->update(['start_date' => '2026-11-01']);

        $this->assertSame(
            ['from' => '2027-01-01', 'to' => '2027-02-15'],
            RelativePeriod::range(RelativePeriod::THIS_QUARTER, '2027-02-15'),
        );

        $this->assertSame(
            ['from' => '2026-11-01', 'to' => '2026-12-31'],
            RelativePeriod::range(RelativePeriod::LAST_QUARTER, '2027-02-15'),
        );
    }

    /** Anything unrecognised is the financial year to date rather than an error or an empty span. */
    public function test_an_unknown_period_falls_back(): void
    {
        foreach ([null, '', 'last_fortnight', 'THIS_MONTH'] as $bad) {
            $this->assertSame(RelativePeriod::YEAR_TO_DATE, RelativePeriod::normalise($bad));
        }
    }

    // ─────────────────────────────── who may build, share and read ──

    /**
     * Building needs `ReportBuild`.
     *
     * Over **payslips**, which looks odd and is the only way to test this: an Employee holds `PayslipView`, so
     * that subject resolves for them and `ReportBuild` is the one thing left refusing. Handing them invoices
     * instead would be refused by the subject gate before this check was reached, and the test would pass with
     * the permission check deleted — a mutation proved exactly that.
     */
    public function test_building_needs_the_permission(): void
    {
        $this->actingAs($this->makeUser('Employee', 'employee@test.local'));

        $this->assertNotNull(PayslipDataset::isAvailable() ? true : null, 'the subject must resolve, or this tests the wrong gate');
        $this->assertNull(ReportDefinition::put('Mine', PayslipDataset::class, ['columns' => ['net_salary']]));
        $this->assertSame(0, ReportDefinition::query()->count());
    }

    /**
     * Sharing needs `ReportShare`, and is refused rather than quietly dropped.
     *
     * An Accountant may build — they read the ledger all day — and may not make a report the company's.
     * Refused rather than saved-unshared on purpose: somebody who ticked the box should be told, and returning
     * null is the only way the caller can tell them.
     */
    public function test_sharing_needs_its_own_permission(): void
    {
        $this->actingAs($this->makeUser('Accountant', 'accountant@test.local'));

        $this->assertNotNull(ReportDefinition::put('Mine', InvoiceDataset::class, ['columns' => ['total']]));
        $this->assertNull(
            ReportDefinition::put('Shared', InvoiceDataset::class, ['columns' => ['total']], isPublic: true),
            'an Accountant shared a report with the whole company',
        );
    }

    /** A definition over a subject the author may not report on is refused. */
    public function test_a_definition_over_a_forbidden_subject_is_refused(): void
    {
        $this->actingAs($this->makeUser('Accountant', 'accountant2@test.local'));

        // An Accountant reads the ledger and holds no `OpportunityView`, so the sales pipeline is not a
        // subject they may build over — while invoices are.
        $this->assertNull(ReportDefinition::put('Pipeline', OpportunityDataset::class, ['columns' => ['title']]));
        $this->assertNotNull(ReportDefinition::put('Sales', InvoiceDataset::class, ['columns' => ['total']]));
    }

    /** Somebody else's private report is not in my list; a shared one is. */
    public function test_visibility_is_mine_plus_whatever_is_shared(): void
    {
        $mine = $this->definition(['columns' => ['total']], name: 'Mine');

        $other = $this->makeUser('Administrator', 'other@test.local');
        $this->actingAs($other);

        $theirs = $this->definition(['columns' => ['total']], name: 'Theirs');
        $shared = $this->definition(['columns' => ['total']], name: 'Shared', isPublic: true);

        $this->actingAs($this->makeUser('Administrator', 'author2@test.local'));

        $names = ReportDefinition::query()->visibleTo()->pluck('name')->all();

        $this->assertSame(['Shared'], $names);
        $this->assertNotNull($theirs);
        $this->assertNotNull($mine);
    }

    /**
     * **A shared report cannot show somebody figures they could not already open.**
     *
     * Item 6's sharp end, and the guarantee that makes the sharing toggle safe rather than merely careful:
     * reading a definition resolves its subject through the *reader's* module licence and the *reader's*
     * permission. An administrator who shares a pipeline report company-wide has shared a report that
     * resolves to nothing for everybody who may not open an opportunity — no columns, no query, no rows.
     */
    public function test_a_shared_report_resolves_to_nothing_for_somebody_who_cannot_read_the_subject(): void
    {
        $shared = ReportDefinition::put(
            'Everybody\'s pipeline',
            OpportunityDataset::class,
            ['columns' => ['title', 'amount']],
            isPublic: true,
        );

        $this->assertNotNull($shared, 'the administrator could not share it, so this test proves nothing');
        $this->assertNotNull($shared->dataset());

        // An Accountant: reads reports all day, holds no OpportunityView.
        $this->actingAs($this->makeUser('Accountant', 'accountant3@test.local'));

        $this->assertNotNull(
            ReportDefinition::query()->visibleTo()->where('name', 'Everybody\'s pipeline')->first(),
            'the row is not even visible, which would make the next assertion prove nothing',
        );

        $this->assertNull($shared->fresh()->dataset(), 'a shared report resolved for somebody without the permission');
        $this->assertSame([], $shared->fresh()->settings()['columns']);
        $this->assertSame([], ReportDefinition::readable()->all(), 'and it is not in the readable list');
    }

    /**
     * **Payslips are the plan's example and are the one subject this gate does not protect**, which is worth
     * recording rather than glossing.
     *
     * Item 6 says "a company-wide custom report over payslips is a payroll leak, and it is one careless toggle
     * away". In this application every seeded role that can read a report at all holds `PayslipView` — an
     * employee sees their own payslip, an accountant runs payroll — so the reader gate above lets a shared
     * payslip report through for all of them. What stops it being a leak is entirely Phase 6.1's item 2: the
     * dataset's base query applies `EmployeeAccess`, so each reader gets their own rows and their downline's.
     *
     * Which means the two halves are not interchangeable defences that happen to overlap. For most subjects
     * the permission is what refuses; for the one the plan was worried about, it is the row scoping and
     * nothing else. `DatasetRegistryTest` is where that scoping is asserted.
     */
    public function test_a_shared_payslip_report_is_protected_by_the_row_scoping_rather_than_the_permission(): void
    {
        $shared = ReportDefinition::put(
            'Everybody\'s payroll',
            PayslipDataset::class,
            ['columns' => ['employee', 'net_salary']],
            isPublic: true,
        );

        $this->assertNotNull($shared);

        $this->actingAs($this->makeUser('Accountant', 'accountant4@test.local'));

        $this->assertNotNull(
            $shared->fresh()->dataset(),
            'if this is ever null, a role stopped holding PayslipView and this test should become the ordinary one above',
        );
    }

    /** And a definition whose module has been switched off resolves to nothing either. */
    public function test_a_definition_over_a_disabled_module_resolves_to_nothing(): void
    {
        $definition = $this->definition(['columns' => ['total']]);

        CompanyModule::updateOrCreate(
            ['company_id' => $this->tenant->getKey(), 'module' => 'invoicing'],
            ['licensed' => false, 'enabled' => false],
        );
        modules()->flush();

        $this->assertNull($definition->fresh()->dataset());
        $this->assertSame([], ReportDefinition::readable()->all());
    }

    /** The readable list is what anything user-facing should read: visible *and* resolvable. */
    public function test_the_readable_list_is_visible_and_resolvable(): void
    {
        $this->definition(['columns' => ['total']], name: 'Invoices');
        ReportDefinition::put('Payroll', PayslipDataset::class, ['columns' => ['net_salary']]);

        $this->assertSame(['Invoices', 'Payroll'], ReportDefinition::readable()->pluck('name')->all());

        CompanyModule::updateOrCreate(
            ['company_id' => $this->tenant->getKey(), 'module' => 'payroll'],
            ['licensed' => false, 'enabled' => false],
        );
        modules()->flush();

        $this->assertSame(['Invoices'], ReportDefinition::readable()->pluck('name')->all());
    }

    /** A definition over a dataset that no longer exists at all resolves to nothing rather than throwing. */
    public function test_a_definition_over_a_deleted_dataset_resolves_to_nothing(): void
    {
        $definition = $this->definition(['columns' => ['total']]);

        $definition->forceFill(['dataset' => 'App\\Reporting\\DatasetThatWasDeleted'])->saveQuietly();

        $this->assertNull($definition->fresh()->dataset());
        $this->assertSame(ReportDefinition::empty(), $definition->fresh()->settings());
    }

    /** Employees are a subject with no period, and a definition over one still stores a span harmlessly. */
    public function test_a_subject_with_no_period_still_stores_one(): void
    {
        $definition = ReportDefinition::put('Headcount', EmployeeDataset::class, [
            'columns' => ['name', 'department'],
            'period' => RelativePeriod::LAST_MONTH,
        ]);

        // Kept rather than refused: the period is applied by whatever renders the report, and a subject with
        // no period column simply has nothing to apply it to. Storing it means a dataset that gains a date
        // later does not need every definition over it rewritten.
        $this->assertSame(RelativePeriod::LAST_MONTH, $definition->settings()['period']);
        $this->assertNull(EmployeeDataset::periodColumn());
    }

    /** @param array<string, mixed> $state */
    private function definition(array $state, string $name = 'A report', bool $isPublic = false): ReportDefinition
    {
        return ReportDefinition::put($name, InvoiceDataset::class, $state, isPublic: $isPublic);
    }
}
