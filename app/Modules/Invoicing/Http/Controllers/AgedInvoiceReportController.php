<?php

namespace App\Modules\Invoicing\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Invoicing\Models\Invoice;
use App\Modules\Invoicing\Services\InvoiceService;
use App\Support\Pdf\Pdf;
use Illuminate\Http\Request;

/**
 * The printable aged receivables and payables.
 *
 * Outside the panel, so the permission is checked here: a direct URL never
 * consults canAccess(). The licence gate and the company are on the route.
 */
class AgedInvoiceReportController extends Controller
{
    public function __construct(private InvoiceService $invoices) {}

    public function receivables(Request $request)
    {
        return $this->render($request, Invoice::KIND_SALE);
    }

    public function payables(Request $request)
    {
        return $this->render($request, Invoice::KIND_PURCHASE);
    }

    private function render(Request $request, string $kind)
    {
        abort_unless($request->user()->can('ReportView'), 403);

        $validated = $request->validate([
            'as_of' => 'nullable|date',
            'format' => 'nullable|in:pdf',
        ]);

        $isReceivable = $kind === Invoice::KIND_SALE;

        $report = $isReceivable
            ? $this->invoices->outstandingReceivables($validated['as_of'] ?? null)
            : $this->invoices->outstandingPayables($validated['as_of'] ?? null);

        // Oldest first: the row that needs chasing belongs at the top.
        $rows = $report['invoices'];
        usort($rows, fn (array $a, array $b): int => $b['days_overdue'] <=> $a['days_overdue']);

        $data = [
            'report' => $report,
            'rows' => $rows,
            'isReceivable' => $isReceivable,
            'heading' => $isReceivable ? 'Aged Receivables' : 'Aged Payables',
        ];

        if (($validated['format'] ?? null) === 'pdf') {
            return Pdf::view('reports.aged-invoices', $data + ['pdf' => true])
                ->format('a4')
                ->name(($isReceivable ? 'aged-receivables-' : 'aged-payables-').$report['as_of'].'.pdf');
        }

        return view('reports.aged-invoices', $data + ['pdf' => false]);
    }
}
