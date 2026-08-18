<?php

namespace Tests\Feature;

use App\Modules\Accounting\Filament\Pages\GnuCashImport;
use App\Modules\Core\Filament\Pages\Reports;
use App\Modules\Core\Models\User;
use Database\Seeders\ChartOfAccountsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;
use Tests\Concerns\InteractsWithTenant;
use Tests\TestCase;

class FilamentReportPagesSmokeTest extends TestCase
{
    use InteractsWithTenant;
    use RefreshDatabase;

    public function test_all_report_pages_render(): void
    {
        Gate::before(fn () => true);
        $this->seed(ChartOfAccountsSeeder::class);
        $user = User::factory()->create();
        $this->actingAs($user);

        // With a tenant, because the panel is tenant-scoped and there is no such
        // thing as one of its pages rendering without one. These pages link out to
        // the report routes, which now name the company in the path — a render
        // with no tenant cannot build those URLs, and neither can a browser.
        $this->setCurrentTenant();

        $pages = $this->reportPages();

        $failures = [];
        foreach ($pages as $page) {
            try {
                Livewire::test($page)->assertSuccessful();
            } catch (\Throwable $e) {
                $failures[] = class_basename($page).' → '.$e->getMessage();
            }
        }

        if ($failures) {
            $this->fail("Report page render failures:\n - ".implode("\n - ", $failures));
        }

        $this->addToAssertionCount(1);
    }

    public function test_report_pages_respect_permission_gates(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        foreach ($this->reportPages() as $page) {
            $this->assertFalse($page::canAccess(), class_basename($page).' is reachable without ReportView');
        }
    }

    /**
     * The report pages, taken from the hub rather than listed here.
     *
     * This file used to name eight classes literally, and the cost of that was invisible: a report added
     * afterwards rendered in no test at all until somebody remembered to add it, and the General Ledger
     * was the ninth. `Reports::linkedPages()` is the same list the hub renders and the same one
     * ReportsHubTest checks for completeness, so a new report now arrives with a render test and a
     * permission test whether or not anybody thinks to write one.
     *
     * GnuCashImport is added by hand because it is the exception: it used to sit in the hub and was moved
     * to Settings for being an import rather than a report (see Reports::SECTIONS), so it is no longer in
     * `linkedPages()` and is still a page this test should keep rendering.
     *
     * @return array<int, class-string<\Filament\Pages\Page>>
     */
    private function reportPages(): array
    {
        $pages = [...Reports::linkedPages(), GnuCashImport::class];

        // Guards the guard: an empty catalogue would make both tests above pass without rendering
        // anything, which is exactly the silence this file exists to prevent.
        $this->assertGreaterThanOrEqual(18, count($pages), 'the report catalogue has shrunk');

        return $pages;
    }
}
