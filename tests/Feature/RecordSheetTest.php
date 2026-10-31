<?php

namespace Tests\Feature;

use App\Filament\Support\RecordSheet;
use App\Filament\Support\RecordSheetActions;
use App\Modules\Accounting\Filament\Resources\Accounts\AccountResource;
use App\Modules\Accounting\Filament\Resources\Accounts\Pages\ListAccounts;
use App\Modules\Accounting\Models\Account;
use App\Modules\Invoicing\Filament\Resources\Contacts\ContactResource;
use App\Modules\Invoicing\Filament\Resources\Contacts\Pages\ListContacts;
use App\Modules\Invoicing\Filament\Resources\TaxRates\Pages\ListTaxRates;
use App\Modules\Invoicing\Filament\Resources\TaxRates\TaxRateResource;
use App\Modules\Invoicing\Models\Contact;
use App\Modules\Invoicing\Models\TaxRate;
use Filament\Actions\Testing\TestAction;
use Livewire\Livewire;
use Tests\AccountingTestCase;
use Tests\Concerns\InteractsWithTenant;

/**
 * Side sheets — docs/side-sheets.md.
 *
 * Tax rates are a `Sheet` (view and edit over the list), contacts a `SheetView` (view over the list,
 * edit on the full page), accounts a `Page`. What is worth proving: that the row and the actions open
 * the sheet instead of the page, that saving from the sheet saves, that the address follows the sheet
 * so a copied link reopens it, that someone who may not edit gets no edit sheet by any route, and that
 * a `Page` resource is left exactly as it was.
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
        $this->assertSame(RecordSheet::Page, RecordSheet::of(AccountResource::class));
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

    public function test_a_page_resource_is_left_on_its_pages(): void
    {
        $accounts = Account::query()->orderBy('code')->limit(2)->get();
        $this->assertCount(2, $accounts);

        $table = Livewire::test(ListAccounts::class)->instance()->getTable();

        $this->assertNotNull($table->getRecordUrl($accounts->first()), 'a Page resource still links its rows');
    }
}
