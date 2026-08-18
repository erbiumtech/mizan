<?php

namespace App\Modules\ConstructionContracts\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\ConstructionContracts\Models\PaymentCertificate;
use App\Modules\ConstructionContracts\Support\CertificateSchedule;
use App\Modules\ConstructionContracts\Support\ContractVocabulary;
use App\Support\Pdf\Pdf;
use App\Support\Pdf\PdfDocument;
use Illuminate\Http\Request;

/**
 * The printed certificate — `docs/construction-management-plan.md` §10.5.
 *
 * **The pagination is decided here, before either engine sees the document.** `CertificateSchedule::paginate()`
 * chunks the lines into pages with their own headers and brought-/carried-forward subtotals, so the sheet breaks
 * the same way whether headless Chrome or Dompdf renders it. A certificate that paginated differently depending on
 * whether the server happened to have Node installed could not be reissued identically, and reissuing identically
 * is the entire point of a certificate.
 *
 * Orientation follows the column set rather than the other way round: the AIA continuation sheet is eleven columns
 * and needs landscape, while FIDIC's clause 14.6 summary is a portrait page with the measurement schedule annexed.
 * Same component, same chunking, different column set (§8.3).
 */
class CertificatePdfController extends Controller
{
    /**
     * The certificate is looked up here rather than bound by the router: the company is made current by
     * middleware, and a bound model would have been resolved before that — against the landlord database, where
     * certificates do not live. Copied from `InvoicePdfController`, which learned it the hard way.
     */
    public function __invoke(Request $request, string $company, int $certificate): PdfDocument
    {
        $record = PaymentCertificate::query()
            ->with([
                'contract.job.certifier',
                'contract.contact',
                'progressClaim',
                'deductions',
                'lines',
            ])
            ->findOrFail($certificate);

        abort_unless($request->user()->can('view', $record), 403);

        $standard = $record->contract->contract_standard;

        $pdf = Pdf::view('pdfs.construction-certificate', [
            'certificate' => $record,
            'columns' => CertificateSchedule::columnsFor($standard),
            'pages' => CertificateSchedule::paginate(
                $record->lines->sortBy([['item_no', 'asc']])->values(),
            ),
        ])->name("{$record->certificate_number}.pdf")
            // Opens in the tab it was asked for from; ?download=1 to keep a copy — the same convention the
            // invoice PDF uses, because people expect one behaviour from every document in the application.
            ->inline(! $request->boolean('download'));

        return $standard === ContractVocabulary::AIA ? $pdf->landscape() : $pdf->portrait();
    }
}
