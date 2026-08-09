<?php

namespace App\Modules\Invoicing\Services;

use App\Modules\Core\Models\FiscalYear;
use App\Modules\Invoicing\Models\Invoice;
use App\Modules\Invoicing\Models\RecurringInvoice;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Raising the month's recurring invoices.
 *
 * A draft per agreement, exactly as if somebody had typed it: same document, same
 * issuing, same posting, same ageing. Nothing here touches the ledger — an invoice
 * reaches it when it is issued, which stays a decision somebody makes after looking at
 * it.
 */
class RecurringInvoiceService
{
    /**
     * @return Collection<int, RecurringInvoice>
     */
    public function due(Carbon $period): Collection
    {
        return RecurringInvoice::active()
            ->with(['lines', 'contact'])
            ->orderBy('day_of_month')
            ->get()
            ->filter(fn (RecurringInvoice $agreement): bool => $agreement->coversPeriod($period))
            ->values();
    }

    public function alreadyRaised(RecurringInvoice $agreement, Carbon $period): bool
    {
        return $agreement->invoices()
            ->whereDate('period', $period->copy()->startOfMonth()->toDateString())
            ->exists();
    }

    /**
     * Draft invoices for every agreement running in this month.
     *
     * Idempotent: an agreement already invoiced for the month is skipped, with the
     * unique key on (agreement, period) behind that in case two runs overlap.
     *
     * @return Collection<int, Invoice>
     */
    public function generateFor(Carbon $period): Collection
    {
        $period = $period->copy()->startOfMonth();
        $raised = collect();

        foreach ($this->due($period) as $agreement) {
            if ($this->alreadyRaised($agreement, $period) || $agreement->lines->isEmpty()) {
                continue;
            }

            $raised->push($this->raise($agreement, $period));
        }

        return $raised;
    }

    protected function raise(RecurringInvoice $agreement, Carbon $period): Invoice
    {
        $invoiceDate = $agreement->invoiceDateFor($period);

        $invoice = Invoice::create([
            'kind' => $agreement->kind,
            'contact_id' => $agreement->contact_id,
            'invoice_date' => $invoiceDate->toDateString(),
            'due_date' => $invoiceDate->copy()->addDays($agreement->due_days)->toDateString(),
            'fiscal_year_id' => FiscalYear::containing($invoiceDate->toDateString())?->getKey(),
            'recurring_invoice_id' => $agreement->getKey(),
            'period' => $period->toDateString(),
            'tax_inclusive' => $agreement->tax_inclusive,
            'memo' => $agreement->memo ?: $agreement->description.' — '.$period->format('F Y'),
            'subtotal' => 0,
            'tax_amount' => 0,
            'total' => 0,
        ]);

        foreach ($agreement->lines as $line) {
            $invoice->lines()->create([
                'description' => $line->description,
                'quantity' => $line->quantity,
                'unit_price' => $line->unit_price,
                'line_total' => $line->lineTotal(),
                'account_id' => $line->account_id,
                'tax_rate_id' => $line->tax_rate_id,
            ]);
        }

        // Totals from the lines, and tax from their rates — the same code that runs
        // when the invoice is issued, so a draft raised here reads exactly as one
        // somebody typed.
        $lines = $invoice->lines()->get();
        $subtotal = round($lines->sum('line_total'), 2);

        $invoice->update([
            'subtotal' => $subtotal,
            'total' => $subtotal,
        ]);

        return app(InvoiceService::class)->applyTaxes($invoice->refresh());
    }
}
