<?php

namespace Tests\Feature;

use App\Modules\Core\Filament\Pages\Reports;
use App\Modules\Core\Models\Company;
use App\Modules\Core\Models\CompanyModule;
use App\Modules\Core\Models\User;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\PermissionRegistrar;
use Tests\Concerns\InteractsWithTenant;
use Tests\TestCase;

/**
 * The Reports screen's own behaviour: the category filter, the search, the two layouts and the run
 * panel.
 *
 * ReportsHubTest covers what the hub *offers* — that every report is linked and that a disabled
 * module takes its reports with it. This covers what happens when somebody uses the screen, and one
 * thing that is not cosmetic at all: the panel renders a link to a report, so the key it opens on has
 * to be one the current role is actually allowed.
 */
class ReportsScreenTest extends TestCase
{
    use InteractsWithTenant;
    use RefreshDatabase;

    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = Company::factory()->create();
        $this->seed(PermissionSeeder::class);

        app(PermissionRegistrar::class)->setPermissionsTeamId($this->company->getKey());
        (new RoleSeeder)->run();

        $user = User::factory()->create(['is_super_admin' => true, 'status' => 1]);
        $this->company->users()->attach($user->getKey());
        $user->assignRole('Administrator');

        $this->actingAs($user);
        $this->setCurrentTenant($this->company);
    }

    // ------------------------------------------------------------- the filters

    public function test_a_section_filter_shows_only_that_section(): void
    {
        Livewire::test(Reports::class)
            ->set('section', 'Payroll & tax')
            ->assertSee('Tax Summary')
            ->assertDontSee('Balance Sheet')
            // The heading follows the filter, so the page says what it is showing.
            ->assertSee('Payroll & tax');
    }

    public function test_no_filter_shows_every_section(): void
    {
        $page = Livewire::test(Reports::class);

        foreach (array_keys(Reports::sections()) as $heading) {
            $page->assertSee($heading);
        }

        // "All" is the chip that clears the filter, and it is the active one with no section set.
        $page->assertSee('All');
    }

    /**
     * The search reads descriptions, not just titles — which is the point of having written them.
     * "how late" is in neither report's name and finds exactly the two ageing reports.
     */
    public function test_the_search_matches_descriptions_as_well_as_titles(): void
    {
        Livewire::test(Reports::class)
            ->set('query', 'how late')
            ->assertSee('Aged Receivables')
            ->assertSee('Aged Payables')
            ->assertDontSee('Trial Balance');
    }

    public function test_the_search_is_case_insensitive(): void
    {
        Livewire::test(Reports::class)
            ->set('query', 'BALANCE SHEET')
            ->assertSee('Balance Sheet');
    }

    public function test_a_search_that_matches_nothing_says_so(): void
    {
        Livewire::test(Reports::class)
            ->set('query', 'zzzznothing')
            ->assertSee('Nothing matches')
            ->assertDontSee('Balance Sheet');
    }

    /**
     * A stale or unlicensed section in the URL falls back to everything.
     *
     * That link is ordinary: somebody bookmarks the payroll section, payroll is later switched off,
     * and the section stops existing. Filtering to nothing would tell them the reports are gone.
     */
    public function test_a_section_that_does_not_exist_is_ignored(): void
    {
        Livewire::test(Reports::class)
            ->set('section', 'Nonsense')
            // Unfiltered: reports from more than one section are listed, and nothing says the list is
            // empty. Asserted on the contents rather than on a heading, because 4c's heading is just
            // the page's name.
            ->assertSee('Balance Sheet')
            ->assertSee('Tax Summary')
            ->assertDontSee('Nothing matches');
    }

    /** The counts in the column describe the sections, so they do not move while somebody types. */
    public function test_the_section_counts_are_not_narrowed_by_the_search(): void
    {
        $before = Reports::sectionCounts();

        Livewire::test(Reports::class)->set('query', 'balance');

        $this->assertSame($before, Reports::sectionCounts());
    }

    // -------------------------------------------------------------- the statement

    /**
     * Both panes on one screen: the list and the statement it selected.
     *
     * The grid/list toggle this used to assert is gone with 3a's card grid — 4c has one list, and the
     * space the second layout used is where the statement now goes.
     */
    public function test_the_list_and_the_statement_share_the_screen(): void
    {
        Livewire::test(Reports::class)
            ->call('select', 'BalanceSheet')
            // the list, still filterable beside the statement
            ->assertSee('Profit & Loss')
            ->assertSee('Search reports')
            // and the statement itself
            ->assertSee('Total assets')
            ->assertSee('Account');
    }

    // ------------------------------------------------------------- the run panel

    /**
     * Selecting a report draws it in the pane.
     *
     * The slide-over this used to assert is gone: 4c reads the whole statement in the right-hand pane
     * instead of previewing it in a panel over the list. What survives is the part that mattered — the
     * report's own description is still shown, and the report's own page is still one click away.
     */
    public function test_selecting_a_report_draws_it_in_the_pane(): void
    {
        Livewire::test(Reports::class)
            ->call('select', 'BalanceSheet')
            ->assertSet('selected', 'BalanceSheet')
            ->assertSee('What the company owns, owes and is worth, on a date.')
            // The statement itself: its sections, and the identity it has to satisfy.
            ->assertSee('ASSETS')
            ->assertSee('LIABILITIES')
            ->assertSee('EQUITY')
            ->assertSee('Total liabilities and equity')
            ->assertSee('Open in full page');
    }

    /**
     * Every report actually renders in the pane.
     *
     * ReportPaneTest asserts the *payload* for all seventeen; this asserts the view draws it, which is not
     * the same thing and was found out the hard way — a Blade change compiled to broken PHP and every report
     * on the screen returned a 500 while every payload test stayed green.
     *
     * The heading is what is asserted per report: it comes from the pane rather than from the list, so
     * seeing it means the right-hand side rendered rather than the row on the left.
     */
    public function test_every_report_renders_in_the_pane(): void
    {
        foreach (Reports::catalogue() as $key => $report) {
            Livewire::test(Reports::class)
                ->call('select', $key)
                ->assertSuccessful()
                ->assertSee($report['label']);
        }

        $this->assertGreaterThanOrEqual(17, count(Reports::catalogue()));
    }

    /**
     * The open report's own filters are on screen, and only its own.
     *
     * The bar used to branch on a single "what does this report ask for" string, so a report could only
     * ever have one control and the two monthly reports had none at all. This asserts the bar is built from
     * what the report declares — including that a report which declares nothing gets nothing, since a month
     * picker on a balance sheet would be a control that changes nothing.
     */
    public function test_the_open_report_carries_its_own_filters(): void
    {
        // The register asks for an account, and offers the accounts it can register.
        Livewire::test(Reports::class)
            ->call('select', 'AccountRegister')
            ->assertSee('wire:model.live="account"', escape: false)
            ->assertDontSee('wire:model.live="month"', escape: false);

        // The tax summary asks for a month, and lets it be left off.
        Livewire::test(Reports::class)
            ->call('select', 'TaxSummary')
            ->assertSee('wire:model.live="month"', escape: false)
            ->assertSee('The whole year')
            ->assertSee('July')
            ->assertDontSee('wire:model.live="account"', escape: false);

        // The balance sheet asks for nothing beyond the date it already has.
        Livewire::test(Reports::class)
            ->call('select', 'BalanceSheet')
            ->assertDontSee('wire:model.live="month"', escape: false)
            ->assertDontSee('wire:model.live="account"', escape: false);
    }

    /**
     * The month a report is filtered to survives in the URL.
     *
     * "The tax summary for July" is the thing somebody sends before a filing, and the pane's whole premise
     * is that what is on screen is a link.
     */
    public function test_a_month_can_be_opened_by_url(): void
    {
        Livewire::withQueryParams(['selected' => 'TaxSummary', 'month' => 'August'])
            ->test(Reports::class)
            ->assertSet('selected', 'TaxSummary')
            ->assertSet('month', 'August')
            ->assertSee('August');
    }

    public function test_the_panel_closes(): void
    {
        Livewire::test(Reports::class)
            ->call('select', 'BalanceSheet')
            ->call('deselect')
            ->assertSet('selected', null)
            ->assertDontSee('Open report');
    }

    /**
     * The assertion that is not cosmetic.
     *
     * `selected` is reachable from the browser, and the panel it opens renders that report's URL. A
     * key taken on trust would make this page a way to obtain a link to a report the role cannot
     * open — so anything not currently on offer is refused rather than shown.
     */
    public function test_an_unknown_key_opens_nothing(): void
    {
        Livewire::test(Reports::class)
            ->call('select', 'NotAReport')
            ->assertSet('selected', null)
            ->assertDontSee('Open in full page');
    }

    /**
     * The same refusal when the key arrives in the URL rather than from a click.
     *
     * `selected` is a #[Url] property now, so it is hydrated straight from the query string before any
     * method of this class runs — which would make `?selected=` a way around select()'s check if mount()
     * did not apply it too.
     */
    public function test_an_unknown_key_in_the_url_opens_nothing(): void
    {
        Livewire::test(Reports::class, ['selected' => 'NotAReport'])
            ->assertSet('selected', null);
    }

    /** And a real one does open, straight from the URL — the point of it being linkable. */
    public function test_a_report_can_be_opened_by_url(): void
    {
        Livewire::test(Reports::class, ['selected' => 'BalanceSheet'])
            ->assertSet('selected', 'BalanceSheet')
            ->assertSee('ASSETS');
    }

    public function test_a_report_from_a_disabled_module_cannot_be_opened(): void
    {
        CompanyModule::updateOrCreate(
            ['company_id' => $this->company->getKey(), 'module' => 'payroll'],
            ['licensed' => false, 'enabled' => false],
        );
        modules()->flush();

        // Tax Summary is a payroll report, and payroll is off — so it is not in the catalogue and
        // the panel must not open on it even when asked for by name.
        Livewire::test(Reports::class)
            ->call('select', 'TaxSummary')
            ->assertSet('selected', null);
    }

    /** A filter that hides the open report should not leave its panel floating over the results. */
    public function test_searching_closes_an_open_panel(): void
    {
        Livewire::test(Reports::class)
            ->call('select', 'BalanceSheet')
            ->set('query', 'payroll')
            ->assertSet('selected', null);
    }

    // ------------------------------------------------------------- the catalogue

    public function test_every_report_has_a_stable_key(): void
    {
        $catalogue = Reports::catalogue();

        $this->assertCount(Reports::total(), $catalogue);

        foreach ($catalogue as $key => $report) {
            // Keyed on the class basename, which is what travels in the URL: a renamed title must
            // not break a link somebody saved.
            $this->assertMatchesRegularExpression('/^[A-Za-z]+$/', $key);
            $this->assertSame($key, $report['key']);
            $this->assertNotEmpty($report['section']);
        }
    }
}
