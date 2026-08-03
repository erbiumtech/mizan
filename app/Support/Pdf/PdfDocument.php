<?php

namespace App\Support\Pdf;

use Dompdf\Dompdf;
use Dompdf\Options;
use Illuminate\Contracts\Support\Responsable;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\View;
use Spatie\Browsershot\Browsershot;
use Spatie\LaravelPdf\Facades\Pdf as BrowsershotPdf;

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

    public function raw(): string
    {
        if ($this->driver() === 'browsershot') {
            return (string) base64_decode($this->browsershot()->base64(), true);
        }

        return $this->dompdf();
    }

    public function save(string $path): static
    {
        if ($this->driver() === 'browsershot') {
            $this->browsershot()->save($path);

            return $this;
        }

        File::ensureDirectoryExists(dirname($path));
        File::put($path, $this->dompdf());

        return $this;
    }

    public function toResponse($request)
    {
        if ($this->driver() === 'browsershot') {
            $pdf = $this->browsershot();

            // download(), not name(): spatie's builder only sets a disposition
            // when asked, and its toResponse() falls back to inline — so a PDF
            // named but not asked for opened in the tab under Browsershot while
            // the Dompdf branch below downloaded it. Same call, two behaviours,
            // depending on whether the host had Node.
            return ($this->isInline ? $pdf->inline($this->getName()) : $pdf->download($this->getName()))
                ->toResponse($request);
        }

        $contents = $this->dompdf();
        $name = $this->getName();

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

    protected function browsershot(): \Spatie\LaravelPdf\PdfBuilder
    {
        $pdf = BrowsershotPdf::view($this->view, $this->data + ['pdfEngine' => 'browsershot'])
            ->format($this->format)
            ->withBrowsershot(fn (Browsershot $b) => $b
                ->setNodeBinary(config('services.node.binary'))
                ->setNpmBinary(config('services.node.npm')));

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
