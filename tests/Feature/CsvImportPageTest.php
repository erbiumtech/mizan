<?php

namespace Tests\Feature;

use App\Modules\Accounting\AccountingServiceProvider;
use App\Modules\Core\Filament\Pages\CsvImport;
use App\Modules\Core\Models\User;
use App\Modules\Inventory\InventoryServiceProvider;
use App\Modules\Invoicing\InvoicingServiceProvider;
use App\Support\CsvImporters;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\Concerns\InteractsWithTenant;
use Tests\TestCase;

/**
 * The import screen, once it stopped knowing what can be imported.
 *
 * `CsvImport` named its own types — a `TYPE_CONTACTS` default and a `TYPE_OPENING_BALANCES` check to decide
 * whether to show a date field — which is how Core came to depend on Invoicing, Inventory and Accounting for
 * a CSV reader. Both now come from whatever registered: see `App\Support\CsvImporters` and
 * docs/module-packaging-plan.md §9. What is asserted here is that the page still works when it is told
 * rather than when it knows, because nothing else covered this page at all.
 */
class CsvImportPageTest extends TestCase
{
    use InteractsWithTenant;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Role::findOrCreate('Administrator', 'web');
        $user = User::factory()->create();
        $user->assignRole('Administrator');
        $this->actingAs($user);
        $this->setCurrentTenant();
    }

    public function test_the_page_renders_and_opens_on_the_first_registered_type(): void
    {
        Livewire::test(CsvImport::class)
            ->assertSuccessful()
            ->assertSet('data.type', CsvImporters::keys()[0])
            ->assertSee('Clients and suppliers');
    }

    /** The preview table's headers come from the chosen importer, not from a list on the page. */
    public function test_the_column_headers_follow_the_chosen_type(): void
    {
        Livewire::test(CsvImport::class)
            ->set('data.type', 'products')
            ->assertSee('sku');
    }

    /**
     * The date field is the importer's to ask for. Contacts asks for none, so it is hidden and its absence
     * from the state is what `import()` reads as "no date" — the behaviour that used to be an `if` on a type
     * constant in this file.
     */
    public function test_only_a_dated_import_shows_the_date_field(): void
    {
        Livewire::test(CsvImport::class)
            ->set('data.type', 'contacts')
            ->assertDontSee('Balances as at')
            ->set('data.type', 'opening_balances')
            ->assertSee('Balances as at');
    }

    /** A page with nothing to import is not a page. */
    public function test_the_page_is_unreachable_with_no_importers_registered(): void
    {
        $registered = CsvImporters::keys();

        CsvImporters::flush();

        try {
            $this->assertFalse(CsvImport::canAccess());
        } finally {
            // Restored by re-booting the providers that register them, since this is static state the rest
            // of the suite shares. Re-registering is idempotent — the key is the same both times.
            CsvImporters::flush();

            foreach ([InvoicingServiceProvider::class, InventoryServiceProvider::class, AccountingServiceProvider::class] as $provider) {
                $this->app->register($provider, true);
            }
        }

        $this->assertSame($registered, CsvImporters::keys(), 'the registry was left as it was found');
    }
}
