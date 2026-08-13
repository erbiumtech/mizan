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

        $page->assertSee('All reports');
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
            ->assertSee('All reports')
            ->assertSee('Balance Sheet')
            ->assertDontSee('Nothing matches');
    }

    /** The counts in the column describe the sections, so they do not move while somebody types. */
    public function test_the_section_counts_are_not_narrowed_by_the_search(): void
    {
        $before = Reports::sectionCounts();

        Livewire::test(Reports::class)->set('query', 'balance');

        $this->assertSame($before, Reports::sectionCounts());
    }

    // -------------------------------------------------------------- the layouts

    public function test_both_layouts_render_the_same_reports(): void
    {
        Livewire::test(Reports::class)
            ->set('display', 'list')
            ->assertSee('Balance Sheet')
            // The list view names the section per row, which the grid says once per group.
            ->assertSee('Financial statements')
            ->set('display', 'grid')
            ->assertSee('Balance Sheet');
    }

    // ------------------------------------------------------------- the run panel

    public function test_selecting_a_report_opens_its_panel(): void
    {
        Livewire::test(Reports::class)
            ->call('select', 'BalanceSheet')
            ->assertSet('selected', 'BalanceSheet')
            ->assertSee('What the company owns, owes and is worth, on a date.')
            ->assertSee('Open report');
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
            ->assertDontSee('Open report');
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
