<?php

namespace Tests\Feature;

use App\Support\Pdf\Pdf;
use App\Support\TenantSettings;
use Illuminate\Support\Facades\Storage;
use Tests\AccountingTestCase;
use Tests\Concerns\InteractsWithTenant;

/**
 * The monthly progress report is this company's document.
 *
 * It used to style itself from the Tailwind browser CDN — a <script> Dompdf never runs and Chrome waits
 * the whole PDF timeout for on a server that cannot reach out — and printed one particular company's logo
 * (by URL), name and Basel address on every tenant's report. It now carries the company's own letterhead
 * and brand colour and fetches nothing, under either engine.
 */
class MprReportIdentityTest extends AccountingTestCase
{
    use InteractsWithTenant;

    private const PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==';

    protected function setUp(): void
    {
        parent::setUp();

        // Filament sets a tenant for a signed-in user; nobody signed in is a state the panel never has.
        $this->actingAs($this->makeUser('Administrator', 'mpr-identity@test.local'));
        $this->setCurrentTenant();
    }

    private function html(string $engine, string $mode = 'single'): string
    {
        config(['pdf.driver' => $engine]);

        $fields = ['User Name' => 'Ada Lovelace', 'MPR Date' => '01-Jul-2026', 'Progress' => "line one\nline two"];

        return Pdf::view('pdfs.mpr-report', $mode === 'single'
            ? ['mode' => 'single', 'reportFields' => $fields, 'contentLabels' => [1 => 'Progress']]
            : ['mode' => 'comparison', 'previous' => $fields, 'latest' => $fields, 'contentLabels' => [1 => 'Progress']],
        )->html();
    }

    public function test_it_prints_the_company_letterhead_and_brand_colour(): void
    {
        Storage::fake('public');
        Storage::disk('public')->put('branding/logo.png', base64_decode(self::PNG));

        $settings = app(TenantSettings::class);
        $settings->set('company.legal_name', 'Northwind Traders (Pvt) Ltd');
        $settings->set('company.address', "12 Mall Road\nLahore");
        $settings->set('company.ntn', '1234567-8');
        $settings->set('company.logo_path', 'branding/logo.png');
        $settings->set('company.brand.colour', '#7c3aed');

        foreach (['dompdf', 'browsershot'] as $engine) {
            foreach (['single', 'comparison'] as $mode) {
                $html = $this->html($engine, $mode);

                $this->assertStringContainsString('Northwind Traders (Pvt) Ltd', $html, "$engine/$mode");
                $this->assertStringContainsString('12 Mall Road, Lahore', $html, "$engine/$mode");
                $this->assertStringContainsString('NTN 1234567-8', $html, "$engine/$mode");
                $this->assertStringContainsString('data:image/png;base64,', $html, "$engine/$mode");
                $this->assertStringContainsString('#7c3aed', $html, "$engine/$mode");
            }
        }
    }

    /** Nothing fetched and nobody else's identity, whatever the letterhead holds. */
    public function test_it_fetches_nothing_and_names_no_other_company(): void
    {
        foreach (['dompdf', 'browsershot'] as $engine) {
            foreach (['single', 'comparison'] as $mode) {
                $html = $this->html($engine, $mode);

                foreach (['<script', 'http://', 'https://', '4sure', 'erbium', 'Basel', 'CHE-193'] as $needle) {
                    $this->assertStringNotContainsStringIgnoringCase($needle, $html, "$engine/$mode still contains [$needle]");
                }
            }
        }
    }

    /** A blank letterhead still prints a report, named after the tenant, with no image and no address line. */
    public function test_a_blank_letterhead_falls_back_to_the_tenant_name(): void
    {
        $html = $this->html('dompdf');

        $this->assertStringContainsString(e($this->tenant->name), $html);
        $this->assertStringNotContainsString('data:image', $html);
    }

    public function test_it_renders_a_pdf_under_dompdf_in_both_modes(): void
    {
        config(['pdf.driver' => 'dompdf']);

        foreach (['single', 'comparison'] as $mode) {
            $fields = ['User Name' => 'Ada Lovelace', 'MPR Date' => '01-Jul-2026', 'Progress' => 'Done'];
            $raw = Pdf::view('pdfs.mpr-report', $mode === 'single'
                ? ['mode' => 'single', 'reportFields' => $fields, 'contentLabels' => [1 => 'Progress']]
                : ['mode' => 'comparison', 'previous' => $fields, 'latest' => $fields, 'contentLabels' => [1 => 'Progress']],
            )->raw();

            $this->assertStringStartsWith('%PDF-', $raw, $mode);
        }
    }
}
