<?php

namespace App\Modules\Invoicing\Support;

use App\Modules\Core\Models\FiscalYear;
use App\Modules\Invoicing\Models\Contact;
use App\Modules\Invoicing\Models\Invoice;
use App\Support\Contracts\CsvImporter;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * The invoices that were already open on the day a company arrived — `docs/erpnext-gap-plan.md` Phase 5.
 *
 * `OpeningBalanceCsvImporter` brings in the trial balance, which includes the Receivables *total*. What it
 * cannot bring in is the invoices behind that total, so ageing starts empty and Aged Receivables says a
 * company with 4m outstanding is owed nothing. Every part of the fix already existed — an importer registry,
 * a CSV reader, an invoice with a due date — so this is one class.
 *
 * **It writes documents and posts nothing, which is the whole design.** The opening trial balance already
 * debited 1250 with the receivables total; posting these invoices as well would double it. So each row
 * becomes an issued invoice with no journal entry, and the two sides then agree — which Phase 2's control
 * check is exactly the thing that says so. A company whose invoices do not add up to the total it imported
 * hears about it from `LedgerControlsCheck` rather than finding out at the year end.
 *
 * **Re-runnable, matched on the invoice number**, as the contract asks: somebody fixing three rows re-uploads
 * the whole file. A number that belongs to a *posted* invoice is refused rather than overwritten — an import
 * must not be able to rewrite a document that is in the ledger.
 *
 * **ERPNext's own order is worth following**: chart, then invoices, then payments, then stock, then assets,
 * then a journal for the remainder — running a trial balance after each stage, so an error belongs to one
 * batch. The help doc says so where somebody importing will read it.
 */
class OpeningInvoiceCsvImporter implements CsvImporter
{
    public function key(): string
    {
        return 'opening_invoices';
    }

    public function label(): string
    {
        return 'Opening invoices and bills';
    }

    public function columns(): array
    {
        return ['invoice_number', 'contact_name', 'kind', 'invoice_date', 'due_date', 'outstanding'];
    }

    public function example(): array
    {
        return ['INV-2026-000123', 'Acme Traders', 'sale', '2026-05-14', '2026-06-13', '250000.00'];
    }

    /**
     * None: every row carries its own date, and it has to.
     *
     * An opening invoice's date is what ages it — that is the entire point of importing them rather than
     * taking the total — so a single "as at" date would flatten a year of ageing into one bucket.
     */
    public function dateField(): ?array
    {
        return null;
    }

    public function problemWith(array $row): ?string
    {
        if (trim((string) $row['invoice_number']) === '') {
            return 'no invoice number';
        }

        if (trim((string) $row['contact_name']) === '') {
            return 'no customer or supplier name';
        }

        if (! $this->contactFor($row)) {
            return "no contact called {$row['contact_name']} — import your contacts first";
        }

        if ($this->kindOf($row) === null) {
            return "kind must be sale or purchase, not '{$row['kind']}'";
        }

        if (! $this->isDate($row['invoice_date'] ?? '')) {
            return 'no usable invoice date';
        }

        if (filled($row['due_date'] ?? '') && ! $this->isDate($row['due_date'])) {
            return 'a due date that is not a date';
        }

        if ($this->amount($row['outstanding'] ?? '') <= 0) {
            return 'nothing outstanding — an opening invoice with nothing left to pay is history, not a balance';
        }

        $existing = Invoice::where('invoice_number', trim((string) $row['invoice_number']))->first();

        if ($existing && $existing->journal_entry_id) {
            return "invoice {$existing->invoice_number} is already posted in this system and will not be "
                .'overwritten by an import';
        }

        return null;
    }

    public function write(Collection $rows, ?string $date = null): int
    {
        $written = 0;

        foreach ($rows as $row) {
            $contact = $this->contactFor($row);
            $outstanding = round($this->amount($row['outstanding']), 2);
            $invoiceDate = Carbon::parse($row['invoice_date'])->toDateString();

            $invoice = Invoice::updateOrCreate(
                ['invoice_number' => trim((string) $row['invoice_number'])],
                [
                    'kind' => $this->kindOf($row),
                    'contact_id' => $contact->getKey(),
                    'invoice_date' => $invoiceDate,
                    'due_date' => filled($row['due_date'] ?? '')
                        ? Carbon::parse($row['due_date'])->toDateString()
                        : $invoiceDate,
                    // Issued, because it is: the customer has it and owes it. Not `draft`, which would keep
                    // it out of every ageing report — the one thing this import exists to fill.
                    'status' => Invoice::STATUS_ISSUED,
                    /*
                     * The outstanding amount, as the whole invoice, with no tax split.
                     *
                     * A part-paid opening invoice is imported as what is *left*: the tax on the paid part was
                     * filed under the old system and re-stating it here would put it in this company's next
                     * return. So `total` is the balance and `amount_paid` is zero, which makes `outstanding()`
                     * — the figure every ageing report reads — exactly right, and makes the document itself
                     * honestly a balance brought forward rather than a copy of the original bill.
                     */
                    'subtotal' => $outstanding,
                    'tax_amount' => 0,
                    'total' => $outstanding,
                    'amount_paid' => 0,
                    'fiscal_year_id' => $this->fiscalYearFor($invoiceDate),
                    'memo' => 'Opening balance brought forward',
                    // Never reported: this invoice was raised elsewhere. Left explicit because the default is
                    // the same value, and somebody reading this row should not have to check.
                    'fbr_status' => Invoice::FBR_NOT_REQUIRED,
                ],
            );

            // Replaced rather than added to, so re-running the file corrects the figure instead of doubling
            // it. Safe because this importer refuses any invoice that has posted (see `problemWith()`).
            $invoice->lines()->delete();
            $invoice->lines()->create([
                'description' => 'Balance brought forward',
                'quantity' => 1,
                'unit_price' => $outstanding,
                'line_total' => $outstanding,
            ]);

            $written++;
        }

        return $written;
    }

    private function contactFor(array $row): ?Contact
    {
        $name = trim((string) ($row['contact_name'] ?? ''));

        return $name === '' ? null : Contact::where('name', $name)->first();
    }

    /** `sale` or `purchase`, and nothing else — the two notes are raised by their own actions, not imported. */
    private function kindOf(array $row): ?string
    {
        return match (mb_strtolower(trim((string) ($row['kind'] ?? '')))) {
            'sale', 'invoice', 'receivable' => Invoice::KIND_SALE,
            'purchase', 'bill', 'payable' => Invoice::KIND_PURCHASE,
            default => null,
        };
    }

    private function fiscalYearFor(string $date): ?int
    {
        return FiscalYear::query()
            ->whereDate('start_date', '<=', $date)
            ->whereDate('end_date', '>=', $date)
            ->value('id');
    }

    private function isDate(string $value): bool
    {
        if (trim($value) === '') {
            return false;
        }

        try {
            Carbon::parse($value);

            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    /** Spreadsheets export thousands separators, and refusing a row over a comma is a poor trade. */
    private function amount(string $value): float
    {
        return (float) str_replace(',', '', $value ?: '0');
    }
}
