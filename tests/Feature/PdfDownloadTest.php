<?php

namespace Tests\Feature;

use App\Support\Pdf\NodeRuntime;
use App\Support\Pdf\PdfDocument;
use Livewire\Features\SupportFileDownloads\SupportFileDownloads;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Tests\TestCase;

/**
 * A PDF download is bytes in a browser's Downloads folder, and this is the test that says so.
 *
 * **Written after a production report of a 0-byte payslip.** The cause was not the engine: Dompdf renders
 * perfectly well. It was that `PdfDocument::toResponse()` returned a *different kind of response* per engine —
 * a plain `Response` under Browsershot, a `StreamedResponse` under Dompdf — and each shape broke a different
 * caller:
 *
 *  - `StreamedResponse::getContent()` is `false` by contract, so the payslip action, which echoed it, echoed
 *    nothing. On production, where `pdf.driver=auto` falls back to Dompdf for want of Node, every payslip
 *    downloaded as 0 bytes. On the machine it was written on, Node was installed and it worked.
 *  - Livewire only turns a `StreamedResponse` or a `BinaryFileResponse` into a download, so the *plain*
 *    response the other engine produced was silently discarded — the report export buttons did nothing at all
 *    on any host with Node.
 *
 * One fault, two symptoms, and each host only ever saw one of them. So what is asserted here is the property
 * that makes both impossible: **the response does not change shape with the engine, and its body is the PDF.**
 *
 * Dompdf is what these run under, deliberately: it is what production uses, it needs no browser, and the
 * engine is not what is under test — the plumbing between the engine and the browser is.
 */
class PdfDownloadTest extends TestCase
{
    /** A payload the export template accepts, small enough to render in milliseconds. */
    private const GRID = [
        'title' => 'Aged Receivables',
        'subtitle' => 'Acme · as of 20 Feb 2027',
        'note' => 'TOTAL 1,000',
        'tiles' => [],
        'columns' => ['Customer', 'Total'],
        'rows' => [['Acme', '1,000']],
        'footer' => null,
        'numeric' => [1],
        'sections' => [],
    ];

    protected function setUp(): void
    {
        parent::setUp();

        // What production runs, and what the bug needed in order to appear.
        config(['pdf.driver' => 'dompdf']);
        NodeRuntime::flush();
    }

    protected function tearDown(): void
    {
        NodeRuntime::flush();

        parent::tearDown();
    }

    private function pdf(): PdfDocument
    {
        return new PdfDocument('reports.pane-export', ['grid' => self::GRID, 'pdf' => true]);
    }

    /** The engine produces a document, which is the floor everything below stands on. */
    public function test_the_engine_renders_a_pdf(): void
    {
        $bytes = $this->pdf()->raw();

        $this->assertNotSame('', $bytes);
        $this->assertStringStartsWith('%PDF', $bytes, 'that is not a PDF');
    }

    /**
     * A download is a `StreamedResponse`, because that is the only shape Livewire will download.
     *
     * The assertion is the *type* as much as the content: a plain response here is a button that does
     * nothing, and nothing about it looks broken from the outside.
     */
    public function test_a_download_is_a_streamed_response_carrying_the_pdf(): void
    {
        $response = $this->pdf()->name('aged.pdf')->toResponse(request());

        $this->assertInstanceOf(StreamedResponse::class, $response);
        $this->assertSame('application/pdf', $response->headers->get('Content-Type'));
        $this->assertStringContainsString('attachment', (string) $response->headers->get('Content-Disposition'));
        $this->assertStringContainsString('aged.pdf', (string) $response->headers->get('Content-Disposition'));

        ob_start();
        $response->sendContent();
        $body = (string) ob_get_clean();

        $this->assertStringStartsWith('%PDF', $body);
    }

    /**
     * And Livewire, given that response, captures a file rather than an empty string.
     *
     * Through Livewire's own capture rather than a stand-in for it: `captureOutput()` is the code that
     * decides what lands in the browser, and the 0-byte download was a disagreement between it and this
     * response. Asserting against the real thing is what makes this test about the fault.
     */
    public function test_livewire_captures_the_pdf_as_a_download(): void
    {
        $response = $this->pdf()->name('aged.pdf')->toResponse(request());
        $downloads = new SupportFileDownloads;

        $this->assertFalse($downloads->valueIsntAFileResponse($response), 'Livewire would ignore this response');

        $captured = $downloads->captureOutput(fn () => $response->sendContent());

        $this->assertStringStartsWith('%PDF', $captured);
        $this->assertSame('aged.pdf', $downloads->getFilenameFromContentDispositionHeader(
            $response->headers->get('Content-Disposition'),
        ));
    }

    /**
     * Inline is a plain response, and that is not the same mistake.
     *
     * A controller handing a document to a browser tab wants content and no disposition; Livewire never asks
     * for inline because it has nowhere to put it. So this one keeps its own shape, and the test says which
     * is which.
     */
    public function test_inline_is_a_plain_response_with_the_bytes_in_it(): void
    {
        $response = $this->pdf()->name('aged.pdf')->inline()->toResponse(request());

        $this->assertNotInstanceOf(StreamedResponse::class, $response);
        $this->assertStringStartsWith('%PDF', (string) $response->getContent());
        $this->assertStringContainsString('inline', (string) $response->headers->get('Content-Disposition'));
    }

    /** Written to disk, it is a file with a document in it — the MPR reports serve one later. */
    public function test_saving_writes_a_pdf(): void
    {
        $path = storage_path('app/'.uniqid('pdf-test-', true).'.pdf');

        try {
            $this->pdf()->save($path);

            $this->assertFileExists($path);
            $this->assertGreaterThan(0, filesize($path), 'a 0-byte file on disk is the same fault, found later');
            $this->assertStringStartsWith('%PDF', (string) file_get_contents($path));
        } finally {
            @unlink($path);
        }
    }
}
