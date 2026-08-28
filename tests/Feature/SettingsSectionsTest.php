<?php

namespace Tests\Feature;

use App\Modules\Accounting\AccountingServiceProvider;
use App\Modules\Core\Filament\Pages\CompanySettings;
use App\Support\SettingsSections;
use App\Support\TenantSettings;
use Livewire\Livewire;
use Tests\AccountingTestCase;
use Tests\Concerns\InteractsWithTenant;

/**
 * Company Settings, once the sections a module owns stopped being written into it.
 *
 * The base-currency section reached `Currency` and `JournalEntryLine`, and the payroll-accounts field
 * validated against `Account` — the last of §9's seven files, and the last thing making Core depend on
 * Accounting. See `App\Support\SettingsSections` and docs/module-packaging-plan.md §9.
 *
 * What the existing tests already cover is that the sections still *work*: `CompanyCurrencyTest` for the
 * currency and `CompanySettingsValidationTest` for the account-code rule, both unchanged in what they assert.
 * What is asserted here is the part that is new — that the page holds together when a section is not there,
 * which is the whole point of contributing them.
 */
class SettingsSectionsTest extends AccountingTestCase
{
    use InteractsWithTenant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs($this->makeUser('Administrator', 'settingssections@test.local'));
        $this->setCurrentTenant();
    }

    /**
     * Every contributed section, in the order they asked to appear.
     *
     * Asserted as an exact list deliberately: a module quietly adding a block to Company Settings is a change to
     * a page every administrator opens, and it should be a decision rather than a surprise. Update this in the
     * same commit as the registration, and say why.
     *
     * **2026-08-28** — `invoicing.dunning` joined at sort 75, contributed by Invoicing:
     * `docs/erpnext-gap-plan.md` Phase 5 adds overdue reminders, and this is the block that decides whether
     * they are sent at all. **Off by default**, which is why it is a settings block rather than a feature:
     * it is the only thing in the application that emails somebody outside the company, so switching it on
     * has to be an act somebody performs having read the sentence next to it. Sorted after Accounting's
     * blocks because a company setting up its books reads the currency and the freeze date first, and before
     * the status page, which this page's own comment keeps last.
     *
     * **2026-08-28** — `accounting.ledger-freeze` joined at sort 20, contributed by Accounting:
     * `docs/erpnext-gap-plan.md` Phase 3 adds a date before which nothing may be posted, which is the
     * request a fiscal-year close is too blunt for — *stop backdating into July now that July is reported,
     * while the year stays open*. One date, on the page where the other decisions about the books are, and
     * between the currency and payroll posting because it is a decision about the ledger rather than about a
     * module's behaviour.
     *
     * **2026-08-18** — `construction.accounts` joined at sort 70, contributed by `construction_contracts` rather
     * than written into Core: §18.2 of `docs/construction-management-plan.md` refuses a `core -> construction`
     * coupling on the grounds that "the account map belongs on a Construction settings page", and this registry is
     * what makes that possible. The block is hidden for a company without the module, so the page is unchanged for
     * everybody else.
     */
    public function test_the_modules_contribute_their_sections(): void
    {
        $this->assertSame(
            [
                'accounting.currency', 'accounting.ledger-freeze', 'accounting.payroll-posting',
                'construction.accounts', 'invoicing.dunning',
            ],
            SettingsSections::keys(),
        );
    }

    /**
     * Currency first, payroll posting sixth — a stated order rather than the order the providers happened to
     * boot in, which is alphabetical accident.
     */
    public function test_the_contributed_sections_land_where_they_asked_to(): void
    {
        Livewire::test(CompanySettings::class)
            ->assertSeeInOrder([
                'What this company keeps its books in',   // 10, contributed
                'Float Amount',                           // 20
                'Who has to sign an entry off',           // 30
                'Auto-post payroll journal entries',      // 60, contributed
                'iPayments Defaults',                     // 70
                'Chasing overdue invoices',               // 75, contributed
                'Enable public status page',              // 80
            ]);
    }

    /**
     * A company with no accounting module gets the rest of the page, not an error.
     *
     * This is the failure the inversion exists to prevent: with the sections written into the page, rendering
     * it queried the currencies table and saving it wrote `accounting.payroll_accounts` whether or not
     * anything could ever read them.
     */
    public function test_the_page_renders_and_saves_with_no_sections_contributed(): void
    {
        SettingsSections::flush();

        try {
            Livewire::test(CompanySettings::class)
                ->assertSuccessful()
                ->assertDontSee('What this company keeps its books in')
                ->assertDontSee('Auto-post payroll journal entries')
                ->assertSee('Float Amount')
                ->set('data.petty_cash_float_amount', 7777)
                ->call('save')
                ->assertHasNoErrors();

            $this->assertEqualsWithDelta(7777.0, app(TenantSettings::class)->get('petty_cash.float_amount'), 0.001);
        } finally {
            // Restored by re-booting the provider that registers them, since this is static state the rest of
            // the suite shares. Re-registering is idempotent — the keys are the same both times.
            $this->app->register(AccountingServiceProvider::class, true);
        }

        $this->assertSame(
            ['accounting.currency', 'accounting.ledger-freeze', 'accounting.payroll-posting'],
            SettingsSections::keys(),
            'the registry was left as it was found',
        );
    }
}
