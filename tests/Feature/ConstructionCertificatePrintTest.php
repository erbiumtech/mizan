<?php

namespace Tests\Feature;

use App\Modules\Construction\Models\Job;
use App\Modules\ConstructionContracts\Models\CertificateLine;
use App\Modules\ConstructionContracts\Models\Contract;
use App\Modules\ConstructionContracts\Models\PaymentCertificate;
use App\Modules\ConstructionContracts\Services\ContractService;
use App\Modules\ConstructionContracts\Support\CertificateSchedule;
use App\Modules\ConstructionContracts\Support\ContractVocabulary;
use App\Modules\Core\Models\CompanyModule;
use App\Support\Pdf\NodeRuntime;
use App\Support\Pdf\Pdf;
use Tests\AccountingTestCase;
use Tests\Concerns\InteractsWithTenant;

/**
 * The printed certificate — `docs/construction-management-plan.md` §8.4 and §10.5, Phase 4e.
 *
 * **This file is Phase 4's stated exit condition**: "the two worked examples of §8.4 reproducing identically under
 * both PDF engines — which is the assertion, not the illustration". Both examples are built from the figures §8.4
 * gives and the printed document is asserted against them, and then the same document is rendered under each engine
 * and compared.
 *
 * What "identically" can and cannot mean is worth stating plainly, because the difference is the whole of §10.5's
 * argument. The PDF *bytes* differ by engine — one is Chrome's writer and the other is Dompdf's — and no test could
 * assert otherwise. What must be identical is the **document**: the same pages, the same rows on each page, the same
 * brought-forward and carried-forward figures, the same "page n of m". That is what a reissue means, and it holds
 * here because the pagination is decided in PHP before either engine sees anything. The only difference between the
 * two renderings is a stylesheet in the head, which is exactly what §10.5 promises: "the fallback is a stylesheet,
 * never a different document".
 */
class ConstructionCertificatePrintTest extends AccountingTestCase
{
    use InteractsWithTenant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs($this->makeUser('Administrator', 'print@test.local'));
        $this->setCurrentTenant();

        foreach (['construction', 'construction_contracts', 'invoicing'] as $module) {
            CompanyModule::updateOrCreate(
                ['company_id' => $this->tenant->getKey(), 'module' => $module],
                ['licensed' => true, 'enabled' => true],
            );
        }
        modules()->flush();

        NodeRuntime::flush();
    }

    protected function tearDown(): void
    {
        NodeRuntime::flush();

        parent::tearDown();
    }

    /**
     * §8.4's contract: a remeasured FIDIC Red job, sum 500,000,000, retention 10% capped at 5% of the sum,
     * advance 50,000,000 recovered at 25% once certified value passes 10%.
     */
    private function fidicContract(): Contract
    {
        $job = Job::create(['code' => 'J-8-4', 'name' => 'Remeasured works']);

        $contracts = app(ContractService::class);
        $contract = $contracts->create($job, [
            'title' => 'Main works',
            'contract_standard' => ContractVocabulary::FIDIC,
            'fidic_book' => 'red',
            'measurement_basis' => Contract::BASIS_REMEASURED,
            'contract_sum' => 500_000_000,
            'retention_percent' => 10,
            'retention_limit_percent' => 5,
            'advance_payment_amount' => 50_000_000,
            'advance_recovery_start_pct' => 10,
            'advance_recovery_rate_pct' => 25,
            'payment_terms_days' => 56,
        ]);

        $contracts->addItem($contract, [
            'item_no' => '2.1', 'description' => 'Reinforced concrete to substructure',
            'unit' => 'm3', 'quantity' => 5_000, 'rate' => 20_000,
        ]);
        $contracts->addItem($contract, [
            'item_no' => '2.2', 'description' => 'Structural steelwork to frame, fabricated and erected',
            'unit' => 't', 'quantity' => 1_600, 'rate' => 250_000,
        ]);

        $contracts->execute($contract);

        return $contract->refresh();
    }

    /**
     * §8.4's certificate 7, written with the header figures and the four deduction rows the plan states.
     *
     * Built directly rather than by certifying seven months of claims: the worked example *is* a set of stated
     * figures, and what this file has to prove is that the printed document reproduces them and that the bottom
     * line comes out at 22,360,000. Deriving the seven months would test the certification service, which
     * `ConstructionCertificationTest` already does.
     */
    private function fidicCertificateSeven(): PaymentCertificate
    {
        $contract = $this->fidicContract();

        $certificate = PaymentCertificate::create([
            'contract_id' => $contract->getKey(),
            'certificate_number' => 'IPC-7',
            'sequence' => 7,
            'period_end' => '2026-06-30',
            'contract_sum_original' => 500_000_000,
            // **Agreed** variations only, which is the figure §8.4 names.
            'variations_net_to_date' => 13_020_000,
            'gross_work_to_date' => 212_400_000,
            'gross_materials_to_date' => 3_200_000,
            'gross_value_to_date' => 215_600_000,
            'retention_to_date' => 21_560_000,
            'previously_certified' => 184_900_000,
            // The gross the movement rows net against. §8.4's own arithmetic implies this figure:
            // 215,600,000 − 2,140,000 − 5,350,000 − 850,000 − 22,360,000 = 184,900,000. The plan's
            // numbers were written for movement rows all along; only the label said otherwise.
            'previous_gross_value_to_date' => 184_900_000,
        ]);

        foreach ([
            ['retention', 'Retention @ 10% (cl. 14.3)', -2_140_000],
            ['advance_recovery', 'Advance payment recovery @ 25% (cl. 14.2)', -5_350_000],
            ['ncr', 'Deduction under clause 14.6, NCR-0012', -850_000],
            ['previous_certificates', 'Less previously certified', -184_900_000],
        ] as [$kind, $description, $amount]) {
            $certificate->deductions()->create([
                'kind' => $kind,
                'description' => $description,
                'amount' => $amount,
                'is_automatic' => $kind !== 'ncr',
            ]);
        }

        $certificate->update(['current_due' => $certificate->refresh()->currentDue()]);

        return $certificate->refresh();
    }

    /** The same figures on an AIA contract, which is what makes §8.4's claim a claim about one set of tables. */
    private function aiaCertificate(): PaymentCertificate
    {
        $job = Job::create(['code' => 'J-AIA', 'name' => 'Fit-out']);

        $contracts = app(ContractService::class);
        $contract = $contracts->create($job, [
            'title' => 'Interior fit-out',
            'contract_standard' => ContractVocabulary::AIA,
            'contract_sum' => 500_000_000,
            'retention_percent' => 10,
        ]);

        // A Schedule of Values line: number, description and value, with no quantity and no rate (§8.2).
        $contracts->addItem($contract, [
            'item_no' => '03 30 00', 'description' => 'Cast-in-place concrete',
            'scheduled_value' => 300_000_000,
        ]);
        $contracts->addItem($contract, [
            'item_no' => '05 12 00', 'description' => 'Structural steel framing',
            'scheduled_value' => 200_000_000,
        ]);
        $contracts->execute($contract);

        $certificate = PaymentCertificate::create([
            'contract_id' => $contract->refresh()->getKey(),
            'certificate_number' => 'APP-7',
            'sequence' => 7,
            'period_end' => '2026-06-30',
            'contract_sum_original' => 500_000_000,
            'variations_net_to_date' => 13_020_000,
            'gross_work_to_date' => 212_400_000,
            'gross_materials_to_date' => 3_200_000,
            'gross_value_to_date' => 215_600_000,
            'retention_to_date' => 21_560_000,
            'previously_certified' => 184_900_000,
            // The gross the movement rows net against. §8.4's own arithmetic implies this figure:
            // 215,600,000 − 2,140,000 − 5,350,000 − 850,000 − 22,360,000 = 184,900,000. The plan's
            // numbers were written for movement rows all along; only the label said otherwise.
            'previous_gross_value_to_date' => 184_900_000,
        ]);

        foreach ($contract->items as $index => $item) {
            $certificate->lines()->create([
                'contract_item_id' => $item->getKey(),
                'item_no' => $item->item_no,
                'description' => $item->description,
                'scheduled_value' => $item->scheduled_value,
                'previous_work_value' => $index === 0 ? 120_000_000 : 72_000_000,
                'cumulative_work_value' => $index === 0 ? 130_000_000 : 82_400_000,
                'cumulative_materials_value' => $index === 0 ? 3_200_000 : 0,
                'line_retention' => $index === 0 ? 13_320_000 : 8_240_000,
            ]);
        }

        // No advance recovery row: the one figure FIDIC uses that AIA does not is a *row*, so an AIA
        // certificate simply has none (§8.4).
        foreach ([
            ['retention', 'Retainage @ 10%', -2_140_000],
            ['ncr', 'Deduction, non-conforming work', -850_000],
            ['previous_certificates', 'Less previous certificates', -184_900_000],
        ] as [$kind, $description, $amount]) {
            $certificate->deductions()->create([
                'kind' => $kind, 'description' => $description, 'amount' => $amount,
            ]);
        }

        $certificate->update(['current_due' => $certificate->refresh()->currentDue()]);

        return $certificate->refresh();
    }

    /** @return array<string, mixed> */
    private function viewData(PaymentCertificate $certificate): array
    {
        return [
            'certificate' => $certificate,
            'columns' => CertificateSchedule::columnsFor($certificate->contract->contract_standard),
            'pages' => CertificateSchedule::paginate($certificate->lines->sortBy('item_no')->values()),
        ];
    }

    private function html(PaymentCertificate $certificate, string $driver): string
    {
        config(['pdf.driver' => $driver]);

        return Pdf::view('pdfs.construction-certificate', $this->viewData($certificate))->html();
    }

    // ------------------------------------------------------- §8.4, the FIDIC example

    /**
     * **The exit condition, first half.** The FIDIC certificate's five header figures and its four deduction rows
     * produce 22,360,000, and the printed page says so.
     */
    public function test_the_fidic_worked_example_prints_its_stated_figures(): void
    {
        $certificate = $this->fidicCertificateSeven();

        // 215,600,000 − 2,140,000 − 5,350,000 − 850,000 − 184,900,000.
        $this->assertSame(22_360_000.0, $certificate->currentDue());

        $html = $this->html($certificate, 'dompdf');

        foreach ([
            'IPC-7',
            'Interim Payment Certificate',
            '500,000,000.00',   // line 1, original contract sum
            '13,020,000.00',    // line 2, net change by agreed variations
            '513,020,000.00',   // line 3, contract sum to date — derived
            '215,600,000.00',   // line 4, total completed and stored
            '21,560,000.00',    // line 5, total retention held
            '184,900,000.00',   // line 7, less previously certified
            '22,360,000.00',    // line 8, current payment due
            'Retention @ 10% (cl. 14.3)',
            'Advance payment recovery @ 25% (cl. 14.2)',
            'NCR-0012',
        ] as $expected) {
            $this->assertStringContainsString($expected, $html, "the printed certificate does not show {$expected}");
        }
    }

    /** And it prints FIDIC's vocabulary, not AIA's — the whole of what the standard drives (§8.3). */
    public function test_the_fidic_certificate_speaks_fidic(): void
    {
        $html = $this->html($this->fidicCertificateSeven(), 'dompdf');

        $this->assertStringContainsString('Interim Payment Certificate', $html);
        $this->assertStringContainsString('Engineer', $html);
        $this->assertStringContainsString('variations', $html);
        $this->assertStringNotContainsString('Change Order', $html);
    }

    // --------------------------------------------------------- §8.4, the AIA example

    /**
     * **The exit condition, second half.** The same five header figures print the same bottom line on the AIA
     * form, off the same columns — and its continuation sheet carries §8.4's column mapping.
     */
    public function test_the_aia_worked_example_reads_off_the_same_columns(): void
    {
        $certificate = $this->aiaCertificate();

        // 215,600,000 − 2,140,000 − 850,000 − 184,900,000. No advance row on this form.
        $this->assertSame(27_710_000.0, $certificate->currentDue());

        $html = $this->html($certificate, 'dompdf');

        $this->assertStringContainsString('Certificate for Payment', $html);
        $this->assertStringContainsString('Architect', $html);
        // Line 2 names change orders on this form and variations on the other, which is the vocabulary doing
        // its one job.
        $this->assertStringContainsString('change orders', $html);
        $this->assertStringNotContainsString('Advance payment recovery', $html);

        // The eleven columns of §8.4's G703 mapping, by their letters.
        foreach (['Scheduled value', 'From previous applications', 'This period', 'Materials stored',
            'Total completed and stored', 'Balance to finish', 'Retainage'] as $head) {
            $this->assertStringContainsString($head, $html, "column {$head} is missing from the continuation sheet");
        }

        // Column G on the first line: D + E + F = 130,000,000 + 3,200,000.
        $this->assertStringContainsString('133,200,000.00', $html);
        // Column H: C − G = 300,000,000 − 133,200,000.
        $this->assertStringContainsString('166,800,000.00', $html);
    }

    /**
     * The legal note §10.5 requires, asserted on the output rather than trusted to a comment.
     *
     * The form reproduces the content and column structure under our own title. A document titled "AIA Document
     * G702" is a licensing problem, and it is exactly what somebody makes it look like when trying to be helpful.
     */
    public function test_the_printed_form_is_not_titled_as_the_copyrighted_document(): void
    {
        $html = $this->html($this->aiaCertificate(), 'dompdf');

        $this->assertStringNotContainsString('AIA Document', $html);
        $this->assertStringNotContainsString('G702', $html);
        $this->assertStringNotContainsString('G703', $html);
        $this->assertStringContainsString('Continuation sheet', $html);
    }

    // ------------------------------------------------------------------ pagination

    /**
     * §10.5's server-side chunking, which is what makes the document engine-independent.
     *
     * Fifty lines at twenty-two rows a page is three pages, each with its own header, and the running totals chain
     * through them: page two's brought-forward is page one's carried-forward.
     */
    public function test_the_continuation_sheet_chunks_into_pages_with_running_totals(): void
    {
        $lines = collect(range(1, 50))->map(fn (int $n): CertificateLine => new CertificateLine([
            'item_no' => sprintf('4.%03d', $n),
            'description' => "Blockwork to level {$n}",
            'scheduled_value' => 100_000,
            'previous_work_value' => 40_000,
            'cumulative_work_value' => 60_000,
            'cumulative_materials_value' => 0,
            'line_retention' => 6_000,
        ]));

        $pages = CertificateSchedule::paginate($lines);

        $this->assertCount(3, $pages);
        $this->assertSame(22, count($pages[0]['rows']));
        $this->assertSame(6, count($pages[2]['rows']));

        // Nothing has been brought forward onto page one.
        $this->assertNull($pages[0]['brought_forward']);
        $this->assertSame(2_200_000.0, $pages[0]['carried_forward']['scheduled_value']);

        // And page two starts where page one finished.
        $this->assertSame($pages[0]['carried_forward'], $pages[1]['brought_forward']);
        $this->assertSame(4_400_000.0, $pages[1]['carried_forward']['scheduled_value']);

        // The last page's carried-forward is the grand total: fifty lines at 100,000.
        $this->assertTrue($pages[2]['is_last']);
        $this->assertSame(5_000_000.0, $pages[2]['carried_forward']['scheduled_value']);
        $this->assertSame(300_000.0, $pages[2]['carried_forward']['line_retention']);
    }

    /**
     * A long description spills onto a continuation row, and **rows are counted rather than lines**.
     *
     * Dompdf has no usable `text-overflow`, so the choice is a character budget or a description silently cut off
     * on the client's copy. Counting rows is what keeps a two-row line from being split by a page break.
     */
    public function test_a_long_description_gets_a_continuation_row_and_is_never_split(): void
    {
        $long = 'Supply, fabricate and erect structural steelwork including all connections, '
            .'shop priming, site touch-up and the temporary works necessary for erection';

        [$head, $continuation] = CertificateSchedule::split($long);

        $this->assertLessThanOrEqual(CertificateSchedule::DESCRIPTION_BUDGET, mb_strlen($head));
        $this->assertNotNull($continuation);
        // Split on a word boundary: a break mid-word reads as a fault in the document.
        $this->assertStringEndsNotWith(' ', $head);
        $this->assertFalse(str_contains($head, 'connectio'), 'the head should not end mid-word');

        // Two lines with long descriptions occupy four rows, so a page of three rows holds one of them and
        // carries the other over whole.
        $lines = collect([
            new CertificateLine(['item_no' => '1', 'description' => $long, 'scheduled_value' => 10]),
            new CertificateLine(['item_no' => '2', 'description' => $long, 'scheduled_value' => 20]),
        ]);

        $pages = CertificateSchedule::paginate($lines, rowsPerPage: 3);

        $this->assertCount(2, $pages);
        $this->assertCount(1, $pages[0]['rows']);
        $this->assertNotNull($pages[0]['rows'][0]['continuation'], 'the continuation stays with its line');
    }

    /** A certificate with no lines prints its summary and no continuation sheet at all. */
    public function test_a_certificate_with_no_lines_prints_no_continuation_sheet(): void
    {
        $html = $this->html($this->fidicCertificateSeven(), 'dompdf');

        $this->assertStringContainsString('IPC-7', $html);
        $this->assertStringNotContainsString('Continuation sheet', $html);
    }

    // ------------------------------------------------- the same document, either engine

    /**
     * **The assertion Phase 4 ends on.** The same document under both engines.
     *
     * The PDF bytes differ — one writer is Chrome's and the other Dompdf's — so what is compared is the document:
     * the body markup, which carries every figure, every page break and every "page n of m". The only difference
     * between the two renderings is the Dompdf stylesheet in the head, which is what §10.5 promises.
     */
    public function test_the_document_is_identical_under_both_engines(): void
    {
        foreach ([$this->fidicCertificateSeven(), $this->aiaCertificate()] as $certificate) {
            $dompdf = $this->html($certificate, 'dompdf');
            $browsershot = $this->html($certificate, 'browsershot');

            $this->assertSame(
                $this->body($browsershot),
                $this->body($dompdf),
                "{$certificate->certificate_number} paginates or prints differently depending on the engine",
            );

            // And the difference between them really is only the stylesheet. Asserted on the CSS the partial
            // emits rather than on its comment, because Blade strips `{{-- --}}` before anything is rendered.
            $this->assertStringContainsString('display: block !important', $dompdf);
            $this->assertStringNotContainsString('display: block !important', $browsershot);
        }
    }

    /** The same holds for a long sheet, where the pagination is the thing that could differ. */
    public function test_a_multi_page_sheet_paginates_identically_under_both_engines(): void
    {
        $certificate = $this->aiaCertificate();

        // Thirty more lines, so the sheet runs to two pages. Each needs its own schedule line, because one
        // certificate line per schedule line is a unique index rather than a convention — two rows against one
        // item would double that item on the printed sheet.
        //
        // Written straight onto the model rather than through `ContractService::addItem()`, which refuses an
        // executed contract: after execution a new line is a variation, and this is a print fixture rather than
        // a claim that the schedule may grow.
        foreach (range(1, 30) as $n) {
            $item = \App\Modules\ConstructionContracts\Models\ContractItem::create([
                'contract_id' => $certificate->contract_id,
                'item_no' => sprintf('09 %03d 00', $n),
                'description' => "Finishes to level {$n}",
                'scheduled_value' => 1_000_000,
                'sort' => 100 + $n,
            ]);

            $certificate->lines()->create([
                'contract_item_id' => $item->getKey(),
                'item_no' => $item->item_no,
                'description' => $item->description,
                'scheduled_value' => 1_000_000,
                'previous_work_value' => 400_000,
                'cumulative_work_value' => 600_000,
                'line_retention' => 60_000,
            ]);
        }

        $certificate->refresh()->load('lines');

        $pages = CertificateSchedule::paginate($certificate->lines->sortBy('item_no')->values());
        $this->assertGreaterThan(1, count($pages), 'the fixture needs to span more than one page to prove anything');

        $this->assertSame(
            $this->body($this->html($certificate, 'browsershot')),
            $this->body($this->html($certificate, 'dompdf')),
        );

        // The page count is printed from the chunk index, so it needs no engine feature at all.
        $this->assertStringContainsString(
            'page 1 of '.count($pages),
            $this->html($certificate, 'dompdf'),
        );
    }

    /** Everything below the head, which is where the two renderings are allowed to differ. */
    private function body(string $html): string
    {
        $at = strpos($html, '<body>');

        return $at === false ? $html : substr($html, $at);
    }

    // ------------------------------------------------------------------ the route

    public function test_the_pdf_route_renders_for_somebody_who_may_see_the_certificate(): void
    {
        config(['pdf.driver' => 'dompdf']);

        $certificate = $this->fidicCertificateSeven();

        $response = $this->get(route('construction.certificate.pdf', [
            'company' => $this->tenant->slug,
            'certificate' => $certificate->getKey(),
        ]));

        $response->assertSuccessful();
        // Inline rather than streamed: the certificate opens in the tab it was asked for from, and ?download=1
        // keeps a copy — the same convention as the invoice PDF.
        $this->assertSame('application/pdf', $response->headers->get('Content-Type'));
        $this->assertStringStartsWith('%PDF', $response->getContent());
    }

    /** The orientation follows the column set: eleven columns need landscape, FIDIC's summary does not. */
    public function test_the_aia_sheet_prints_landscape_and_the_fidic_one_portrait(): void
    {
        config(['pdf.driver' => 'dompdf']);

        $controller = app(\App\Modules\ConstructionContracts\Http\Controllers\CertificatePdfController::class);

        $aia = $controller($this->requestAsCurrentUser(), $this->tenant->slug, $this->aiaCertificate()->getKey());
        $fidic = $controller($this->requestAsCurrentUser(), $this->tenant->slug, $this->fidicCertificateSeven()->getKey());

        $this->assertSame('landscape', $this->orientationOf($aia));
        $this->assertSame('portrait', $this->orientationOf($fidic));
    }

    /**
     * A request carrying the acting user.
     *
     * `request()` outside an HTTP call has no user resolver, so the controller's authorisation check would fail on
     * null rather than on a permission — which would make this test pass or fail for the wrong reason.
     */
    private function requestAsCurrentUser(): \Illuminate\Http\Request
    {
        $request = \Illuminate\Http\Request::create('/');
        $request->setUserResolver(fn () => auth()->user());

        return $request;
    }

    private function orientationOf(\App\Support\Pdf\PdfDocument $pdf): string
    {
        $property = new \ReflectionProperty($pdf, 'orientation');

        return $property->getValue($pdf);
    }
}
