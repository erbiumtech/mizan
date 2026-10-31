<?php

namespace Tests\Feature;

use App\Filament\Support\RecordSheet;
use App\Filament\Support\RecordSheetActions;
use App\Filament\Support\RecordSheetEditPage;
use App\Modules\Accounting\Filament\Resources\Accounts\AccountResource;
use App\Modules\Accounting\Filament\Resources\Accounts\Pages\ListAccounts;
use App\Modules\Accounting\Models\Account;
use App\Modules\Core\Models\CustomField;
use App\Modules\Invoicing\Filament\Resources\Contacts\ContactResource;
use App\Modules\Invoicing\Filament\Resources\Contacts\Pages\EditContact;
use App\Modules\Invoicing\Filament\Resources\Contacts\Pages\ListContacts;
use App\Modules\Invoicing\Filament\Resources\Invoices\InvoiceResource;
use App\Modules\Invoicing\Filament\Resources\Invoices\Pages\ListInvoices;
use App\Modules\Invoicing\Filament\Resources\TaxRates\Pages\EditTaxRate;
use App\Modules\Invoicing\Filament\Resources\TaxRates\Pages\ListTaxRates;
use App\Modules\Invoicing\Filament\Resources\TaxRates\TaxRateResource;
use App\Modules\Invoicing\Models\Contact;
use App\Modules\Invoicing\Models\Invoice;
use App\Modules\Invoicing\Models\TaxRate;
use Filament\Actions\Testing\TestAction;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;
use Livewire\Livewire;
use Tests\AccountingTestCase;
use Tests\Concerns\InteractsWithTenant;

/**
 * Side sheets — docs/side-sheets.md.
 *
 * Tax rates are a `Sheet` (view and edit over the list), contacts and accounts a `SheetView` (view
 * over the list, edit on the full page), invoices a `Page`. What is worth proving: that the row and the
 * actions open the sheet instead of the page, that saving from the sheet saves — through the edit
 * page's own hooks, custom fields included — that the address follows the sheet so a copied link
 * reopens it, that someone who may not edit gets no edit sheet by any route, and that a `Page`
 * resource is left exactly as it was.
 */
class RecordSheetTest extends AccountingTestCase
{
    use InteractsWithTenant;

    private TaxRate $rate;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs($this->makeUser('Administrator', 'sheets@test.local'));
        $this->setCurrentTenant();

        $this->rate = TaxRate::create(['name' => 'GST 18%', 'rate' => 18, 'is_default' => true, 'is_active' => true]);
        // A second row, so the list hydrates a collection — what turns on lazy-loading prevention.
        TaxRate::create(['name' => 'Reduced 5%', 'rate' => 5, 'is_active' => true]);
    }

    public function test_the_declarations_read_back(): void
    {
        $this->assertSame(RecordSheet::Sheet, RecordSheet::of(TaxRateResource::class));
        $this->assertSame(RecordSheet::SheetView, RecordSheet::of(ContactResource::class));
        $this->assertSame(RecordSheet::SheetView, RecordSheet::of(AccountResource::class));
        $this->assertSame(RecordSheet::Page, RecordSheet::of(InvoiceResource::class));
    }

    public function test_a_sheet_resource_edits_over_the_list_instead_of_on_its_page(): void
    {
        $component = Livewire::test(ListTaxRates::class)
            ->assertActionVisible(TestAction::make('edit')->table($this->rate))
            ->assertActionDoesNotHaveUrl(
                TestAction::make('edit')->table($this->rate),
                TaxRateResource::getUrl('edit', ['record' => $this->rate]),
            );

        $table = $component->instance()->getTable();

        $this->assertNull($table->getRecordUrl($this->rate), 'the row no longer links to the page');
        $this->assertSame('edit', $table->getRecordAction($this->rate), 'it opens the edit sheet');

        $component
            ->mountAction(TestAction::make('edit')->table($this->rate))
            ->assertActionMounted(TestAction::make('edit')->table($this->rate))
            ->assertActionDataSet(['name' => 'GST 18%'])
            ->assertMountedActionModalSeeHtml('fi-record-sheet-tools');
    }

    public function test_saving_from_the_sheet_saves_the_record(): void
    {
        Livewire::test(ListTaxRates::class)
            ->callAction(TestAction::make('edit')->table($this->rate), data: ['name' => 'GST standard rate'])
            ->assertHasNoActionErrors();

        $this->assertSame('GST standard rate', $this->rate->fresh()->name);
    }

    public function test_the_sheet_validates_like_the_page(): void
    {
        Livewire::test(ListTaxRates::class)
            ->callAction(TestAction::make('edit')->table($this->rate), data: ['name' => ''])
            ->assertHasActionErrors(['name' => 'required']);

        $this->assertSame('GST 18%', $this->rate->fresh()->name);
    }

    public function test_the_address_follows_the_sheet_and_clears_when_it_closes(): void
    {
        Livewire::test(ListTaxRates::class)
            ->mountAction(TestAction::make('edit')->table($this->rate))
            ->assertSet('defaultTableAction', 'edit')
            ->assertSet('defaultTableActionRecord', (string) $this->rate->getKey())
            ->call('unmountAction')
            ->assertSet('defaultTableAction', [])
            ->assertSet('defaultTableActionRecord', []);
    }

    public function test_a_copied_link_reopens_the_sheet(): void
    {
        $link = RecordSheetActions::link(TaxRateResource::class, $this->rate, 'edit');

        $this->assertStringContainsString('tableAction=edit', $link);
        $this->assertStringContainsString('tableActionRecord='.$this->rate->getKey(), $link);

        // Filament reads the parameters on load and mounts the action with `wire:init` — the call below.
        Livewire::withQueryParams(['tableAction' => 'edit', 'tableActionRecord' => $this->rate->getKey()])
            ->test(ListTaxRates::class)
            ->assertSet('defaultTableAction', 'edit')
            ->call('mountAction', 'edit', [], ['table' => true, 'recordKey' => (string) $this->rate->getKey(), 'mountedFromUrl' => true])
            ->assertActionMounted(TestAction::make('edit')->table($this->rate))
            ->assertActionDataSet(['name' => 'GST 18%']);
    }

    public function test_someone_who_may_not_edit_gets_the_view_sheet_and_no_edit_sheet(): void
    {
        $reader = $this->makeUser('Employee', 'reader@test.local');
        $reader->givePermissionTo('InvoiceView');
        $this->actingAs($reader);

        $this->assertTrue($reader->can('view', $this->rate), 'the fixture must be able to read tax rates');
        $this->assertFalse($reader->can('update', $this->rate), 'and must not be able to change them');

        $component = Livewire::test(ListTaxRates::class)
            ->assertActionHidden(TestAction::make('edit')->table($this->rate))
            ->assertActionVisible(TestAction::make('view')->table($this->rate));

        $this->assertSame('view', $component->instance()->getTable()->getRecordAction($this->rate));

        // Not by a crafted link either: the edit action refuses to mount.
        $component
            ->call('mountAction', 'edit', [], ['table' => true, 'recordKey' => (string) $this->rate->getKey(), 'mountedFromUrl' => true])
            ->assertActionNotMounted(TestAction::make('edit')->table($this->rate));

        $component
            ->mountAction(TestAction::make('view')->table($this->rate))
            ->assertActionMounted(TestAction::make('view')->table($this->rate))
            ->assertActionDataSet(['name' => 'GST 18%']);
    }

    public function test_a_sheet_view_resource_views_over_the_list_and_edits_on_its_page(): void
    {
        $contact = Contact::create(['name' => 'Globex', 'kind' => 'customer', 'is_active' => true]);
        Contact::create(['name' => 'Initech', 'kind' => 'customer', 'is_active' => true]);

        $component = Livewire::test(ListContacts::class)
            ->assertActionVisible(TestAction::make('view')->table($contact))
            ->assertActionHasUrl(
                TestAction::make('edit')->table($contact),
                ContactResource::getUrl('edit', ['record' => $contact]),
            );

        $table = $component->instance()->getTable();

        $this->assertNull($table->getRecordUrl($contact));
        $this->assertSame('view', $table->getRecordAction($contact));

        $component
            ->mountAction(TestAction::make('view')->table($contact))
            ->assertActionMounted(TestAction::make('view')->table($contact))
            ->assertActionDataSet(['name' => 'Globex'])
            ->assertMountedActionModalSeeHtml(e(ContactResource::getUrl('edit', ['record' => $contact])));
    }

    public function test_an_account_opens_in_a_view_sheet_and_edits_on_its_page(): void
    {
        $accounts = Account::query()->orderBy('code')->limit(2)->get();
        $this->assertCount(2, $accounts, 'the chart of accounts is seeded with the company');
        $account = $accounts->first();

        $component = Livewire::test(ListAccounts::class)
            ->assertActionVisible(TestAction::make('view')->table($account))
            ->assertActionHasUrl(
                TestAction::make('edit')->table($account),
                AccountResource::getUrl('edit', ['record' => $account]),
            );

        $table = $component->instance()->getTable();

        $this->assertNull($table->getRecordUrl($account), 'the row opens the sheet, not the page');
        $this->assertSame('view', $table->getRecordAction($account));

        $component
            ->mountAction(TestAction::make('view')->table($account))
            ->assertActionMounted(TestAction::make('view')->table($account))
            ->assertActionDataSet(['code' => $account->code])
            ->assertMountedActionModalSeeHtml('fi-record-sheet-tools');
    }

    public function test_a_document_resource_is_left_on_its_pages(): void
    {
        $customer = Contact::create(['name' => 'Karachi Textiles', 'kind' => Contact::KIND_CUSTOMER]);

        $invoices = collect(['2026-10-01', '2026-10-02'])->map(fn (string $date): Invoice => Invoice::create([
            'kind' => Invoice::KIND_SALE,
            'contact_id' => $customer->getKey(),
            'invoice_date' => $date,
            'due_date' => '2026-10-31',
        ]));

        $table = Livewire::test(ListInvoices::class)->instance()->getTable();

        $this->assertNotNull($table->getRecordUrl($invoices->first()), 'a Page resource still links its rows');
    }

    /**
     * The edit sheet fills and saves through the resource's own edit page. The contact's page loads and
     * saves custom field values in its hooks (InteractsWithCustomFields) — the values that would silently
     * stop saving if a sheet ran Filament's edit action on its own.
     */
    public function test_the_edit_page_hooks_carry_custom_fields_both_ways(): void
    {
        CustomField::create([
            'model_type' => Contact::class, 'code' => 'region', 'name' => 'Region', 'type' => 'text',
        ]);

        $contact = Contact::create(['name' => 'Globex', 'kind' => 'customer', 'is_active' => true]);
        $contact->saveCustomFields(['region' => 'North']);

        $this->assertSame(EditContact::class, RecordSheetEditPage::pageFor(ContactResource::class));

        $filled = RecordSheetEditPage::fill(EditContact::class, $contact->fresh(), $contact->fresh()->attributesToArray());
        $this->assertSame('North', $filled['custom_fields']['region'] ?? null, 'the page fills the custom fields');

        RecordSheetEditPage::save(
            EditContact::class,
            $contact->fresh(),
            data: ['name' => 'Globex Ltd'],
            rawData: ['name' => 'Globex Ltd', 'custom_fields' => ['region' => 'South']],
        );

        $saved = $contact->fresh();
        $this->assertSame('Globex Ltd', $saved->name);
        $this->assertSame('South', $saved->customFieldsData()['region'], 'the page saves them from the raw form state');
    }

    /** The page's own order: beforeSave, mutateFormDataBeforeSave, handleRecordUpdate, afterSave. */
    public function test_the_edit_page_hooks_run_in_the_pages_order(): void
    {
        RecordSheetHookProbePage::$calls = [];

        $saved = RecordSheetEditPage::save(RecordSheetHookProbePage::class, $this->rate, ['name' => 'gst'], ['name' => 'gst', 'note' => 'raw']);

        $this->assertSame(['beforeSave', 'mutateFormDataBeforeSave', 'handleRecordUpdate:GST', 'afterSave:raw'], RecordSheetHookProbePage::$calls);
        $this->assertSame('GST', $saved->fresh()->name);

        $this->assertSame(['name' => 'filled'], RecordSheetEditPage::fill(RecordSheetHookProbePage::class, $this->rate, ['name' => 'x']));
    }

    /** End to end: the tax rate sheet saves through its edit page, and carries the unsaved-changes guard. */
    public function test_the_sheet_saves_through_the_resource_edit_page(): void
    {
        $this->assertSame(EditTaxRate::class, RecordSheetEditPage::pageFor(TaxRateResource::class));

        Livewire::test(ListTaxRates::class)
            ->mountAction(TestAction::make('edit')->table($this->rate))
            ->assertMountedActionModalSeeHtml('data-record-sheet-dismiss')
            ->assertMountedActionModalSeeHtml('recordSheetMayClose')
            ->setActionData(['rate' => 17])
            ->callMountedAction()
            ->assertHasNoActionErrors();

        $this->assertEquals(17, $this->rate->fresh()->rate);
    }
}

/**
 * An edit page whose hooks note what ran, in what order, with what — standing in for a resource's own.
 */
class RecordSheetHookProbePage extends EditRecord
{
    /** @var list<string> */
    public static array $calls = [];

    protected static string $resource = TaxRateResource::class;

    protected function mutateFormDataBeforeFill(array $data): array
    {
        return ['name' => 'filled'];
    }

    protected function beforeSave(): void
    {
        static::$calls[] = 'beforeSave';
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        static::$calls[] = 'mutateFormDataBeforeSave';

        return ['name' => strtoupper($data['name'])];
    }

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        static::$calls[] = 'handleRecordUpdate:'.$data['name'];

        $record->update($data);

        return $record;
    }

    protected function afterSave(): void
    {
        static::$calls[] = 'afterSave:'.($this->data['note'] ?? '');
    }
}
