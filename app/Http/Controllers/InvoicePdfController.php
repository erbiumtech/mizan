<?php

namespace App\Http\Controllers;

use App\Models\Invoice;
use App\Support\Pdf\Pdf;

class InvoicePdfController extends Controller
{
    public function __invoke(Invoice $invoice)
    {
        abort_unless(request()->user()->can('view', $invoice), 403);

        $invoice->load(['contact', 'lines.product']);

        return Pdf::view('pdfs.invoice', ['invoice' => $invoice])
            ->name("{$invoice->invoice_number}.pdf");
    }
}
