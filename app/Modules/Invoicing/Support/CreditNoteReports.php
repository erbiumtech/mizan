<?php

namespace App\Modules\Invoicing\Support;

use App\Modules\Invoicing\Models\Invoice;
use App\Support\Reporting\ReportPeriod;
use App\Support\Reporting\ReportShapes;
use App\Support\TenantDb;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Credit notes issued — `docs/reports-expansion-plan.md` Phase 3.5.
 *
 * "A tax-sensitive list with commissioner approval status; only visible per invoice today."
 *
 * **The tax sensitivity is the whole report.** A credit note may be issued against an invoice for a limited
 * number of days — `fbr.credit_note_days`, 180 by default — and beyond that it needs the Commissioner's
 * approval under rule 22, recorded as `commissioner_approval_ref`. A credit note issued outside the window
 * with no reference against it is an exposure: the tax has been reversed on a document that, on the face of
 * the record, was not permitted to reverse it.
 *
 * Nothing in this application refuses such a credit note — the window is *reported*, as the SLA clocks are —
 * so this list is the only place the exposure is visible at all. It is stated as an amount rather than a
 * count, because what matters is how much tax was reversed and not how many documents did it.
 *
 * **"Outside the window" is computed from the invoice being credited**, so a credit note that names no
 * invoice cannot be judged either way and says so. That is a real state: a credit note may be raised
 * standalone, and calling it compliant would be a guess in the company's favour.
 */
class CreditNoteReports
{
    use ReportShapes;

    public function creditNotes(string $asOf): array
    {
        $period = ReportPeriod::toDate($asOf);

        $notes = Invoice::query()
            ->where('kind', Invoice::KIND_CREDIT_NOTE)
            // Drafts excluded: a credit note nobody has issued has reversed no tax. Void ones likewise.
            ->whereIn('status', [Invoice::STATUS_ISSUED, Invoice::STATUS_PARTIALLY_PAID, Invoice::STATUS_PAID])
            ->whereDate('invoice_date', '>=', $period['from'])
            ->whereDate('invoice_date', '<=', $period['to'])
            ->orderByDesc('invoice_date')
            ->get();

        if ($notes->isEmpty()) {
            return $this->emptyCreditNotes($period);
        }

        $credited = $this->creditedInvoices($notes);
        $customers = $this->customerNames($notes);

        $rows = [];
        $total = 0.0;
        $exposed = 0.0;
        $unjudgeable = 0;

        foreach ($notes as $note) {
            $against = $note->credits_invoice_id === null ? null : ($credited[$note->credits_invoice_id] ?? null);
            $standing = $this->standing($note, $against);

            $rows[] = [
                (string) ($note->invoice_number ?: 'CN #'.$note->getKey()),
                (string) ($customers[$note->contact_id] ?? 'No customer'),
                (string) ($against['invoice_number'] ?? ($note->credits_invoice_id === null ? 'None named' : 'Invoice #'.$note->credits_invoice_id)),
                (string) Carbon::parse($note->invoice_date)->toDateString(),
                number_format((float) $note->total, 0),
                $standing['days'] === null ? '—' : number_format($standing['days']),
                $standing['label'],
            ];

            $total += (float) $note->total;

            if ($standing['exposed']) {
                $exposed += (float) $note->total;
            }

            if ($standing['days'] === null) {
                $unjudgeable++;
            }
        }

        return $this->table(
            'CreditNotesIssued',
            'Credit Notes Issued',
            $this->subtitle('issued between '.$period['from'].' and '.$period['to']),
            ['Credit note', 'Customer', 'Against', 'Issued', 'Amount', 'Days after', 'Standing'],
            'minmax(8rem, 12rem) minmax(0, 1fr) 11rem 8rem 10rem 8rem 14rem',
            [4, 5],
            $rows,
            [
                ['label' => 'CREDITED', 'value' => round($total, 2), 'accent' => true],
                // The exposure as money, because what matters is how much tax was reversed without cover
                // and not how many documents did it.
                ['label' => 'WITHOUT APPROVAL', 'value' => round($exposed, 2), 'accent' => false],
            ],
            $this->creditNoteNote($notes, round($exposed, 2), $unjudgeable),
            $rows === [] ? null : [
                'Total — '.count($rows).' credit notes',
                '',
                '',
                '',
                number_format($total, 0),
                '',
                '',
            ],
            'No credit note was issued in this period.',
        );
    }

    /**
     * Where this credit note stands against the window it had to be issued in.
     *
     * Four answers, and each is a different thing to do about it: inside the window is nothing; outside with
     * an approval is a reference somebody should be able to produce; outside without one is an exposure; and
     * *unjudgeable* is a credit note naming no invoice, where the window cannot be computed at all.
     *
     * The last is deliberately not treated as compliant. A credit note may legitimately be raised standalone,
     * but calling it within the window would be a guess in the company's favour on a tax question, and the
     * report has no business making that guess.
     *
     * @param  array<string, mixed>|null  $against
     * @return array{days: ?int, label: string, exposed: bool}
     */
    private function standing(Invoice $note, ?array $against): array
    {
        if ($against === null) {
            return [
                'days' => null,
                'label' => $note->hasCommissionerApproval() ? 'Approved · no invoice' : 'No invoice named',
                'exposed' => false,
            ];
        }

        $invoiceDate = Carbon::parse($against['invoice_date']);
        $days = (int) $invoiceDate->startOfDay()->diffInDays(Carbon::parse($note->invoice_date)->startOfDay());

        // Read from the setting, not written here: a company on a different regime has a different window and
        // this report must judge it by that one.
        $window = (int) setting('fbr.credit_note_days', 180);

        if ($days <= $window) {
            return ['days' => $days, 'label' => 'Within '.$window.' days', 'exposed' => false];
        }

        if ($note->hasCommissionerApproval()) {
            return [
                'days' => $days,
                'label' => 'Approved · '.$note->commissioner_approval_ref,
                'exposed' => false,
            ];
        }

        return ['days' => $days, 'label' => 'No approval recorded', 'exposed' => true];
    }

    /**
     * What the list amounts to, exposure first.
     *
     * Exposure leads because it is the only line on this report that asks somebody to do something, and the
     * unjudgeable count follows it because those are the rows a reader cannot clear without going and looking.
     *
     * @param  Collection<int, Invoice>  $notes
     */
    private function creditNoteNote(Collection $notes, float $exposed, int $unjudgeable): string
    {
        $window = (int) setting('fbr.credit_note_days', 180);

        return mb_strtoupper(implode(' · ', array_filter([
            $notes->count().' credit notes',
            $exposed > 0
                ? number_format($exposed, 0).' reversed outside the '.$window.'-day window with no approval recorded'
                : 'every credit note is inside its window or approved',
            $unjudgeable > 0
                ? $unjudgeable.($unjudgeable === 1 ? ' names' : ' name').' no invoice, so the window cannot be judged'
                : null,
        ])));
    }

    /**
     * The invoices being credited: number and date, which is all the window needs.
     *
     * Read through the query builder rather than loaded as models — two columns of the same table the notes
     * came from, and a credited invoice may be outside the report's own period, which an Eloquent relation
     * would happily fetch and a reader would then wonder why.
     *
     * @param  Collection<int, Invoice>  $notes
     * @return array<int, array<string, mixed>>
     */
    private function creditedInvoices(Collection $notes): array
    {
        $ids = $notes->pluck('credits_invoice_id')->filter()->unique()->values()->all();

        if ($ids === []) {
            return [];
        }

        return TenantDb::table('invoices')
            ->whereIn('id', $ids)
            ->get(['id', 'invoice_number', 'invoice_date'])
            ->keyBy('id')
            ->map(fn ($row): array => ['invoice_number' => $row->invoice_number, 'invoice_date' => $row->invoice_date])
            ->all();
    }

    /**
     * @param  Collection<int, Invoice>  $notes
     * @return array<int, string>
     */
    private function customerNames(Collection $notes): array
    {
        $ids = $notes->pluck('contact_id')->filter()->unique()->values()->all();

        return $ids === [] ? [] : TenantDb::table('contacts')->whereIn('id', $ids)->pluck('name', 'id')->all();
    }

    /**
     * @param  array{from: string, to: string}  $period
     * @return array<string, mixed>
     */
    private function emptyCreditNotes(array $period): array
    {
        return $this->table(
            'CreditNotesIssued',
            'Credit Notes Issued',
            $this->subtitle('issued between '.$period['from'].' and '.$period['to']),
            ['Credit note', 'Customer', 'Against', 'Issued', 'Amount', 'Days after', 'Standing'],
            'minmax(8rem, 12rem) minmax(0, 1fr) 11rem 8rem 10rem 8rem 14rem',
            [4, 5],
            [],
            [
                ['label' => 'CREDITED', 'value' => 0.0, 'accent' => true],
                ['label' => 'WITHOUT APPROVAL', 'value' => 0.0, 'accent' => false],
            ],
            'NO CREDIT NOTE WAS ISSUED IN THIS PERIOD',
            null,
            'No credit note was issued in this period.',
        );
    }
}
