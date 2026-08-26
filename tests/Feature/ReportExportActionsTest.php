<?php

namespace Tests\Feature;

use App\Modules\Core\Filament\Pages\Reports;
use App\Modules\Core\Models\CompanyModule;
use App\Modules\Core\Models\FiscalYear;
use App\Modules\Core\Models\User;
use App\Modules\Employees\Models\Employee;
use App\Modules\Lifecycle\Filament\Pages\AssetsInHand;
use App\Modules\Lifecycle\Models\IssuedAsset;
use App\Support\Pdf\PdfDocument;
use App\Support\Reporting\ReportExport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;
use Tests\Concerns\InteractsWithTenant;
use Tests\TestCase;

/**
 * The export actions, on both doors — `docs/reports-expansion-plan.md` Phase 4.1.
 *
 * `ReportExportTest` covers the normaliser. This covers the wiring, and the reason it needs its own file is
 * that there are **two** places a report is read: the hub's pane and the report's own page. They render the
 * same payload — there is a test per report asserting exactly that — so an export on one and not the other
 * would be an arbitrary difference between two views of one thing.
 *
 * It also renders the PDF **template** rather than a PDF. Producing the document needs an engine, and which
 * engine is available depends on whether the machine has Node; the part that can actually be wrong is the
 * Blade, and that can be asserted without either.
 */
class ReportExportActionsTest extends TestCase
{
    use InteractsWithTenant;
    use RefreshDatabase;

    private const AS_OF = '2027-02-20';

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs(User::factory()->create(['status' => 1]));
        $this->setCurrentTenant();
        Gate::before(fn () => true);

        foreach (['lifecycle', 'employees', 'leave', 'advances', 'accounting'] as $module) {
            CompanyModule::updateOrCreate(
                ['company_id' => $this->tenant->getKey(), 'module' => $module],
                ['licensed' => true, 'enabled' => true],
            );
        }
        modules()->flush();

        FiscalYear::firstOrCreate(
            ['name' => '2026-2027'],
            ['start_date' => '2026-07-01', 'end_date' => '2027-06-30', 'is_active' => true],
        );
    }

    private function issuedAsset(): IssuedAsset
    {
        $employee = Employee::create([
            'employee_id' => 'EMP-1',
            'name' => 'Ayesha',
            'date_of_joining' => '2024-01-01',
            'status' => 1,
        ]);

        return IssuedAsset::create([
            'employee_id' => $employee->getKey(),
            'asset_kind' => 'laptop',
            'description' => 'ThinkPad T14',
            'serial_no' => 'PF-9K2LM',
            'issued_on' => '2026-08-01',
            'value' => 180_000,
        ]);
    }

    // ─────────────────────────────── on a report's own page ──

    /** The two actions are offered on the report's own page. */
    public function test_a_report_page_offers_both_exports(): void
    {
        $this->issuedAsset();

        Livewire::test(AssetsInHand::class, ['asOf' => self::AS_OF])
            ->assertSuccessful()
            ->assertActionVisible('exportReportCsv')
            ->assertActionVisible('exportReportPdf');
    }

    /**
     * And it still offers its own help button.
     *
     * The base page now assembles the header row and the subclass contributes to it, which is what let two
     * actions be added to thirty-three pages without editing thirty-three files. If the contribution were
     * dropped, every report would lose its help.
     */
    public function test_a_report_page_keeps_its_help_action(): void
    {
        $this->issuedAsset();

        Livewire::test(AssetsInHand::class, ['asOf' => self::AS_OF])
            ->assertSuccessful()
            ->assertActionVisible('help');
    }

    /** A report with nothing in it offers no export — an empty file is nobody's errand. */
    public function test_an_empty_report_offers_no_export(): void
    {
        Livewire::test(AssetsInHand::class, ['asOf' => self::AS_OF])
            ->assertSuccessful()
            ->assertActionHidden('exportReportCsv')
            ->assertActionHidden('exportReportPdf');
    }

    /** The CSV downloads, named for the report and the date it was drawn to. */
    public function test_the_csv_downloads_with_the_report_and_date_in_its_name(): void
    {
        $this->issuedAsset();

        $response = Livewire::test(AssetsInHand::class, ['asOf' => self::AS_OF])
            ->instance()
            ->downloadReportCsv();

        $this->assertSame(
            'attachment; filename=assets-in-employees-hands-2027-02-20.csv',
            $response->headers->get('Content-Disposition'),
        );
        $this->assertStringContainsString('text/csv', (string) $response->headers->get('Content-Type'));
    }

    /**
     * The file opens as UTF-8 in Excel on Windows, which needs a byte-order mark.
     *
     * Without one Excel reads the file as the local codepage, and these reports are full of em dashes and
     * middots — every one of which would arrive as mojibake in the one spreadsheet most likely to open it.
     */
    public function test_the_csv_carries_a_byte_order_mark(): void
    {
        $this->issuedAsset();

        $body = $this->downloadedBody();

        $this->assertStringStartsWith("\u{FEFF}", $body);
    }

    /** And the content is the report — the same rows the page shows. */
    public function test_the_downloaded_csv_holds_the_report(): void
    {
        $this->issuedAsset();

        $body = $this->downloadedBody();

        $this->assertStringContainsString("Assets in Employees' Hands", $body);
        $this->assertStringContainsString('PF-9K2LM', $body);
        // The value column, with its thousands separator undone for the spreadsheet.
        $this->assertStringContainsString('180000', $body);
        $this->assertStringNotContainsString('180,000', $body);
    }

    private function downloadedBody(): string
    {
        $response = Livewire::test(AssetsInHand::class, ['asOf' => self::AS_OF])
            ->instance()
            ->downloadReportCsv();

        ob_start();
        $response->sendContent();

        return (string) ob_get_clean();
    }

    // ────────────────────────────────────── on the hub's pane ──

    /**
     * The hub offers the same two actions once a report is open.
     *
     * The pane and the page render one payload, so this is the other half of the same guarantee.
     */
    public function test_the_hub_offers_both_exports_for_the_open_report(): void
    {
        $this->issuedAsset();

        Livewire::test(Reports::class, ['selected' => 'AssetsInHand', 'asOf' => self::AS_OF])
            ->assertSuccessful()
            ->assertActionVisible('exportReportCsv')
            ->assertActionVisible('exportReportPdf');
    }

    /** With nothing selected there is nothing to export. */
    public function test_the_hub_offers_no_export_with_nothing_selected(): void
    {
        Livewire::test(Reports::class, ['asOf' => self::AS_OF])
            ->assertSuccessful()
            ->assertActionHidden('exportReportCsv')
            ->assertActionHidden('exportReportPdf');
    }

    /** The hub keeps its own help action. */
    public function test_the_hub_keeps_its_help_action(): void
    {
        Livewire::test(Reports::class, ['asOf' => self::AS_OF])
            ->assertSuccessful()
            ->assertActionVisible('help');
    }

    /** And the hub's export is the same file the page's export is. */
    public function test_the_hub_and_the_page_export_the_same_thing(): void
    {
        $this->issuedAsset();

        $fromHub = app(ReportExport::class)->csv(
            Livewire::test(Reports::class, ['selected' => 'AssetsInHand', 'asOf' => self::AS_OF])
                ->instance()
                ->statement(),
        );

        $fromPage = app(ReportExport::class)->csv(
            Livewire::test(AssetsInHand::class, ['asOf' => self::AS_OF])->instance()->statement(),
        );

        $this->assertSame($fromHub, $fromPage);
    }

    // ────────────────────────────────────── the PDF template ──

    /**
     * The PDF template renders, with the grid in it.
     *
     * `html()` rather than a PDF: producing the document needs Browsershot or Dompdf and which one is
     * available depends on the machine, while the Blade is the part that can be wrong.
     */
    public function test_the_pdf_template_renders_the_report(): void
    {
        $this->issuedAsset();

        $statement = Livewire::test(AssetsInHand::class, ['asOf' => self::AS_OF])->instance()->statement();

        $html = (new PdfDocument('reports.pane-export', [
            'grid' => app(ReportExport::class)->grid($statement),
            'pdf' => true,
        ]))->html();

        $this->assertStringContainsString('Assets in Employees&#039; Hands', $html);
        $this->assertStringContainsString('PF-9K2LM', $html);
        // The PDF keeps the formatting the CSV strips, because a PDF is for reading.
        $this->assertStringContainsString('180,000', $html);
    }

    /**
     * A statement renders too, with its section headings marked.
     *
     * The template takes the heading rows from the exporter rather than guessing at them, so this asserts
     * the class reaches the markup.
     */
    public function test_the_pdf_template_marks_section_headings(): void
    {
        $html = (new PdfDocument('reports.pane-export', [
            'grid' => app(ReportExport::class)->grid([
                'kind' => 'ledger',
                'title' => 'Trial Balance',
                'subtitle' => 'Acme · as of 20 Feb 2027',
                'columns' => ['Account', 'Debit', 'Credit'],
                'numeric' => [1, 2],
                'sections' => [[
                    'label' => 'ASSET',
                    'rows' => [['code' => '1000', 'cells' => ['Cash', '150,000', '']]],
                    'total' => ['cells' => ['Total asset', '150,000', '0']],
                ]],
                'tiles' => [],
                'note' => 'BALANCED',
            ]),
            'pdf' => true,
        ]))->html();

        $this->assertStringContainsString('grid-section', $html);
        $this->assertStringContainsString('ASSET', $html);
        $this->assertStringContainsString('Total asset', $html);
    }

    /** An empty grid says so rather than rendering a headerless table. */
    public function test_the_pdf_template_handles_an_empty_grid(): void
    {
        $html = (new PdfDocument('reports.pane-export', [
            'grid' => app(ReportExport::class)->grid(['kind' => 'table', 'title' => 'Nothing', 'columns' => ['A']]),
            'pdf' => true,
        ]))->html();

        $this->assertStringContainsString('Nothing to show.', $html);
    }
}
