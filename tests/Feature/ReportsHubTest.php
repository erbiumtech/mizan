<?php

namespace Tests\Feature;

use App\Filament\Navigation\DomainNavigationManager;
use App\Modules\Core\Filament\Pages\Reports;
use App\Modules\Core\Models\Company;
use App\Modules\Core\Models\CompanyModule;
use App\Modules\Core\Models\User;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\PermissionRegistrar;
use Tests\Concerns\InteractsWithTenant;
use Tests\TestCase;

/**
 * The Reports group of fourteen sidebar entries is now one link to a hub page.
 *
 * Two things can quietly break that. A new report page can land in the sidebar
 * and bring the old group back; or it can be hidden from the sidebar and never
 * linked from the hub, which leaves a screen reachable only by the ⌘K palette
 * and its own URL. There is a test below for each.
 */
class ReportsHubTest extends TestCase
{
    use InteractsWithTenant;
    use RefreshDatabase;

    private function actAsSuperAdminOf(Company $company): User
    {
        $this->seed(PermissionSeeder::class);

        app(PermissionRegistrar::class)->setPermissionsTeamId($company->getKey());
        (new RoleSeeder)->run();

        $user = User::factory()->create(['is_super_admin' => true, 'status' => 1]);
        $company->users()->attach($user->getKey());
        $user->assignRole('Administrator');

        $this->actingAs($user);
        $this->setCurrentTenant($company);

        return $user;
    }

    /** @return array<string, array<int, string>> group label => item labels */
    private function navigation(): array
    {
        $navigation = [];

        // Unfiltered: the sidebar shows one domain at a time now (see NavigationDomains), and what
        // this file asserts is that the panel registers one Reports link and no Reports group —
        // a fact about the whole tree rather than about whichever domain is open.
        $groups = DomainNavigationManager::withoutFiltering(
            fn (): array => Filament::getPanel('admin')->getNavigation(),
        );

        foreach ($groups as $group) {
            $navigation[$group->getLabel() ?? ''] = collect($group->getItems())
                ->map(fn ($item): string => $item->getLabel())
                ->all();
        }

        return $navigation;
    }

    /** @return array<int, string> every link label on the hub, ungrouped */
    private function hubLabels(): array
    {
        return collect(Reports::sections())->flatten(1)->pluck('label')->all();
    }

    public function test_the_sidebar_has_one_reports_link_and_no_reports_group(): void
    {
        $this->actAsSuperAdminOf(Company::factory()->create());

        $navigation = $this->navigation();

        // The group is what was replaced. A new report page that sets
        // $navigationGroup = 'Reports' without hiding itself brings it back, and
        // the panel then has two ways in to the same fourteen screens.
        $this->assertArrayNotHasKey('Reports', $navigation);

        // Top level, next to the Dashboard, exactly once. Deliberately still an
        // exhaustive list rather than a contains-check: what this catches is a
        // new *report* arriving at top level instead of inside the hub, and that
        // only fails if an unexpected label here is an error. User Manual earns
        // its place beside Reports for the same reason Reports has one — it is a
        // door to everything rather than one more screen.
        $this->assertSame(['Dashboard', 'Reports', 'User Manual'], $navigation[''] ?? []);

        $everything = collect($navigation)->flatten()->all();
        $this->assertSame(1, collect($everything)->filter(fn (string $l) => $l === 'Reports')->count());
    }

    public function test_the_hub_links_to_every_report(): void
    {
        $this->actAsSuperAdminOf(Company::factory()->create());

        $this->assertSame([
            'Financial statements',
            'Receivables & payables',
            'Payroll & tax',
            // Separate from Payroll & tax on purpose: that section's FBR entry is
            // a file a human downloads and uploads, this one watches an
            // integration that reports on its own.
            'Statutory reporting',
            'Ledgers & books',
            'Bank files',
            // CRM's five (reports-expansion-plan.md Phase 1.2). After the financial sections because a
            // company opening the hub is usually there for the statements — and the section is declared
            // empty in ReportCatalogue rather than created on first registration, so this order is a
            // decision rather than a consequence of bootstrap/providers.php order.
            'Sales & pipeline',
            // Timesheets' two (Phase 1.4). Before Operations because these are read about people, and
            // Phase 2's payroll register and leave liability join them here.
            'People & payroll',
            // Support's SLA pair (Phase 1.3). Phase 3 fills this section with attendance, quotations and
            // campaigns; it is last because a company opening the hub is usually there for the statements.
            'Operations',
        ], array_keys(Reports::sections()));

        // Named rather than counted: a rename that dropped one would still count
        // the same. GnuCash Import is deliberately absent — it is an import, and
        // it lives in Settings now; NavigationGroupsTest holds that end of it.
        foreach ([
            'Balance Sheet', 'Profit & Loss', 'Cash Flow', 'Trial Balance', 'Budget vs Actual',
            'Fixed Asset Register', 'Bank Reconciliation Statement',
            'Aged Receivables', 'Aged Payables', 'Contractor Payments',
            'Revenue by Customer, Project and Product',
            'Tax Summary', 'FBR Tax File', 'Salary Bank File',
            'Credit Notes Issued',
            'FBR Invoice Reporting',
            'Account Register', 'Petty Cash Book', 'Loans Outstanding', 'Cash Commitments',
            'Bank Payment File',
            'Pipeline by Stage', 'Sales Forecast', 'Win / Loss', 'Rotting Deals', 'Target Attainment',
            'Quotation Conversion',
            'SLA Performance', 'SLA Breaches', 'Unbilled WIP', 'Stock on Hand',
            'Timesheet Utilisation', 'Plan vs Actual', 'Documents Expiring', 'Payroll Register', 'Leave Liability',
            'Advances Outstanding', 'Expense Claims', 'Attendance Register', 'Hiring Funnel',
        ] as $report) {
            $this->assertContains($report, $this->hubLabels());
        }

        $this->assertNotContains('GnuCash Import', $this->hubLabels());
    }

    /**
     * Deliberately the URL rather than a request to it. Whether a report renders
     * depends on that report's own data — Account Register aborts 404 until a
     * postable cash account exists, Petty Cash Book needs a float — and those are
     * each page's tests to write. What belongs to the hub is that a link goes to
     * its own page, inside the panel of the company the user is looking at.
     */
    public function test_every_link_points_at_its_own_page_in_the_current_tenant(): void
    {
        $company = Company::factory()->create();
        $this->actAsSuperAdminOf($company);

        $urls = collect(Reports::sections())->flatten(1)->pluck('url', 'label');

        foreach (Reports::linkedPages() as $page) {
            $label = (string) $page::getNavigationLabel();

            $this->assertSame($page::getUrl(), $urls[$label] ?? null, "[{$label}] does not link to {$page}");
            $this->assertStringContainsString("/admin/{$company->slug}/", (string) $urls[$label]);
        }
    }

    public function test_the_page_renders_its_links(): void
    {
        $this->actAsSuperAdminOf(Company::factory()->create());

        $page = Livewire::test(Reports::class)
            ->assertSuccessful()
            // The section is a filter chip now rather than a heading over a grid of cards, but it is
            // still on the page and still names the same set.
            ->assertSee('Financial statements')
            ->assertSee('Balance Sheet');

        // The descriptions are the reason the page exists rather than being a list of the same titles
        // the sidebar already had. 4c's list rows are two lines and have no room for one, so the
        // selected report's description is shown in the pane beside them — asserted here rather than
        // dropped, because it is the claim that matters.
        $page->call('select', 'BalanceSheet')
            ->assertSee('What the company owns, owes and is worth, on a date.');
    }

    /**
     * The Reports column names every report and links each to its own page.
     *
     * The column used to list the six categories and their counts alone, so the sidebar could say a company
     * had seventeen reports without naming one — and a report's own page, which is where its actions live,
     * was reachable only from inside the explorer.
     *
     * Asserted through a real request rather than through the page component, because the column is rendered
     * by the panel's own sidebar and a Livewire test of the page never draws it.
     */
    public function test_the_reports_column_lists_every_report_with_a_link_to_its_page(): void
    {
        $this->actAsSuperAdminOf(Company::factory()->create());

        $response = $this->get(Reports::getUrl())->assertOk();
        $html = html_entity_decode($response->getContent());

        foreach (Reports::sections() as $section => $links) {
            // The category still filters the explorer, which is a different destination on purpose.
            $this->assertStringContainsString($section, $html);

            foreach ($links as $link) {
                $this->assertStringContainsString(
                    'href="'.$link['url'].'"',
                    $html,
                    "the column does not link to {$link['label']}'s own page",
                );
            }
        }

        // Guards the loop: an empty catalogue would satisfy it.
        $this->assertGreaterThanOrEqual(17, Reports::total());
    }

    public function test_a_disabled_module_takes_its_reports_out_of_the_hub(): void
    {
        $company = Company::factory()->create();
        $this->actAsSuperAdminOf($company);

        CompanyModule::updateOrCreate(
            ['company_id' => $company->getKey(), 'module' => 'payroll'],
            ['licensed' => false, 'enabled' => false],
        );
        modules()->flush();

        $labels = $this->hubLabels();

        // Gone with their module — and the section they were the only members of
        // is gone with them, rather than left as an empty heading.
        foreach (['Tax Summary', 'FBR Tax File', 'Salary Bank File'] as $payrollReport) {
            $this->assertNotContains($payrollReport, $labels);
        }

        $this->assertArrayNotHasKey('Payroll & tax', Reports::sections());
        $this->assertContains('Balance Sheet', $labels);
    }

    public function test_a_role_without_report_permissions_never_sees_the_hub(): void
    {
        $company = Company::factory()->create();

        $this->seed(PermissionSeeder::class);
        app(PermissionRegistrar::class)->setPermissionsTeamId($company->getKey());
        (new RoleSeeder)->run();

        $user = User::factory()->create(['status' => 1]);
        $company->users()->attach($user->getKey());
        $user->assignRole('Employee');

        $this->actingAs($user);
        $this->setCurrentTenant($company);

        // Employee holds none of ReportView, JournalEntryView or PettyCashView, so
        // there is no report to link to — and a hub with an empty body is worse
        // than no link at all.
        $this->assertSame([], Reports::sections());
        $this->assertFalse(Reports::canAccess());
        $this->assertNotContains('Reports', collect($this->navigation())->flatten()->all());
    }

    /**
     * The guard that matters.
     *
     * Hiding a page from the sidebar is one line; remembering to link it from the
     * hub is a separate one, and nothing about the first prompts the second. Every
     * page that has taken itself out of the navigation must therefore be in the
     * hub's list — there is no legitimate exception today, so this asserts none.
     */
    public function test_every_page_hidden_from_the_sidebar_is_linked_from_the_hub(): void
    {
        $this->actAsSuperAdminOf(Company::factory()->create());

        $linked = Reports::linkedPages();
        $orphans = [];
        $examined = 0;

        foreach (Filament::getPages() as $page) {
            if ($page === Reports::class || $page::shouldRegisterNavigation()) {
                continue;
            }

            if (! $page::canAccess()) {
                continue;
            }

            $examined++;

            if (! in_array($page, $linked, true)) {
                $orphans[] = $page;
            }
        }

        $this->assertSame([], $orphans, implode("\n", [
            'These pages are hidden from the sidebar and not linked from the Reports hub,',
            'so nothing in the panel navigates to them — only the ⌘K palette and a URL',
            'somebody already knows. Add each to Reports::SECTIONS, or let it register',
            'navigation of its own.',
            '',
            ...$orphans,
        ]));

        // Guards the guard: the loop above passes trivially if the panel reports no
        // hidden pages at all, which is what a broken tenant or an unbooted panel
        // looks like from here.
        // A floor, not the count: pages legitimately move in and out of the hub
        // (GnuCash Import went to Settings), and this only needs to catch a scan
        // that found nothing.
        $this->assertGreaterThanOrEqual(10, $examined, 'the panel reported no pages hidden from the sidebar');
    }
}
