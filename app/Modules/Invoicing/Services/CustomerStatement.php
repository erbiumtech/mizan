<?php

namespace App\Modules\Invoicing\Services;

use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\JournalEntryLine;
use App\Modules\Invoicing\Models\Contact;
use App\Modules\Invoicing\Models\Invoice;
use App\Support\CompanyLetterhead;
use App\Support\ModuleMap;
use App\Support\Pdf\Pdf;
use App\Support\Pdf\PdfDocument;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * One customer's account with this company, for a period — `docs/erpnext-gap-plan.md` §4 item 1, the top of
 * the list: "the only item on this list a customer sees".
 *
 * ERPNext's *Process Statement of Accounts*: opening balance, every document and receipt in the period,
 * closing balance, and how late what is still owed is. The plan noted that a `ReportSchedule` could not do it
 * because a schedule sends one rendered report to a list of people and this needs one *per recipient*,
 * rendered as theirs. Phase 5's dunning correction settled what that means here: a per-customer email is a
 * notification and a command, not a schedule. So this is a service that answers for one customer, a PDF, a
 * notification that attaches it, and a monthly command that sends one to everybody with activity.
 *
 * **Receipts come from the ledger, not from the event log.** `InvoiceEvent` records that a payment was
 * recorded, dated when somebody typed it; the receipt's journal entry is dated when the money arrived, which
 * is the date a customer reconciles against. The entry carries the invoice as its source, so the receipts for
 * a customer are the credits to Receivables on entries sourced from their invoices — less each invoice's own
 * issue entry, which debits it.
 *
 * **Base currency throughout.** A customer billed in two currencies gets one statement in the company's
 * books' currency, with the invoice's own figure alongside where it differs. Adding dollars to rupees is the
 * mistake this avoids; a statement per currency is a feature nobody has asked for.
 */
class CustomerStatement
{
    /**
     * @return array{
     *     contact: Contact, from: string, to: string, opening: float, closing: float,
     *     lines: array<int, array{date: string, type: string, reference: string, detail: string, debit: float, credit: float, balance: float}>,
     *     ageing: array<string, float>, total_due: float
     * }
     */
    public function for(Contact $contact, string $from, string $to): array
    {
        $from = Carbon::parse($from)->toDateString();
        $to = Carbon::parse($to)->toDateString();

        $documents = $this->documents($contact);
        $receipts = $this->receipts($documents);

        $movements = collect()
            ->concat($documents->map(fn (Invoice $invoice): array => [
                'date' => $invoice->invoice_date->toDateString(),
                'sort' => $invoice->invoice_date->toDateString().'-0-'.$invoice->getKey(),
                'type' => $invoice->isCreditNote() ? 'Credit note' : 'Invoice',
                'reference' => (string) $invoice->invoice_number,
                'detail' => $invoice->isForeignCurrency()
                    ? $invoice->currencyCode().' '.number_format(abs((float) $invoice->total), 2).' at '.$invoice->rate()
                    : ($invoice->due_date ? 'Due '.$invoice->due_date->format('d M Y') : ''),
                'debit' => $invoice->isCreditNote() ? 0.0 : abs($invoice->baseTotal()),
                'credit' => $invoice->isCreditNote() ? abs($invoice->baseTotal()) : 0.0,
            ]))
            ->concat($receipts->map(fn (JournalEntryLine $line): array => [
                'date' => $line->journalEntry->entry_date->toDateString(),
                'sort' => $line->journalEntry->entry_date->toDateString().'-1-'.$line->getKey(),
                'type' => 'Receipt',
                'reference' => (string) $line->journalEntry->entry_number,
                'detail' => (string) str($line->journalEntry->memo)->after('Payment against ')->value(),
                'debit' => 0.0,
                'credit' => round((float) $line->credit_amount, 2),
            ]))
            ->sortBy('sort')
            ->values();

        $opening = round((float) $movements
            ->filter(fn (array $row): bool => $row['date'] < $from)
            ->sum(fn (array $row): float => $row['debit'] - $row['credit']), 2);

        $balance = $opening;
        $lines = [];

        foreach ($movements->filter(fn (array $row): bool => $row['date'] >= $from && $row['date'] <= $to) as $row) {
            $balance = round($balance + $row['debit'] - $row['credit'], 2);
            unset($row['sort']);
            $lines[] = $row + ['balance' => $balance];
        }

        $ageing = $this->ageing($documents, $to);

        return [
            'contact' => $contact,
            'from' => $from,
            'to' => $to,
            'opening' => $opening,
            'closing' => $balance,
            'lines' => $lines,
            'ageing' => $ageing,
            'total_due' => round(array_sum($ageing), 2),
        ];
    }

    /**
     * Customers worth sending a statement to for the period: anybody with a movement in it, or a balance.
     *
     * A customer with neither is not sent a statement saying so — a monthly "you owe nothing and nothing
     * happened" is the kind of email that gets a company's address filtered.
     *
     * @return Collection<int, array<string, mixed>> statements, keyed by contact id
     */
    public function due(string $from, string $to): Collection
    {
        // ponytail: one statement computed per customer with any invoice ever. Fine for the tens of customers a
        // services company has; a company with thousands wants the period filter pushed into the query.
        return Contact::query()
            ->whereIn('kind', ['customer', 'both'])
            ->where('is_active', true)
            ->whereHas('invoices', fn ($invoices) => $invoices->whereIn('kind', [Invoice::KIND_SALE, Invoice::KIND_CREDIT_NOTE]))
            ->orderBy('name')
            ->get()
            ->mapWithKeys(fn (Contact $contact): array => [$contact->getKey() => $this->for($contact, $from, $to)])
            ->filter(fn (array $statement): bool => $statement['lines'] !== [] || abs($statement['closing']) >= 0.005);
    }

    public function renderPdf(Contact $contact, string $from, string $to): PdfDocument
    {
        return Pdf::view('pdfs.customer-statement', $this->for($contact, $from, $to) + [
            'company' => CompanyLetterhead::data(),
            'issued_on' => Carbon::today(),
        ])
            ->format('a4')
            ->name($this->filename($contact, $to));
    }

    public function filename(Contact $contact, string $to): string
    {
        $name = str($contact->name)->slug()->value() ?: 'customer';

        return "statement-{$name}-".Carbon::parse($to)->format('Y-m-d').'.pdf';
    }

    /** @return Collection<int, Invoice> every sale and credit note that reached the books, oldest first */
    private function documents(Contact $contact): Collection
    {
        return Invoice::query()
            ->where('contact_id', $contact->getKey())
            ->whereIn('kind', [Invoice::KIND_SALE, Invoice::KIND_CREDIT_NOTE])
            ->whereIn('status', [Invoice::STATUS_ISSUED, Invoice::STATUS_PARTIALLY_PAID, Invoice::STATUS_PAID])
            ->orderBy('invoice_date')
            ->orderBy('id')
            ->get();
    }

    /**
     * Money received against these invoices, from the ledger.
     *
     * @param  Collection<int, Invoice>  $documents
     * @return Collection<int, JournalEntryLine>
     */
    private function receipts(Collection $documents): Collection
    {
        $sales = $documents->filter(fn (Invoice $invoice): bool => $invoice->kind === Invoice::KIND_SALE);

        if ($sales->isEmpty()) {
            return collect();
        }

        $receivable = Account::query()->where('code', '1250')->value('id');

        if ($receivable === null) {
            return collect();
        }

        // Each invoice's own issue entry debits the receivable; anything else sourced from it that credits the
        // receivable is a receipt — cash or, since the customer-withholding change, a tax certificate.
        $issueEntries = $sales->pluck('journal_entry_id')->filter()->all();

        return JournalEntryLine::query()
            ->where('account_id', $receivable)
            ->where('credit_amount', '>', 0)
            ->whereNotIn('journal_entry_id', $issueEntries ?: [0])
            ->whereHas('journalEntry', fn ($query) => $query
                ->where('is_posted', true)
                ->whereIn('source_type', [ModuleMap::alias(Invoice::class), Invoice::class])
                ->whereIn('source_id', $sales->modelKeys()))
            ->with('journalEntry')
            ->get();
    }

    /**
     * What is still owed as at the statement date, by how late it is — the buckets `InvoiceService::aging()`
     * uses, for one customer.
     *
     * @param  Collection<int, Invoice>  $documents
     * @return array<string, float>
     */
    private function ageing(Collection $documents, string $asOf): array
    {
        $on = Carbon::parse($asOf);
        $buckets = ['current' => 0.0, '31-60' => 0.0, '61-90' => 0.0, '90+' => 0.0];

        foreach ($documents as $invoice) {
            if (! $invoice->isOpen() || $invoice->invoice_date->toDateString() > $asOf) {
                continue;
            }

            $days = (int) Carbon::parse($invoice->due_date ?? $invoice->invoice_date)->diffInDays($on, false);
            $bucket = match (true) {
                $days <= 30 => 'current',
                $days <= 60 => '31-60',
                $days <= 90 => '61-90',
                default => '90+',
            };

            $buckets[$bucket] = round($buckets[$bucket] + $invoice->signedBaseOutstanding(), 2);
        }

        return $buckets;
    }
}
