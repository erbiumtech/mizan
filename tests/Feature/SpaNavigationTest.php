<?php

namespace Tests\Feature;

use App\Modules\Core\Models\Company;
use App\Modules\Core\Models\User;
use App\Modules\Employees\Filament\Resources\Employees\EmployeeResource;
use App\Support\TenantStorage;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Filament\Support\Facades\FilamentView;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\PermissionRegistrar;
use Tests\Concerns\InteractsWithTenant;
use Tests\TestCase;

/**
 * Soft navigation, and the URLs it must keep its hands off.
 *
 * The panel runs in SPA mode: a click swaps the body over fetch instead of
 * reloading the document, which is what keeps the 649KB theme parsed and Alpine,
 * Livewire and the Reverb socket alive between pages.
 *
 * The failure mode worth a test is the second half of that. A soft navigation
 * replaces the body with whatever came back, so a URL answering with a PDF or a
 * CSV leaves somebody on a blank screen holding a file the browser never offered
 * to save — and nothing errors, which is why this is asserted rather than
 * noticed. Every download in the application is listed in the panel's
 * spaUrlExceptions and named again below.
 *
 * See docs/page-load-performance-plan.md.
 */
class SpaNavigationTest extends TestCase
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

    public function test_the_admin_panel_navigates_without_reloading_the_document(): void
    {
        $this->assertTrue(
            FilamentView::hasSpaMode(),
            'SPA mode is off, so every click reloads the document and re-parses every asset',
        );
    }

    /**
     * Prefetching is off on purpose, and this says so out loud.
     *
     * `wire:navigate.hover` fetches a page when the pointer crosses its link. A
     * full render here costs ~25 statements before the page does any work of its
     * own (PanelPerformanceTest), so a sweep down the sidebar would pay that
     * several times for pages nobody opens. Turning it on is a decision to take
     * once the sidebar badge counts are cached — not a default to drift into.
     */
    public function test_hover_prefetching_stays_off_until_the_sidebar_is_cheaper(): void
    {
        $this->assertFalse(FilamentView::hasSpaPrefetching());
    }

    public function test_links_between_panel_pages_are_soft(): void
    {
        $this->assertTrue(FilamentView::hasSpaMode(EmployeeResource::getUrl('index')));

        // And the sidebar actually renders them that way — the setting above is
        // only worth anything through generate_href_html.
        $html = $this->get(EmployeeResource::getUrl('index'))->assertOk()->getContent();

        $this->assertStringContainsString('wire:navigate', $html);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function downloadUrls(): array
    {
        return [
            'export download' => ['http://localhost/filament/exports/1/download'],
            'failed import rows' => ['http://localhost/filament/imports/1/failed-rows/download'],
            'balance sheet PDF' => ['http://localhost/reports/1/balance-sheet'],
            'invoice PDF' => ['http://localhost/reports/1/invoice/1/pdf'],
            'payslip PDF' => ['http://localhost/api/my-payslips/1/pdf'],
            'company upload' => ['http://localhost/'.TenantStorage::URL_PREFIX.'/1/payslips/march.pdf'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('downloadUrls')]
    public function test_a_download_is_never_swapped_into_the_page(string $url): void
    {
        $this->assertFalse(
            FilamentView::hasSpaMode($url),
            "[{$url}] would be fetched and swapped into the body instead of downloaded — "
            .'add it to spaUrlExceptions in AdminPanelProvider',
        );
    }

    /**
     * A different panel is a different set of assets and a different navigation.
     * Swapping this panel's body for that one's runs new markup against JS booted
     * for neither.
     */
    public function test_the_platform_panel_is_reached_by_a_real_navigation(): void
    {
        $this->assertFalse(FilamentView::hasSpaMode('http://localhost/platform'));
        $this->assertFalse(FilamentView::hasSpaMode('http://localhost/platform/companies'));
    }

    /**
     * The exceptions are patterns, and a pattern wide enough to catch the PDF
     * statements at `/reports/{company}/...` is one segment away from catching the
     * Reports hub at `/admin/{company}/reports`. If that ever happens the hub
     * silently becomes the one page in the panel that reloads.
     */
    public function test_the_reports_hub_itself_still_navigates_softly(): void
    {
        $this->assertTrue(
            FilamentView::hasSpaMode(\App\Modules\Core\Filament\Pages\Reports::getUrl()),
        );
    }
}
