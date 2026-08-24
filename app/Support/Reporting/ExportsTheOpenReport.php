<?php

namespace App\Support\Reporting;

use App\Support\Pdf\PdfDocument;
use Filament\Actions\Action;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The two export actions, for any page that can produce a pane payload — Phase 4.1.
 *
 * A trait rather than a base class because the two pages that need it already have different parents: the
 * Reports hub is a plain Filament page and each report's own screen extends `ModuleReportPage`. Both can
 * answer `statement()`, and that is the whole contract.
 *
 * **Both doors get the same two buttons**, which is the point of the phase. Somebody reading the aged
 * receivables in the hub pane and somebody reading it on its own page are looking at the same payload —
 * there is a test asserting exactly that for every report — so an export offered on one and not the other
 * would be an arbitrary difference between two views of one thing.
 *
 * The actions hide themselves rather than erroring when there is nothing to export: nothing selected yet, a
 * report the pane cannot draw, an empty period, or one of the three `file` reports whose screen describes a
 * download rather than containing one.
 */
trait ExportsTheOpenReport
{
    /**
     * The payload to export. Both consumers already have this method; declared so the trait can say so.
     *
     * @return array<string, mixed>|null
     */
    abstract public function statement(): ?array;

    /**
     * Export CSV and Export PDF, in that order.
     *
     * CSV first because it is the one the plan predicts people will want — "twenty new reports make 'I need
     * this in a spreadsheet' twenty times more likely" — and because a PDF of something already on screen is
     * the rarer errand of the two.
     *
     * @return array<int, Action>
     */
    protected function exportActions(): array
    {
        return [
            Action::make('exportReportCsv')
                ->label('Export CSV')
                ->icon('heroicon-o-table-cells')
                ->color('gray')
                ->visible(fn (): bool => $this->reportIsExportable())
                ->action(fn (): StreamedResponse => $this->downloadReportCsv()),

            Action::make('exportReportPdf')
                ->label('Export PDF')
                ->icon('heroicon-o-document-arrow-down')
                ->color('gray')
                ->visible(fn (): bool => $this->reportIsExportable())
                ->action(fn (): mixed => $this->downloadReportPdf()),
        ];
    }

    public function reportIsExportable(): bool
    {
        return app(ReportExport::class)->supports($this->statement());
    }

    public function downloadReportCsv(): StreamedResponse
    {
        $statement = $this->statement() ?? [];
        $export = app(ReportExport::class);
        $csv = $export->csv($statement);

        return response()->streamDownload(
            function () use ($csv): void {
                // A BOM, and not decoration. Excel on Windows reads a CSV without one as the local
                // codepage, which turns every em dash and every rupee sign in these reports into mojibake —
                // and this application's reports are full of both.
                echo "\u{FEFF}".$csv;
            },
            $export->filename($statement, $this->exportAsOf(), 'csv'),
            ['Content-Type' => 'text/csv; charset=UTF-8'],
        );
    }

    public function downloadReportPdf(): mixed
    {
        $statement = $this->statement() ?? [];
        $export = app(ReportExport::class);

        return (new PdfDocument('reports.pane-export', [
            'grid' => $export->grid($statement),
            // The layout's own flag for "no toolbar, no back link". An export is a document.
            'pdf' => true,
        ]))
            // Landscape for anything the pane itself calls wide. A nine-column table on A4 portrait is
            // unreadable, and `wide` is the report telling us it does not fit — the same flag the pane uses
            // to decide it must scroll sideways.
            ->{($statement['wide'] ?? false) ? 'landscape' : 'portrait'}()
            ->name($export->filename($statement, $this->exportAsOf(), 'pdf'))
            ->toResponse(request());
    }

    /**
     * The date the export is drawn to, for the filename.
     *
     * Both consumers have an `$asOf`, but neither guarantees it is set — the hub defaults it in `mount()`
     * and a page can be constructed without one — so this falls back rather than letting a null reach
     * `Carbon::parse()`, where it would silently become today and put the wrong date on the file.
     */
    protected function exportAsOf(): string
    {
        return filled($this->asOf ?? null) ? (string) $this->asOf : now()->toDateString();
    }
}
