<?php

namespace App\Support\Pdf;

use Dompdf\Dompdf;
use Dompdf\Options;
use Illuminate\Contracts\Support\Responsable;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\View;
use RuntimeException;
use Spatie\Browsershot\Browsershot;
use Spatie\LaravelPdf\Facades\Pdf as BrowsershotPdf;
use Spatie\LaravelPdf\PdfBuilder;

/**
 * A PDF built from a Blade view, rendered by whichever engine the environment
 * can actually run — headless Chrome via Browsershot where Node is installed,
 * Dompdf (pure PHP) where it is not.
 *
 * Exposes the slice of spatie/laravel-pdf's fluent API this app uses, so call
 * sites read the same regardless of driver.
 */
class PdfDocument implements Responsable
{
    protected string $format;

    protected string $orientation;

    protected ?string $name = null;

    protected bool $isInline = false;

    /** @var array{0: float, 1: float, 2: float, 3: float}|null top/right/bottom/left in mm */
    protected ?array $margins = null;

    /**
     * @param  array<string, mixed>  $data
     */
    public function __construct(
        protected string $view,
        protected array $data = [],
    ) {
        $this->format = config('pdf.paper.format', 'a4');
        $this->orientation = config('pdf.paper.orientation', 'portrait');
    }

    public function format(string $format): static
    {
        $this->format = $format;

        return $this;
    }

    public function portrait(): static
    {
        $this->orientation = 'portrait';

        return $this;
    }

    public function landscape(): static
    {
        $this->orientation = 'landscape';

        return $this;
    }

    public function margins(float $top, float $right, float $bottom, float $left): static
    {
        $this->margins = [$top, $right, $bottom, $left];

        return $this;
    }

    public function name(string $name): static
    {
        $this->name = str_ends_with($name, '.pdf') ? $name : $name.'.pdf';

        return $this;
    }

    public function getName(): string
    {
        return $this->name ?? 'document.pdf';
    }

    /**
     * Hand the PDF to the browser to display rather than to save.
     *
     * The only difference is Content-Disposition, but it is the difference
     * between a document you glance at in a tab and one that lands in Downloads
     * every time you look at it.
     */
    public function inline(bool $condition = true): static
    {
        $this->isInline = $condition;

        return $this;
    }

    /**
     * Which engine will actually render. "auto" prefers Browsershot and falls
     * back to Dompdf when Node is missing.
     */
    public function driver(): string
    {
        $driver = config('pdf.driver', 'auto');

        if ($driver !== 'auto') {
            return $driver;
        }

        return NodeRuntime::isAvailable() ? 'browsershot' : 'dompdf';
    }

    /**
     * The PDF's bytes.
     *
     * **Never empty.** An engine that produces nothing — Chrome that could not start, a Dompdf that
     * threw past its own error handling — used to hand back an empty string, and every caller then
     * did something reasonable with it: attached it to an email, wrote it to disk, or sent it to a
     * browser as a 0-byte download. A 0-byte PDF is the worst shape a failure can take, because it
     * looks like a document until somebody opens it, so this refuses instead and names the engine.
     */
    public function raw(): string
    {
        $contents = $this->driver() === 'browsershot'
            ? (string) base64_decode($this->browsershot()->base64(), true)
            : $this->dompdf();

        if ($contents === '') {
            throw new RuntimeException(sprintf(
                'The %s engine rendered no bytes for [%s]. A 0-byte PDF is never a document.',
                $this->driver(),
                $this->view,
            ));
        }

        return $contents;
    }

    /**
     * Write the PDF to disk.
     *
     * Through `raw()` for both engines rather than Browsershot's own `save()`, which costs a base64
     * round trip and buys the guard: the MPR reports write a file here and then serve it, so an
     * engine that rendered nothing would leave a 0-byte document on the disk for somebody to
     * download later — the same fault as the response path, discovered a day afterwards instead of
     * immediately.
     */
    public function save(string $path): static
    {
        File::ensureDirectoryExists(dirname($path));
        File::put($path, $this->raw());

        return $this;
    }

    /**
     * The PDF as an HTTP response — **one path for both engines**.
     *
     * It used to branch: Browsershot delegated to spatie's builder, which returns a plain
     * `Response`, while Dompdf returned a `StreamedResponse`. Two response *types* for one call, and
     * that difference broke a different button on each kind of host:
     *
     *  - **Livewire only turns a `StreamedResponse` or a `BinaryFileResponse` into a download**
     *    (`SupportFileDownloads::call()` returns early for anything else). So on a host *with* Node,
     *    every Filament action returning this — the report exports — handed Livewire a plain response
     *    it ignored, and the button did nothing at all.
     *  - **`StreamedResponse::getContent()` is `false` by contract**, because a streamed response has
     *    no content until it is sent. So on a host *without* Node — which is production, since `auto`
     *    falls back to Dompdf — a caller that echoed `toResponse()->getContent()` echoed `false`, and
     *    the browser saved a **0-byte PDF**. That was the payslip download.
     *
     * Neither is a bug in the caller: they were written against whichever engine the author's machine
     * had. So the engine no longer decides the shape of the answer. The bytes are rendered here, by
     * `raw()`, which refuses to be empty — and a caller that wants the bytes should ask `raw()` for
     * them rather than take them out of a response.
     */
    public function toResponse($request)
    {
        $contents = $this->raw();
        $name = $this->getName();

        // Inline is for a controller handing a document to a tab; Livewire has no way to show one,
        // so nothing that goes through a Filament action asks for it.
        if ($this->isInline) {
            return response($contents, 200, [
                'Content-Type' => 'application/pdf',
                'Content-Disposition' => 'inline; filename="'.$name.'"',
            ]);
        }

        return response()->streamDownload(
            fn () => print $contents,
            $name,
            ['Content-Type' => 'application/pdf'],
        );
    }

    /**
     * Rendered HTML for the view — shared by both engines.
     *
     * `$pdfEngine` is handed to the template so it can include CSS overrides
     * for Dompdf, which understands no flexbox or grid.
     */
    public function html(): string
    {
        return View::make($this->view, $this->data + ['pdfEngine' => $this->driver()])->render();
    }

    protected function browsershot(): PdfBuilder
    {
        $pdf = BrowsershotPdf::view($this->view, $this->data + ['pdfEngine' => 'browsershot'])
            ->format($this->format)
            ->withBrowsershot(fn (Browsershot $b) => $b
                ->setNodeBinary(config('services.node.binary'))
                ->setNpmBinary(config('services.node.npm'))
                // Wall-clock, so what spends it is a busy machine rather than a
                // complicated document. Configurable for exactly that reason.
                ->timeout(config('pdf.timeout', 60)));

        $pdf = $this->orientation === 'landscape' ? $pdf->landscape() : $pdf->portrait();

        if ($this->margins) {
            $pdf->margins(...$this->margins);
        }

        if ($this->name) {
            $pdf->name($this->getName());
        }

        return $pdf;
    }

    protected function dompdf(): string
    {
        $options = new Options;

        foreach (config('pdf.dompdf.options', []) as $key => $value) {
            $options->set($key, $value);
        }

        // Local assets resolve straight off disk; that keeps logos working
        // without an HTTP round trip back into the app.
        $options->set('chroot', [public_path(), storage_path('app/public')]);

        $dompdf = new Dompdf($options);
        $dompdf->setPaper($this->format, $this->orientation);
        $dompdf->loadHtml($this->html(), 'UTF-8');
        $dompdf->render();

        return (string) $dompdf->output();
    }
}
