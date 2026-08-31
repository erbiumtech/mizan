<?php

namespace Tests\Feature;

use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\JournalEntry;
use App\Modules\Accounting\Services\FinancialReportService;
use App\Modules\Accounting\Services\JournalEntryService;
use App\Modules\Accounting\Services\LedgerDimensionReport;
use App\Modules\Accounting\Support\ReportPane;
use App\Modules\Core\Models\CompanyModule;
use App\Modules\Employees\Models\Employee;
use App\Modules\Invoicing\Models\Contact;
use App\Modules\Invoicing\Models\Invoice;
use App\Modules\Invoicing\Models\InvoiceLine;
use App\Modules\Invoicing\Services\InvoiceService;
use App\Modules\Projects\Models\Project;
use App\Support\LedgerDimensions;
use Illuminate\Support\Carbon;
use Tests\AccountingTestCase;
use Tests\Concerns\InteractsWithTenant;

/**
 * Dimensions read from what produced each posting — `docs/erpnext-gap-plan.md` Phase 1.
 *
 * The gap plan's finding was that `journal_entries` has carried a `source` morph since it was created and
 * that almost nothing filled it in: 57% of a demo company's entries had none, because only depreciation,
 * payroll and the year-end closer ever passed one. Everything here is that column being populated and read.
 *
 * Three claims, and the third is the one that would make the report untrustworthy if it broke:
 *
 *  - **a posting records the document that produced it** — one key in a header each path already built;
 *  - **the dimension is derived, not stored** — an invoice knows its project, so an entry that knows the
 *    invoice knows the project, and `journal_entry_lines` gains no column;
 *  - **the split totals to the profit and loss.** Every posted income and expense line lands in exactly one
 *    bucket, so this report and the statement are the same number seen two ways. A management report that
 *    quietly disagrees with the statutory one is worse than no management report.
 *
 * The registry's own boundary — that Accounting names no module and each module registers its own
 * documents — is `ModuleBoundaryTest`'s to enforce, and it does.
 */
class LedgerDimensionsTest extends AccountingTestCase
{
    use InteractsWithTenant;

    /** February of the 2026-2027 financial year, which runs 1 July to 30 June. */
    private const AS_OF = '2027-02-19';

    protected function setUp(): void
    {
        parent::setUp();

        // Single-database suite: drop the DB-switch task, as ModuleEnforcementTest and ScheduledReportTest
        // do. `accounting:backfill-entry-sources` is TenantAware, so it makes each company current.
        config(['multitenancy.switch_tenant_tasks' => [
            \App\Multitenancy\Tasks\SetPermissionsTeamIdTask::class,
        ]]);

        Carbon::setTestNow(self::AS_OF.' 09:00:00');

        $this->actingAs($this->makeUser('Administrator', 'dimensions@test.local'));
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

    // ───────────────────────────────────────────── the column, filled in ──

    /** An invoice's posting records the invoice, which is Phase 1's first item in one assertion. */
    public function test_an_invoice_posting_records_the_invoice(): void
    {
        $invoice = $this->issuedInvoice('INV-1', 50_000);

        $entry = JournalEntry::query()->latest('id')->firstOrFail();

        $this->assertSame($invoice->getKey(), (int) $entry->source_id);
        // The alias, never the live class name: a model that moves directories must not orphan its
        // postings, which is the same rule `TableView` and `ReportDefinition` follow.
        $this->assertSame(\App\Support\ModuleMap::alias(Invoice::class), $entry->source_type);
        $this->assertTrue($entry->source->is($invoice));
    }

    /** And so does the settlement, because a receipt is the second half of that invoice's story. */
    public function test_a_settlement_records_the_invoice_it_settles(): void
    {
        $invoice = $this->issuedInvoice('INV-2', 10_000);

        app(InvoiceService::class)->recordPayment($invoice, 4_000, '2027-02-10');

        $settlement = JournalEntry::query()->latest('id')->firstOrFail();

        $this->assertStringContainsString('Payment against', (string) $settlement->memo);
        $this->assertTrue($settlement->source->is($invoice));
    }

    /**
     * A manual entry has no source and is not given one.
     *
     * The named ceiling of the whole design: nothing produced it but a person. Inventing an attribution
     * here would be the guess the gap plan refuses — and the report shows it as *Unassigned* instead.
     */
    public function test_a_manual_entry_keeps_no_source(): void
    {
        $entry = $this->manualEntry('4200', '5100', 1_000, 'Accrued interest');

        $this->assertNull($entry->source_type);
        $this->assertNull($entry->source);
    }

    // ─────────────────────────────────────────── dimensions off the source ──

    /** The dimensions of an invoice are the invoice's own: its project and its customer. */
    public function test_an_invoices_dimensions_are_its_project_and_its_customer(): void
    {
        $project = Project::create(['name' => 'Harbour Bridge', 'code' => 'HB1']);
        $invoice = $this->issuedInvoice('INV-3', 25_000, $project);

        $dimensions = LedgerDimensions::of($invoice);

        $this->assertSame('Harbour Bridge', $dimensions[LedgerDimensions::PROJECT]);
        $this->assertSame('Customer INV-3', $dimensions[LedgerDimensions::PARTY]);
        // An invoice knows no department, and says null rather than inventing one.
        $this->assertNull($dimensions[LedgerDimensions::DEPARTMENT]);
    }

    /** A source nothing registered resolves to nothing rather than throwing. */
    public function test_an_unregistered_source_resolves_to_nothing(): void
    {
        $employee = $this->employee('Rehana', 'Operations');

        $this->assertSame(
            [LedgerDimensions::PROJECT => null, LedgerDimensions::PARTY => null, LedgerDimensions::DEPARTMENT => null],
            LedgerDimensions::of($employee),
            'nothing registers Employee as a source, so it has no dimensions — and must not error',
        );

        $this->assertSame(LedgerDimensions::UNASSIGNED, LedgerDimensions::bucket($employee, LedgerDimensions::PROJECT));
        $this->assertSame(LedgerDimensions::UNASSIGNED, LedgerDimensions::bucket(null, LedgerDimensions::PROJECT));
    }

    // ──────────────────────────────────────────────────────── the report ──

    /** The profit splits by project, and what cannot be split says so. */
    public function test_the_profit_splits_by_project(): void
    {
        $bridge = Project::create(['name' => 'Harbour Bridge', 'code' => 'HB1']);
        $tower = Project::create(['name' => 'Clock Tower', 'code' => 'CT1']);

        $this->issuedInvoice('INV-A', 30_000, $bridge);
        $this->issuedInvoice('INV-B', 20_000, $tower);
        $this->manualEntry('1100', '4200', 5_000, 'Sundry income nobody attributed');

        $report = app(LedgerDimensionReport::class)->for(LedgerDimensions::PROJECT, self::AS_OF);

        $buckets = collect($report['rows'])->pluck('income', 'bucket')->all();

        $this->assertSame(30_000.0, $buckets['Harbour Bridge']);
        $this->assertSame(20_000.0, $buckets['Clock Tower']);
        $this->assertSame(5_000.0, $buckets[LedgerDimensions::UNASSIGNED]);

        // Unassigned is last, and deliberately not sorted among the projects as though it were one.
        $this->assertSame(LedgerDimensions::UNASSIGNED, collect($report['rows'])->last()['bucket']);
    }

    /**
     * The split totals to the profit and loss for the same dates.
     *
     * The assertion that makes the report worth having. Every posted income and expense line lands in
     * exactly one bucket, so the two reports are the same figure seen two ways — and if a posting path
     * ever starts writing a line the split cannot see, this fails rather than the two quietly diverging.
     */
    public function test_the_split_totals_to_the_profit_and_loss(): void
    {
        $bridge = Project::create(['name' => 'Harbour Bridge', 'code' => 'HB1']);

        $this->issuedInvoice('INV-C', 40_000, $bridge);
        $this->issuedInvoice('INV-D', 15_000);
        $this->manualEntry('5100', '1100', 6_000, 'Rent, typed by hand');

        $period = \App\Support\Reporting\ReportPeriod::toDate(self::AS_OF);

        $statement = app(FinancialReportService::class)->profitAndLoss($period['from'], $period['to']);

        $split = app(LedgerDimensionReport::class)->for(LedgerDimensions::PROJECT, self::AS_OF);

        $this->assertSame(
            round((float) $statement['net_profit'], 2),
            round((float) $split['totals']['profit'], 2),
            'the dimension split and the profit and loss disagree',
        );
    }

    /** Two dimensions over one ledger: the same money, grouped differently. */
    public function test_the_same_ledger_reads_by_department(): void
    {
        $this->issuedInvoice('INV-E', 12_000);

        $byProject = app(LedgerDimensionReport::class)->for(LedgerDimensions::PROJECT, self::AS_OF);
        $byDepartment = app(LedgerDimensionReport::class)->for(LedgerDimensions::DEPARTMENT, self::AS_OF);

        $this->assertSame($byProject['totals']['profit'], $byDepartment['totals']['profit']);

        // An invoice knows no department, so the whole of it is unassigned on that axis — which is the
        // honest answer and exactly what the report has to show rather than hide.
        $this->assertSame(
            [LedgerDimensions::UNASSIGNED],
            collect($byDepartment['rows'])->pluck('bucket')->all(),
        );
    }

    /** The pane draws it, asks for the dimension, and offers the three there are. */
    public function test_the_pane_draws_it_and_offers_the_dimension_picker(): void
    {
        $this->issuedInvoice('INV-F', 9_000);

        $this->assertSame(['dimension'], ReportPane::asks('ProfitAndLossByDimension'));
        $this->assertSame(LedgerDimensions::LABELS, app(ReportPane::class)->options('ProfitAndLossByDimension', 'dimension'));

        $payload = app(ReportPane::class)->for('ProfitAndLossByDimension', self::AS_OF, false, [
            'dimension' => LedgerDimensions::PROJECT,
        ]);

        $this->assertNotNull($payload);
        $this->assertSame('table', $payload['kind']);
        $this->assertSame('P&L by Project', $payload['title']);
        $this->assertSame(['Project', 'Income', 'Expense', 'Profit'], $payload['columns']);
    }

    /** A dimension the registry does not know falls back rather than drawing an empty report. */
    public function test_an_unknown_dimension_falls_back_to_project(): void
    {
        $this->issuedInvoice('INV-G', 3_000);

        $report = app(LedgerDimensionReport::class)->for('nonsense', self::AS_OF);

        $this->assertSame(LedgerDimensions::PROJECT, $report['dimension']);
    }

    // ─────────────────────────────────────────────────────── the backfill ──

    /**
     * History is attributed from the documents that point at it, and never guessed.
     *
     * The entry is stripped of its source to stand for the ledger as it was before Phase 1 — which is what
     * every company running this application has.
     */
    public function test_the_backfill_attributes_history_from_the_documents(): void
    {
        $invoice = $this->issuedInvoice('INV-H', 7_000);
        $entry = JournalEntry::query()->latest('id')->firstOrFail();

        $entry->forceFill(['source_type' => null, 'source_id' => null])->save();
        $manual = $this->manualEntry('5100', '1100', 500, 'Typed by a person');

        $this->artisan('accounting:backfill-entry-sources', ['--tenant' => [$this->tenant->getKey()]])
            ->assertSuccessful();

        $this->assertTrue($entry->refresh()->source->is($invoice));
        // And the one nothing points at is left alone: a guessed source is worse than none.
        $this->assertNull($manual->refresh()->source_type);
    }

    /** A dry run reports and writes nothing. */
    public function test_the_backfill_can_report_without_writing(): void
    {
        $this->issuedInvoice('INV-I', 2_000);
        $entry = JournalEntry::query()->latest('id')->firstOrFail();
        $entry->forceFill(['source_type' => null, 'source_id' => null])->save();

        $this->artisan('accounting:backfill-entry-sources', [
            '--dry-run' => true,
            '--tenant' => [$this->tenant->getKey()],
        ])->assertSuccessful();

        $this->assertNull($entry->refresh()->source_type);
    }

    /**
     * An entry nothing stamped is still protected from being edited in the register.
     *
     * Which is why `JournalEntryOwners` survives Phase 1 rather than being deleted, as
     * `docs/module-packaging-plan.md` §8 Group B wanted once every path stamped a source. Stamping is
     * forward-looking and the backfill is a command somebody runs, so a company that upgrades and does not
     * run it has years of postings with a null source — and those are the ones with the most to lose.
     */
    public function test_an_unattributed_entry_is_still_guarded_by_the_owners_registry(): void
    {
        $invoice = $this->issuedInvoice('INV-J', 1_500);
        $entry = JournalEntry::query()->latest('id')->firstOrFail();

        $entry->forceFill(['source_type' => null, 'source_id' => null])->save();

        $this->assertSame(
            'an invoice',
            \App\Support\JournalEntryOwners::ownerOf($entry->id)['label'] ?? null,
        );
    }

    // ───────────────────────────────────────────────────────── fixtures ──

    private function issuedInvoice(string $number, float $amount, ?Project $project = null): Invoice
    {
        $contact = Contact::create(['name' => 'Customer '.$number, 'kind' => Contact::KIND_CUSTOMER]);

        $invoice = Invoice::create([
            'invoice_number' => $number,
            'kind' => Invoice::KIND_SALE,
            'status' => Invoice::STATUS_DRAFT,
            'contact_id' => $contact->id,
            'project_id' => $project?->getKey(),
            'invoice_date' => '2027-02-01',
            'due_date' => '2027-03-01',
            'subtotal' => $amount,
            'total' => $amount,
        ]);

        InvoiceLine::create([
            'invoice_id' => $invoice->id,
            'description' => 'Consultancy',
            'quantity' => 1,
            'unit_price' => $amount,
            'line_total' => $amount,
        ]);

        return app(InvoiceService::class)->issue($invoice->refresh());
    }

    /** A posting with no document behind it — the case the whole design is honest about. */
    private function manualEntry(string $debit, string $credit, float $amount, string $memo): JournalEntry
    {
        $entries = app(JournalEntryService::class);

        $entry = $entries->create([
            'entry_date' => '2027-02-05',
            'entry_type' => 'general',
            'memo' => $memo,
        ], [
            ['account_id' => Account::where('code', $debit)->firstOrFail()->id, 'debit_amount' => $amount],
            ['account_id' => Account::where('code', $credit)->firstOrFail()->id, 'credit_amount' => $amount],
        ]);

        $entry->update(['status' => JournalEntry::STATUS_APPROVED, 'approved_at' => now()]);

        return $entries->post($entry);
    }

    private function employee(string $name, string $department): Employee
    {
        return Employee::create([
            'employee_id' => 'E-'.mb_substr(md5($name), 0, 5),
            'name' => $name,
            'gender' => 'Female',
            'is_active' => true,
            'date_of_joining' => '2026-07-01',
            'department' => $department,
        ]);
    }
}
