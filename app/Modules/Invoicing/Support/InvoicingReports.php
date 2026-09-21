<?php

namespace App\Modules\Invoicing\Support;

use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\JournalEntryLine;
use App\Modules\Invoicing\Models\Invoice;
use App\Modules\Invoicing\Services\FbrReconciliation;
use App\Modules\Invoicing\Services\InvoiceService;
use App\Support\ModuleMap;
use App\Support\Reporting\ReportPeriod;
use App\Support\Reporting\ReportShapes;
use Carbon\Carbon;

/**
 * Invoicing's three reports, as the Reports explorer draws them.
 *
 * These were methods on `Accounting\Support\ReportPane`, which meant Accounting imported
 * `InvoiceService` and `FbrReconciliation` to render reports it does not own — see
 * docs/module-packaging-plan.md §8 Group A. PayrollReports is the same change on the other side.
 *
 * Registered with `App\Support\Reporting\ReportRenderers` by InvoicingServiceProvider, so a company
 * without Invoicing has no ageing reports rather than two that throw.
 */
class InvoicingReports
{
    use ReportShapes;

    /**
     * Receivables or payables, bucketed by how late they are.
     *
     * The buckets are the report; the invoice list underneath is what makes a bucket actionable, and it
     * is capped here because the pane is a reading surface rather than a work queue — the report's own
     * page has all of them.
     *
     * @return array<string, mixed>
     */
    public function ageing(string $key, string $asOf): array
    {
        $receivable = $key === 'AgedReceivables';

        $report = $receivable
            ? app(InvoiceService::class)->outstandingReceivables($asOf)
            : app(InvoiceService::class)->outstandingPayables($asOf);

        // Every outstanding invoice, oldest first. Capped at twenty-five once and that was the wrong
        // call: the reason to open an ageing report is to work through it, and a list that stops at
        // twenty-five silently omits the invoices at the end — which on this report are the worst ones.
        $invoices = collect($report['invoices'])
            ->sortByDesc('days_overdue')
            ->values();

        return [
            'kind' => 'table',
            'key' => $key,
            'title' => $receivable ? 'Aged Receivables' : 'Aged Payables',
            'subtitle' => $this->subtitle('as of '.Carbon::parse($report['as_of'])->format('j M Y')),
            'columns' => ['Invoice', 'Contact', 'Days overdue', 'Outstanding'],
            'grid' => '8rem minmax(0, 1fr) 7rem 8rem',
            'numeric' => [2, 3],
            'empty' => 'Nothing is outstanding at this date.',
            'rows' => $invoices->map(fn (array $invoice): array => [
                $invoice['invoice_number'],
                $invoice['contact'],
                (string) $invoice['days_overdue'],
                number_format($invoice['outstanding_base'], 0),
            ])->all(),
            // The buckets as tiles: what the report is actually for.
            'tiles' => collect($report['buckets'])
                ->map(fn (float $amount, string $bucket): array => [
                    'label' => mb_strtoupper($bucket === 'current' ? 'not yet due' : $bucket.' days'),
                    'value' => $amount,
                    'accent' => $bucket === '90+',
                ])
                ->values()
                ->all(),
            'footer' => [
                'Total outstanding — '.$invoices->count().' invoices',
                '',
                '',
                number_format((float) $report['total'], 0),
            ],
            'note' => mb_strtoupper($invoices->count().' invoices outstanding'),
            'balanced' => true,
        ];
    }

    /** What FBR has not accepted, and what it never received. */
    public function fbrReconciliation(string $asOf): array
    {
        $reconciliation = app(FbrReconciliation::class);

        if (! $reconciliation->enabled()) {
            return $this->table(
                'FbrInvoiceReporting', 'FBR Invoice Reporting', $this->subtitle('integration off'),
                ['Finding', 'Invoice', 'Detail'], 'minmax(0, 14rem) 10rem minmax(0, 1fr)', [], [], [],
                'THE FBR INTEGRATION IS NOT SWITCHED ON', null,
                'The FBR integration is not switched on for this company, so there is nothing to reconcile.',
            );
        }

        $findings = collect($reconciliation->findings());

        return $this->table(
            'FbrInvoiceReporting',
            'FBR Invoice Reporting',
            $this->subtitle('as of '.Carbon::parse($asOf)->format('j M Y')),
            ['Finding', 'Invoice', 'Detail'],
            'minmax(0, 14rem) 10rem minmax(0, 1fr)',
            [],
            $findings->map(function ($finding): array {
                // findings() is the reconciliation's own vocabulary and has changed shape before, so each
                // row is read defensively rather than destructured — a wrong key here would be a blank
                // column on a compliance report.
                if (! is_array($finding)) {
                    return ['—', '—', (string) $finding];
                }

                return [
                    (string) ($finding['kind'] ?? $finding['finding'] ?? '—'),
                    (string) ($finding['invoice_number'] ?? $finding['invoice'] ?? '—'),
                    (string) ($finding['detail'] ?? $finding['reason'] ?? ''),
                ];
            })->all(),
            [['label' => 'FINDINGS', 'value' => (float) $reconciliation->total(), 'accent' => $reconciliation->total() > 0]],
            $reconciliation->total() > 0
                ? mb_strtoupper($reconciliation->total().' invoices need attention')
                : 'EVERY INVOICE IS ACCOUNTED FOR',
            null,
            'Every issued invoice has been accepted, and FBR has nothing this company has not sent.',
        );
    }

    /**
     * Tax customers withheld on receipts, for the company's own return.
     *
     * Read from the ledger — the debits to 1260 Advance Income Tax — rather than from `InvoiceEvent`, because
     * the figure the return claims has to be the one the trial balance carries, and a list built from the
     * event log could disagree with it by exactly one receipt somebody recorded twice. The invoice and the
     * customer are resolved from the entry's source, and the certificate from the line's own description,
     * which is where `InvoiceService::recordPayment()` writes it.
     */
    public function taxWithheldByCustomers(string $asOf): array
    {
        ['from' => $from, 'to' => $to] = ReportPeriod::toDate($asOf);

        $account = Account::query()->where('code', '1260')->first();

        $lines = $account === null
            ? collect()
            : JournalEntryLine::query()
                ->where('account_id', $account->getKey())
                ->where('debit_amount', '>', 0)
                ->whereHas('journalEntry', fn ($query) => $query
                    ->where('is_posted', true)
                    ->whereBetween('entry_date', [$from, $to]))
                ->with('journalEntry')
                ->get()
                ->sortBy(fn (JournalEntryLine $line): string => $line->journalEntry->entry_date->toDateString().'-'.$line->getKey())
                ->values();

        // The source is stored as an alias, but compared against both spellings: the mutator normalises on
        // write and nothing here should depend on which one an older row carries.
        $invoiceTypes = [ModuleMap::alias(Invoice::class), Invoice::class];

        $invoices = Invoice::query()
            ->whereKey($lines
                ->filter(fn (JournalEntryLine $line): bool => in_array($line->journalEntry->source_type, $invoiceTypes, true))
                ->map(fn (JournalEntryLine $line): mixed => $line->journalEntry->source_id)
                ->unique()
                ->all())
            ->with('contact')
            ->get()
            ->keyBy('id');

        $rows = $lines->map(function (JournalEntryLine $line) use ($invoices, $invoiceTypes): array {
            $entry = $line->journalEntry;
            $invoice = in_array($entry->source_type, $invoiceTypes, true) ? $invoices->get($entry->source_id) : null;

            // "Tax withheld by customer — cert. ABC — INV-1": the reference is the middle part when there
            // is one, and its absence is stated rather than left blank — a blank cell reads as a gap in
            // the report, where this is a gap in the record.
            preg_match('/cert\. (.+?) — /u', (string) $line->description, $match);

            return [
                $entry->entry_date->format('d M Y'),
                (string) ($invoice?->contact?->name ?? '—'),
                (string) ($invoice?->invoice_number ?? '—'),
                $match[1] ?? 'no certificate',
                number_format((float) $line->debit_amount, 0),
            ];
        })->all();

        $total = round((float) $lines->sum('debit_amount'), 2);

        return $this->table(
            'TaxWithheldByCustomers',
            'Tax Withheld by Customers',
            $this->subtitle("receipts from {$from} to {$to}"),
            ['Received', 'Customer', 'Invoice', 'Certificate', 'Withheld'],
            '8rem minmax(0, 1fr) 10rem minmax(0, 12rem) 10rem',
            [4],
            $rows,
            [
                ['label' => 'WITHHELD', 'value' => $total, 'accent' => true],
                ['label' => 'CERTIFICATES', 'value' => $lines->count(), 'accent' => false],
            ],
            $rows === []
                ? 'NOTHING WITHHELD IN THIS PERIOD'
                : mb_strtoupper(count($rows).' receipts · held in 1260 advance income tax · claim against the company\'s own return'),
            $rows === [] ? null : ['Total — '.count($rows).' receipts', '', '', '', number_format($total, 0)],
            'No customer has withheld tax on a receipt in this period.',
        );
    }
}
