<?php

namespace Tests\Feature;

use App\Modules\Accounting\Filament\Resources\Currencies\CurrencyResource;
use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\Currency;
use App\Modules\Accounting\Models\ExchangeRate;
use App\Modules\Accounting\Models\JournalEntry;
use App\Modules\Accounting\Services\JournalEntryService;
use App\Modules\Billing\Models\BillingRun;
use App\Modules\Core\Filament\Pages\CompanySettings;
use App\Modules\Invoicing\Models\Contact;
use Database\Seeders\CurrencySeeder;
use Livewire\Livewire;
use Tests\AccountingTestCase;
use Tests\Concerns\InteractsWithTenant;

/**
 * The currency belongs to the company.
 *
 * Not to a document and not to the billing statement: it is what every amount in this
 * company's ledger means, so it is set once in Company Settings and fixed the moment
 * anything is posted. BillingRun's EUR quoting is a separate thing and stays as it is —
 * a quote at an agreed rate, not a currency the books are kept in.
 */
class CompanyCurrencyTest extends AccountingTestCase
{
    use InteractsWithTenant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs($this->makeUser('Administrator', 'companycurrency@test.local'));
        $this->setCurrentTenant();

        $this->seed(CurrencySeeder::class);
    }

    private function postSomething(): JournalEntry
    {
        $entries = app(JournalEntryService::class);

        $entry = $entries->create(
            ['entry_date' => '2026-08-04', 'entry_type' => 'general', 'memo' => 'Test'],
            [
                ['account_id' => Account::where('code', '1100')->firstOrFail()->id, 'debit_amount' => 1000],
                ['account_id' => Account::where('code', '3100')->firstOrFail()->id, 'credit_amount' => 1000],
            ],
        );

        $entry->update(['status' => JournalEntry::STATUS_APPROVED, 'approved_at' => now()]);

        return $entries->post($entry);
    }

    public function test_company_settings_shows_the_companys_currency(): void
    {
        Livewire::test(CompanySettings::class)
            ->assertSet('data.base_currency', 'PKR')
            ->assertSee('What this company keeps its books in');
    }

    public function test_it_can_be_changed_while_the_ledger_is_empty(): void
    {
        $this->assertFalse(CompanySettings::ledgerHasEntries());

        Livewire::test(CompanySettings::class)
            ->set('data.base_currency', 'EUR')
            ->call('save');

        $this->assertSame('EUR', Currency::baseCode());
    }

    /**
     * Once anything is posted it is fixed: every stored amount means this currency, so
     * changing it would reinterpret the ledger rather than restate it.
     */
    public function test_it_is_fixed_once_anything_is_posted(): void
    {
        $this->postSomething();

        $this->assertTrue(CompanySettings::ledgerHasEntries());

        Livewire::test(CompanySettings::class)
            ->set('data.base_currency', 'EUR')
            ->call('save');

        $this->assertSame('PKR', Currency::baseCode(), 'the ledger still means rupees');
    }

    public function test_the_page_says_why_it_is_fixed(): void
    {
        $this->postSomething();

        Livewire::test(CompanySettings::class)->assertSee('reinterpret them rather than restate them');
    }

    // ---- Currencies and their rates as data --------------------------------

    public function test_the_currencies_screen_lists_them_with_todays_rate(): void
    {
        ExchangeRate::create(['currency_code' => 'EUR', 'effective_on' => now()->toDateString(), 'rate' => 304]);

        Livewire::test(CurrencyResource::getPages()['index']->getPage())
            ->assertSee('PKR')
            ->assertSee('EUR')
            ->assertSee('304.0000');
    }

    /** A currency with no rate cannot be posted in, so the screen says so plainly. */
    public function test_a_currency_with_no_rate_is_called_out(): void
    {
        Livewire::test(CurrencyResource::getPages()['index']->getPage())->assertSee('none recorded');
    }

    public function test_the_base_currency_needs_no_rate(): void
    {
        $this->assertSame(1.0, Currency::where('code', 'PKR')->first()->rateOn());
    }

    /**
     * Enforced on the model, not the policy: Administrators pass every policy check, and
     * this is a rule about what the ledger can still explain rather than about who is
     * asking.
     */
    public function test_a_currency_that_has_been_used_cannot_be_deleted(): void
    {
        $eur = Currency::where('code', 'EUR')->first();
        ExchangeRate::create(['currency_code' => 'EUR', 'effective_on' => '2026-08-01', 'rate' => 304]);

        $this->assertTrue(auth()->user()->isAdministrator());

        try {
            $eur->delete();
            $this->fail('a used currency was deleted');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('Switch it off instead', $e->getMessage());
        }

        $this->assertDatabaseHas('currencies', ['code' => 'EUR']);
    }

    public function test_the_base_currency_cannot_be_deleted(): void
    {
        $this->expectExceptionMessage('books are kept in');

        Currency::where('code', 'PKR')->first()->delete();
    }

    public function test_an_unused_currency_can_be_removed(): void
    {
        $usd = Currency::create(['code' => 'USD', 'name' => 'US Dollar']);

        $usd->delete();

        $this->assertDatabaseMissing('currencies', ['code' => 'USD']);
    }

    /**
     * The billing statement is untouched: it quotes a month at an agreed rate, which is
     * a different act from keeping books in a currency, and collapsing the two would
     * make a quote look like a posting.
     */
    public function test_billing_still_quotes_its_own_rate(): void
    {
        $run = BillingRun::create([
            'contact_id' => Contact::create([
                'name' => 'Erbium AG', 'kind' => 'customer', 'is_active' => true,
            ])->id,
            'month' => 'August',
            'fiscal_year_id' => $this->fiscalYear->id,
            'invoice_date' => '2026-09-01',
            'currency' => 'EUR',
            'exchange_rate' => 304,
        ]);

        $this->assertSame('EUR', $run->currency);
        $this->assertSame('304.000000', $run->exchange_rate);
    }
}
