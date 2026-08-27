<?php

namespace Tests\Feature;

use App\Modules\Core\Filament\Pages\Reports;
use App\Modules\Core\Models\CompanyModule;
use App\Modules\Core\Models\ReportDefinition;
use App\Modules\Invoicing\Models\Contact;
use App\Modules\Invoicing\Models\Invoice;
use App\Modules\Invoicing\Reporting\InvoiceDataset;
use App\Modules\Payroll\Reporting\PayslipDataset;
use App\Support\Reporting\DatasetColumn;
use App\Support\Reporting\RelativePeriod;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\AccountingTestCase;
use Tests\Concerns\InteractsWithTenant;

/**
 * The builder screen — `docs/reports-expansion-plan.md` Phase 6, item 7.
 *
 * **A mode of the Reports hub rather than a screen of its own**, which is Phase 7's arranger decision applied
 * to the other half of the plan: item 4 already put a built report in the pane so that it inherits the pane,
 * and a builder anywhere else would have to reproduce the pane to show what it was building. So what is
 * tested here is the mode — it opens, it draws the report it is assembling, it saves under a name, it renames
 * without duplicating, and it is not there at all for somebody without `ReportBuild`.
 *
 * Two of these tests are about refusals rather than about features, and they are the ones worth having:
 *
 *  - **the form offers only what a dataset declared.** There is no field here through which a column name, a
 *    table or a relation reaches a query, which is why the registry needs no validator behind it. The test
 *    asserts the negative — a column key the subject does not declare, posted straight at the property, does
 *    not survive the save.
 *  - **somebody else's report is not editable.** A shared report belongs to whoever made it; editing it would
 *    change what every reader sees, and `put()`'s `$replacing` is scoped to the owner for that reason.
 *
 * The state's own sanitisation is `ReportDefinitionTest`'s and the rendering is `BuiltReportTest`'s. Neither
 * is restated.
 */
class ReportBuilderTest extends AccountingTestCase
{
    use InteractsWithTenant;

    private const AS_OF = '2027-02-19';

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(self::AS_OF.' 09:00:00');

        $this->actingAs($this->makeUser('Administrator', 'author@test.local'));
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

    // ──────────────────────────────────────────────────────────── the mode ──

    /** The mode opens, and the form offers the subjects this reader may build over. */
    public function test_the_builder_opens_and_offers_the_subjects(): void
    {
        Livewire::test(Reports::class)
            ->call('startBuilding')
            ->assertSet('building', true)
            ->assertSee('New report')
            ->assertSee('One subject per report')
            // The subject picker's own options, grouped by module.
            ->assertSee('Invoices')
            ->assertSee('Payslips');
    }

    /**
     * Item 7's refusal is on the screen, not only in the plan.
     *
     * "A question that needs two subjects joined is a coded report. The builder's answer to it is a clear
     * refusal, not a join it cannot secure." A refusal nobody reads is a refusal that gets raised as a
     * missing feature, so it is stated where somebody would go looking for the join.
     */
    public function test_the_builder_says_a_report_is_over_one_subject(): void
    {
        Livewire::test(Reports::class)
            ->call('startBuilding')
            ->assertSee('A report is over one subject')
            ->assertSee('is a coded report rather than a built one');
    }

    /**
     * Nothing is rendered, and nothing can be started, without `ReportBuild`.
     *
     * A purpose-built role rather than a seeded one, because no seeded role has this shape: `ReportBuild`
     * goes to Accountant and upward — "anybody trusted to read the ledger is trusted to ask it a question" —
     * and the roles below that cannot open the hub at all, which would test the wrong refusal. A company that
     * grants `ReportView` to a Sales role and nothing else is the real case here.
     */
    public function test_somebody_without_the_permission_cannot_build(): void
    {
        $reader = $this->makeUser('Employee', 'reader-only@test.local');

        $role = Role::create([
            'name' => 'Report reader',
            'guard_name' => 'web',
            'company_id' => $this->tenant->getKey(),
        ]);
        $role->givePermissionTo('ReportView');

        $reader->syncRoles([$role]);

        $this->actingAs($reader);

        Livewire::test(Reports::class)
            ->assertDontSee('New report')
            ->call('startBuilding')
            ->assertSet('building', false);

        $this->assertSame(0, ReportDefinition::query()->count());
    }

    /** The share box is only offered to somebody who may share. */
    public function test_the_share_box_needs_the_share_permission(): void
    {
        Livewire::test(Reports::class)
            ->call('startBuilding')
            ->set('draft.dataset', InvoiceDataset::key())
            ->assertSee('Share with everybody in this company');

        // An Accountant may build but not share — `ReportShare` is Administrator's alone.
        $this->actingAs($this->makeUser('Accountant', 'books@test.local'));

        Livewire::test(Reports::class)
            ->call('startBuilding')
            ->set('draft.dataset', InvoiceDataset::key())
            ->assertDontSee('Share with everybody in this company');
    }

    // ─────────────────────────────────────────────────── assembling a report ──

    /** The report being assembled is drawn in the pane, from the same renderer as a saved one. */
    public function test_the_pane_draws_the_report_being_assembled(): void
    {
        $this->invoice('INV-1', 4200);

        Livewire::test(Reports::class, ['asOf' => self::AS_OF])
            ->call('startBuilding')
            ->set('draft.dataset', InvoiceDataset::key())
            ->set('draft.name', 'Sales list')
            ->call('toggleColumn', 'invoice_number')
            ->call('toggleColumn', 'total')
            ->assertSee('INV-1')
            ->assertSee('4,200')
            // The title follows the name as it is typed, because the preview *is* the report.
            ->assertSee('Sales list');
    }

    /** Columns are kept in the order they were chosen, and can be moved. */
    public function test_columns_keep_the_order_they_were_chosen_in(): void
    {
        $component = Livewire::test(Reports::class)
            ->call('startBuilding')
            ->set('draft.dataset', InvoiceDataset::key())
            ->call('toggleColumn', 'total')
            ->call('toggleColumn', 'invoice_number');

        $component->assertSet('draft.columns', ['total', 'invoice_number']);

        // Which is the order the report draws them in — the declaration order is the other one.
        $component->call('moveColumn', 'invoice_number', -1)
            ->assertSet('draft.columns', ['invoice_number', 'total']);

        // And off the ends it does nothing rather than losing a column.
        $component->call('moveColumn', 'invoice_number', -1)
            ->assertSet('draft.columns', ['invoice_number', 'total']);

        $component->call('toggleColumn', 'total')
            ->assertSet('draft.columns', ['invoice_number']);
    }

    /** Changing the subject clears what was chosen against the old one. */
    public function test_changing_the_subject_clears_the_columns(): void
    {
        Livewire::test(Reports::class)
            ->call('startBuilding')
            ->set('draft.dataset', InvoiceDataset::key())
            ->set('draft.name', 'Half a report')
            ->call('toggleColumn', 'total')
            ->set('draft.dataset', PayslipDataset::key())
            ->assertSet('draft.columns', [])
            // The name is prose rather than a key of the old subject, so it survives.
            ->assertSet('draft.name', 'Half a report');
    }

    /** A subject with no period says so instead of offering a picker that would do nothing. */
    public function test_a_subject_with_no_period_offers_no_period_picker(): void
    {
        Livewire::test(Reports::class)
            ->call('startBuilding')
            ->set('draft.dataset', PayslipDataset::key())
            ->assertSee('this subject has no date to bound');
    }

    /** Totals per group are offered only once there is a group to total per. */
    public function test_the_totals_section_appears_only_when_grouping(): void
    {
        $component = Livewire::test(Reports::class)
            ->call('startBuilding')
            ->set('draft.dataset', InvoiceDataset::key())
            ->assertDontSee('Totals per group');

        $component->set('draft.group_by', 'contact')
            ->assertSee('Totals per group')
            ->assertSee('A grouped report shows the group and its totals');
    }

    // ─────────────────────────────────────────────────────────── saving it ──

    /** Saving keeps the report, closes the form, and opens what was just built. */
    public function test_saving_keeps_the_report_and_opens_it(): void
    {
        $this->invoice('INV-1', 1000);

        $component = Livewire::test(Reports::class, ['asOf' => self::AS_OF])
            ->call('startBuilding')
            ->set('draft.dataset', InvoiceDataset::key())
            ->set('draft.name', 'Sales this year')
            ->set('draft.description', 'What we billed')
            ->set('draft.period', RelativePeriod::YEAR_TO_DATE)
            ->call('toggleColumn', 'invoice_number')
            ->call('saveReport')
            ->assertSet('building', false);

        $definition = ReportDefinition::query()->firstOrFail();

        $this->assertSame('Sales this year', $definition->name);
        $this->assertSame(InvoiceDataset::key(), $definition->dataset);
        $this->assertSame(['invoice_number'], $definition->settings()['columns']);
        $this->assertFalse($definition->is_public);

        // Opened, because the thing somebody just built is the thing they want to read.
        $component->assertSet('selected', $definition->reportKey())->assertSee('INV-1');
    }

    /** A report with no name, or no subject, is refused with a sentence rather than saved half-built. */
    public function test_a_report_needs_a_name_and_a_subject(): void
    {
        Livewire::test(Reports::class)
            ->call('startBuilding')
            ->set('draft.dataset', InvoiceDataset::key())
            ->call('saveReport')
            ->assertSet('building', true)
            ->assertNotified('A report needs a name and a subject.');

        $this->assertSame(0, ReportDefinition::query()->count());
    }

    /**
     * The form is not the boundary — the registry is.
     *
     * A column key the subject does not declare, written straight at the property the way a crafted request
     * would, is dropped on the way in. Which is Phase 6.1's design working: nothing accepts a column *name*,
     * only a key the dataset turns back into the one column it declared.
     */
    public function test_a_column_the_subject_does_not_declare_does_not_survive_the_save(): void
    {
        Livewire::test(Reports::class)
            ->call('startBuilding')
            ->set('draft.dataset', InvoiceDataset::key())
            ->set('draft.name', 'Crafted')
            // `contact_id` is a real database column and not a declared key, which is the distinction.
            ->set('draft.columns', ['invoice_number', 'contact_id', 'password'])
            ->call('saveReport');

        $this->assertSame(['invoice_number'], ReportDefinition::query()->firstOrFail()->settings()['columns']);
    }

    /** Editing one of mine loads it, and saving a new name renames it rather than making a second. */
    public function test_editing_renames_rather_than_duplicating(): void
    {
        $definition = ReportDefinition::put('Sales', InvoiceDataset::class, [
            'columns' => ['invoice_number'],
            'period' => RelativePeriod::LAST_MONTH,
        ]);

        Livewire::test(Reports::class)
            ->call('select', $definition->reportKey())
            ->call('editReport', $definition->reportKey())
            ->assertSet('building', true)
            ->assertSet('draft.name', 'Sales')
            ->assertSet('draft.columns', ['invoice_number'])
            ->assertSet('draft.period', RelativePeriod::LAST_MONTH)
            ->set('draft.name', 'Sales, last month')
            ->call('toggleColumn', 'total')
            ->call('saveReport')
            ->assertSet('building', false);

        $this->assertSame(1, ReportDefinition::query()->count(), 'the rename left a second report behind');

        $definition->refresh();

        $this->assertSame('Sales, last month', $definition->name);
        $this->assertSame(['invoice_number', 'total'], $definition->settings()['columns']);
    }

    /** A rename onto a name I already use is refused, rather than leaving two reports called the same. */
    public function test_a_rename_onto_an_existing_name_is_refused(): void
    {
        ReportDefinition::put('Sales', InvoiceDataset::class, ['columns' => ['invoice_number']]);
        $other = ReportDefinition::put('Purchases', InvoiceDataset::class, ['columns' => ['invoice_number']]);

        Livewire::test(Reports::class)
            ->call('editReport', $other->reportKey())
            ->set('draft.name', 'Sales')
            ->call('saveReport')
            ->assertSet('building', true)
            ->assertNotified();

        $this->assertSame('Purchases', $other->refresh()->name);
        $this->assertSame(2, ReportDefinition::query()->count());
    }

    /** Deleting one of mine takes it out of the hub. */
    public function test_deleting_a_report_removes_it(): void
    {
        $definition = ReportDefinition::put('Sales', InvoiceDataset::class, ['columns' => ['invoice_number']]);

        Livewire::test(Reports::class)
            ->call('select', $definition->reportKey())
            ->call('deleteReport', $definition->reportKey())
            ->assertSet('selected', null);

        $this->assertSame(0, ReportDefinition::query()->count());
    }

    // ────────────────────────────────────────────────── whose report it is ──

    /**
     * Somebody else's report is not editable, and not deletable either.
     *
     * A shared report belongs to whoever made it: editing it would change what every reader of it sees.
     * `put()`'s `$replacing` is scoped to the owner for the same reason, so this asserts both the affordance
     * and the action behind it.
     */
    public function test_another_persons_report_cannot_be_edited_or_deleted(): void
    {
        $mine = ReportDefinition::put('Shared sales', InvoiceDataset::class, [
            'columns' => ['invoice_number'],
        ], isPublic: true);

        $this->actingAs($this->makeUser('Administrator', 'colleague@test.local'));
        ReportDefinition::forgetReadable();

        Livewire::test(Reports::class)
            ->call('select', $mine->reportKey())
            // Readable, so it opens...
            ->assertSet('selected', $mine->reportKey())
            // ...and it is not offered for editing.
            ->assertDontSee('Edit report')
            ->call('editReport', $mine->reportKey())
            ->assertSet('building', false)
            ->call('deleteReport', $mine->reportKey())
            ->assertSet('selected', $mine->reportKey());

        $this->assertSame(1, ReportDefinition::query()->count());
        $this->assertSame('Shared sales', $mine->refresh()->name);
    }

    /** Sharing is offered, and it is what makes a report readable by a colleague. */
    public function test_a_shared_report_is_readable_by_a_colleague(): void
    {
        Livewire::test(Reports::class)
            ->call('startBuilding')
            ->set('draft.dataset', InvoiceDataset::key())
            ->set('draft.name', 'Everybody sales')
            ->set('draft.is_public', true)
            ->call('toggleColumn', 'invoice_number')
            ->call('saveReport')
            ->assertSet('building', false);

        $definition = ReportDefinition::query()->firstOrFail();

        $this->assertTrue($definition->is_public);

        $this->actingAs($this->makeUser('Administrator', 'reader@test.local'));
        ReportDefinition::forgetReadable();

        $this->assertArrayHasKey($definition->reportKey(), Reports::catalogue());
    }

    /** A grouped report assembled in the form comes out grouped. */
    public function test_a_grouped_report_can_be_assembled(): void
    {
        $acme = Contact::create(['name' => 'Acme', 'kind' => Contact::KIND_CUSTOMER]);
        $this->invoice('INV-1', 1000, ['contact_id' => $acme->id]);
        $this->invoice('INV-2', 2000, ['contact_id' => $acme->id]);

        Livewire::test(Reports::class, ['asOf' => self::AS_OF])
            ->call('startBuilding')
            ->set('draft.dataset', InvoiceDataset::key())
            ->set('draft.name', 'By customer')
            ->call('toggleColumn', 'contact')
            ->set('draft.group_by', 'contact')
            ->set('draft.aggregates.total', DatasetColumn::SUM)
            ->assertSee('Acme')
            ->assertSee('3,000')
            ->call('saveReport');

        $settings = ReportDefinition::query()->firstOrFail()->settings();

        $this->assertSame('contact', $settings['group_by']);
        $this->assertSame(['total' => DatasetColumn::SUM], $settings['aggregates']);
    }

    /** @param  array<string, mixed>  $attributes */
    private function invoice(string $number, float $total, array $attributes = []): Invoice
    {
        return Invoice::create(array_merge([
            'invoice_number' => $number,
            'kind' => Invoice::KIND_SALE,
            'status' => Invoice::STATUS_ISSUED,
            'contact_id' => ($attributes['contact_id'] ?? null)
                ?: Contact::create(['name' => 'Customer '.$number, 'kind' => Contact::KIND_CUSTOMER])->id,
            'invoice_date' => '2026-08-10',
            'subtotal' => $total,
            'total' => $total,
        ], $attributes));
    }
}
