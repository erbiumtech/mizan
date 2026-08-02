<?php

namespace App\Modules\Invoicing\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Invoicing\Models\Invoice;
use App\Support\Pdf\Pdf;
use Illuminate\Http\Request;

class InvoicePdfController extends Controller
{
    /**
     * The invoice is looked up here rather than bound by the router: the company
     * is made current by middleware, and a bound model would have been resolved
     * before that — against the landlord database, where invoices do not live.
     */
    public function __invoke(Request $request, string $company, int $invoice)
    {
        $record = Invoice::with(['contact', 'lines.product'])->findOrFail($invoice);

        abort_unless($request->user()->can('view', $record), 403);

        return Pdf::view('pdfs.invoice', ['invoice' => $record])
            ->name("{$record->invoice_number}.pdf")
            // Opens in the tab it was asked for from; ?download=1 to keep a copy.
            ->inline(! $request->boolean('download'));
    }
}
