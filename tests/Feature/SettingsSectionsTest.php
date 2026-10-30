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
     *
     * **2026-09-21** — `invoicing.credit-control` (76) and `invoicing.statements` (77) joined beside dunning:
     * `docs/erpnext-gap-plan.md` §4 items 5 and 1. Both are company-wide switches for customer-facing
     * behaviour, so they sit with the other one — chasing overdue invoices — rather than among the ledger's.
     *
     * **2026-09-28** — `crm.lead-capture` joined at sort 79, contributed by Crm: the phase 3.5 capture
     * endpoint read `crm.lead_capture.enabled` and `.token`, but nothing wrote them — the endpoint existed
     * and no administrator could open it. Directly before the status page (80) because it is the same shape
     * of thing: a public, token-gated door that opening has to be a deliberate act. Hidden for a company
     * without the CRM module.
     *
     * **2026-10-30** — construction's two policy blocks joined, owner-decision fixes that would otherwise be hard-coded:
     * `construction.qhse` (64, people) holds the base every safety rate is quoted against, and `construction.costing`
     * (71, money, beside the account map) holds the treatment a hand-typed cost starts with and the three-way match
     * tolerances, which had a setting key and no screen. Both hidden for a company without the module.
     *
     * **2026-10-30** — `accounting.petty-cash` joined at sort 25, contributed by Accounting: the petty cash float
     * was a field Core's page carried for Accounting, and a required one, so a company that keeps no petty cash
     * could save no setting at all without inventing a float. It is Accounting's own block now, in the same place
     * on the page (after the ledger freeze, before approvals), and blank means "no float".
     *
     * **2026-10-29** — the SaaS launch plan's three public doors joined on the same terms:
     * `crm.field-visits` (78, docs/saas-launch-plan.md §8.5), `recruitment.careers` (§7.4) and
     * `documents.verification` (§7.5). Each is a switch that closes a public page without a deploy.
     */
    public function test_the_modules_contribute_their_sections(): void
    {
        $this->assertSame(
            [
                'accounting.currency', 'accounting.ledger-freeze', 'accounting.petty-cash', 'users.access',
                'accounting.payroll-posting', 'payroll.run', 'lifecycle.documents', 'expenses.approval-chain',
                'lifecycle.onboarding', 'construction.qhse', 'advances.recovery', 'construction.accounts',
                'construction.costing', 'invoicing.dunning',
                'invoicing.credit-control', 'devices.devices', 'invoicing.statements', 'campaigns.sending', 'crm.field-visits',
                'crm.lead-capture', 'recruitment.careers', 'documents.verification', 'documents.layout',
                'verification.checks',
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
                'Float Amount',                           // 25, contributed
                'Who has to sign an entry off',           // 30
                'Auto-post payroll journal entries',      // 60, contributed
                'iPayments Defaults',                     // 70
                'Chasing overdue invoices',               // 75, contributed
                'Enable public status page',              // 80
            ]);
    }

    /**
     * Which tab each module's settings belong under — an exact map, for the same reason the list above
     * is exact.
     *
     * **2026-10-30** — the page was twenty-eight sections in one column, which is a screen nobody
     * reaches the end of, and it grew that way one honest contribution at a time. They are dealt into
     * five tabs chosen by who is asking rather than by which module owns the block, so a module says
     * which one it belongs under at registration: it is the only thing that knows what its settings
     * are about, and the page keeping a list of every module's keys is the coupling this registry
     * exists to avoid.
     *
     * The groupings worth stating, because they are judgements rather than module names:
     *
     *  - the four **public doors** — lead capture, field visits, the careers page and document
     *    verification — sit with the status page rather than with the modules behind them. They are
     *    the same decision in five places: whether somebody outside the company may open this
     *    without signing in.
     *  - **attendance devices** is people, not integrations: it is read by whoever sets the hours.
     *  - **what somebody must confirm before being let in** is people for the same reason.
     *  - the **document layout** is company, beside the letterhead, which is the other half of the
     *    same question — it is the one section placed against its sort number.
     *
     * **2026-10-30** — `users.access` is **Company, not People.** People is employment policy — leave,
     * hours, what staff must confirm before they are let in — and this is about logins: whether an
     * account made on the Users page can sign in with the password just typed for it. Every company has
     * that question whether or not it records anybody as an employee, and a company using none of the
     * HR modules would have found the setting under a tab about staff it does not keep.
     */
    public function test_each_module_says_which_tab_its_settings_belong_under(): void
    {
        $this->assertSame(
            [
                'accounting.currency' => 'money',
                'accounting.ledger-freeze' => 'money',
                'accounting.petty-cash' => 'money',
                'users.access' => 'company',
                'accounting.payroll-posting' => 'money',
                'payroll.run' => 'people',
                'lifecycle.documents' => 'people',
                'expenses.approval-chain' => 'people',
                'lifecycle.onboarding' => 'people',
                'construction.qhse' => 'people',
                'advances.recovery' => 'people',
                'construction.accounts' => 'money',
                'construction.costing' => 'money',
                'invoicing.dunning' => 'customers',
                'invoicing.credit-control' => 'customers',
                'devices.devices' => 'people',
                'invoicing.statements' => 'customers',
                'campaigns.sending' => 'customers',
                'crm.field-visits' => 'public',
                'crm.lead-capture' => 'public',
                'recruitment.careers' => 'public',
                'documents.verification' => 'public',
                'documents.layout' => 'company',
                'verification.checks' => 'people',
            ],
            SettingsSections::tabs(),
        );

        $this->assertSame(
            [],
            array_diff(SettingsSections::tabs(), array_keys(CompanySettings::TABS)),
            'a module asked for a tab this page does not have',
        );
    }

    /**
     * A tab with nothing left in it is not rendered.
     *
     * Every section of the customers tab is contributed — dunning, credit control and statements are all
     * Invoicing's — so a company without the module would otherwise open Company Settings and find a
     * heading with nothing under it, which reads as something broken rather than something absent.
     */
    public function test_a_tab_with_nothing_in_it_is_not_rendered(): void
    {
        SettingsSections::flush();

        try {
            Livewire::test(CompanySettings::class)
                ->assertSuccessful()
                ->assertSee('Public pages')      // still has the status page, which is this page's own
                ->assertDontSee('Customers');    // had only Invoicing's three
        } finally {
            $this->app->register(AccountingServiceProvider::class, true);
        }
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
                // The petty cash float is Accounting's too now, so it goes with the rest of the books.
                ->assertDontSee('Float Amount')
                ->set('data.reports_negatives_in_parentheses', true)
                ->call('save')
                ->assertHasNoErrors();

            $this->assertTrue((bool) app(TenantSettings::class)->get('reports.negatives_in_parentheses'));
        } finally {
            // Restored by re-booting the provider that registers them, since this is static state the rest of
            // the suite shares. Re-registering is idempotent — the keys are the same both times.
            $this->app->register(AccountingServiceProvider::class, true);
        }

        $this->assertSame(
            ['accounting.currency', 'accounting.ledger-freeze', 'accounting.petty-cash', 'accounting.payroll-posting'],
            SettingsSections::keys(),
            'the registry was left as it was found',
        );
    }
}
