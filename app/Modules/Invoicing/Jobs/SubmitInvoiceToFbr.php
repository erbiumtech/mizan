<?php

namespace App\Modules\Invoicing\Jobs;

use App\Modules\Invoicing\Fbr\FbrDriver;
use App\Modules\Invoicing\Fbr\FbrResponse;
use App\Modules\Invoicing\Models\FbrSubmission;
use App\Modules\Invoicing\Models\Invoice;
use App\Modules\Invoicing\Models\InvoiceLine;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * Report one issued invoice to FBR, through whichever driver the company runs —
 * docs/fbr-digital-invoicing-plan.md phase 5.
 *
 * Queued, not synchronous: "real-time" is a reporting obligation, not a demand
 * that a clerk's save request block on a third-party HTTP call (§3). The id
 * rather than the model, for the reason DeliverScheduledReport gives: the
 * invoice is read as it is now, and the tenant travels with the job.
 *
 * Every driver answer — accepted, rejected, never-heard-back — is written to
 * `fbr_submissions` before anything is decided, because a push integration's
 * failures are silent unless something records them (§5). Only the
 * never-heard-back outcome retries: it may have landed anyway, which is why
 * the idempotency key is fixed at dispatch and reused verbatim on every retry.
 * A rejection is FBR saying no to this data; resending it unchanged is not a
 * fix, so it goes straight to `rejected` where the reconciliation report
 * surfaces the reason.
 */
class SubmitInvoiceToFbr implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public int $tries = 5;

    /**
     * Fixed at dispatch and serialized with the job, so every retry of this
     * logical submission reuses it — an integrator that recorded the invoice
     * before timing out dedupes on it. A fresh dispatch (a resubmission after
     * a rejection) gets a new one, which is correct: FBR recorded nothing.
     */
    public string $idempotencyKey;

    public function __construct(public int $invoiceId)
    {
        $this->idempotencyKey = (string) Str::uuid();
    }

    /**
     * Minutes-then-hours, because the plausible failures live at both ends: a
     * blip that clears in a minute, and an integrator outage that clears when
     * somebody at the integrator fixes it.
     */
    // ponytail: fixed ladder (1m/5m/30m/2h, 5 tries); make it config the day a real integrator's SLA says otherwise.
    public function backoff(): array
    {
        return [60, 300, 1800, 7200];
    }

    public function handle(FbrDriver $driver): void
    {
        $invoice = Invoice::query()->with(['contact', 'lines'])->find($this->invoiceId);

        // Deleted, or reporting switched off, between dispatch and here — the
        // setting is read now because it is the authority on whether the
        // company still reports, not on whether it did when the job was queued.
        if ($invoice === null || ! setting('fbr.enabled', false)) {
            return;
        }

        // A duplicate dispatch of something already reported must do nothing:
        // submitting it again is the exact double-report the key exists to stop.
        if ($invoice->isFbrLive()) {
            return;
        }

        // A draft was never issued and a void has been withdrawn; neither is FBR's.
        if (in_array($invoice->status, [Invoice::STATUS_DRAFT, Invoice::STATUS_VOID], true)) {
            return;
        }

        if ($invoice->isCreditNote()) {
            $this->refuseCreditNote($invoice, $driver);

            return;
        }

        // A purchase bill is the supplier's to report, a debit note adjusts one.
        if (! $invoice->isSale()) {
            return;
        }

        // Marked before the call, so a crash mid-request reads as `submitted`
        // and ages into the reconciliation report's "stuck" group instead of
        // looking untouched.
        $invoice->update(['fbr_status' => Invoice::FBR_SUBMITTED]);

        $payload = $this->payload($invoice);
        $response = $driver->submit($payload, $this->idempotencyKey);

        $this->record($invoice, $driver->name(), $payload, $response);

        match ($response->outcome) {
            FbrSubmission::OUTCOME_ACCEPTED => $invoice->update([
                'fbr_status' => Invoice::FBR_ACCEPTED,
                'fbr_irn' => $response->irn,
                'fbr_usin' => $response->usin,
                // The clock the 72-hour correction window runs from.
                'fbr_reported_at' => Carbon::now(),
                // Stored, never regenerated: a reprint must carry the QR the
                // invoice was reported with (plan §4).
                'fbr_qr_payload' => $response->qrPayload ?? $response->irn,
            ]),
            FbrSubmission::OUTCOME_REJECTED => $invoice->update([
                'fbr_status' => Invoice::FBR_REJECTED,
            ]),
            // Never heard back. The row above keeps the attempt; the throw is
            // what buys the retry, with the same idempotency key.
            default => throw new RuntimeException(
                "FBR submission for {$invoice->invoice_number} got no answer: {$response->errorMessage}"
            ),
        };
    }

    /**
     * The retries are spent, and nothing ever answered.
     *
     * Back to `not_required` rather than left at `submitted`, so the invoice
     * lands in FbrReconciliation::unreported() — the group whose explanation
     * says "carries the compliance exposure" — immediately, instead of dressing
     * up as in-flight for another day until stuck() ages it in. The submission
     * rows keep the caution that matters: an `error` may have landed anyway, so
     * whoever resubmits does it off that log, not blind.
     */
    public function failed(Throwable $exception): void
    {
        $invoice = Invoice::query()->find($this->invoiceId);

        if ($invoice !== null
            && in_array($invoice->fbr_status, [Invoice::FBR_PENDING, Invoice::FBR_SUBMITTED], true)) {
            $invoice->update(['fbr_status' => Invoice::FBR_NOT_REQUIRED]);
        }
    }

    /**
     * A credit note cannot be transmitted: reportedly it must be (SRO
     * 69(I)/2025, plan §9.7), but no driver defines how — and transmitting a
     * guess at a statutory document is worse than refusing. Refusing loudly:
     * the note stays `not_required`, which is exactly where
     * FbrReconciliation::unreported() lists it as a gap, and the submission
     * row below says why, so the trail exists instead of a silent hole.
     */
    private function refuseCreditNote(Invoice $invoice, FbrDriver $driver): void
    {
        $this->record($invoice, $driver->name(), $this->payload($invoice), FbrResponse::error(
            errorMessage: 'Credit notes cannot be transmitted: no integrator driver defines them yet '
                .'(docs/fbr-digital-invoicing-plan.md §9.7). Listed as unreported until phase 4 does.',
            errorCode: 'credit_note_unsupported',
        ));

        $invoice->update(['fbr_status' => Invoice::FBR_NOT_REQUIRED]);
    }

    /**
     * One row per logical submission — the unique `idempotency_key` enforces
     * it — so a retry updates the row and bumps `attempt` rather than
     * inserting a sibling with a key the integrator has already seen. The row
     * reads as "this submission, tried N times, latest answer X"; a
     * resubmission after a rejection is a new dispatch, a new key, a new row.
     */
    private function record(Invoice $invoice, string $driver, array $payload, FbrResponse $response): void
    {
        FbrSubmission::updateOrCreate(
            ['idempotency_key' => $this->idempotencyKey],
            [
                'invoice_id' => $invoice->getKey(),
                'attempt' => $this->attempts(),
                'driver' => $driver,
                'request_payload' => $payload,
                'response_payload' => $response->responsePayload,
                'http_status' => $response->httpStatus,
                'outcome' => $response->outcome,
                'error_code' => $response->errorCode,
                'error_message' => $response->errorMessage,
                'submitted_at' => Carbon::now(),
            ],
        );
    }

    /**
     * The canonical shape a driver maps to its integrator's wire format.
     *
     * Deliberately only what the invoice itself knows. The exact required
     * field list is §9.5's to confirm with a tax advisor; the buyer NTN is
     * already on `contacts` (plan §5) and nullable, so it travels as-is and a
     * real driver validates before it transmits.
     */
    private function payload(Invoice $invoice): array
    {
        return [
            'kind' => $invoice->kind,
            'invoice_number' => $invoice->invoice_number,
            'invoice_date' => $invoice->invoice_date->toDateString(),
            'currency' => $invoice->currencyCode(),
            'buyer' => [
                'name' => $invoice->contact->name,
                'ntn' => $invoice->contact->ntn,
            ],
            'lines' => $invoice->lines->map(fn (InvoiceLine $line): array => [
                'description' => $line->description,
                'quantity' => (float) $line->quantity,
                'unit_price' => (float) $line->unit_price,
                'tax_amount' => (float) $line->tax_amount,
                'line_total' => (float) $line->line_total,
            ])->all(),
            'subtotal' => (float) $invoice->subtotal,
            'tax_amount' => (float) $invoice->tax_amount,
            'total' => (float) $invoice->total,
        ];
    }
}
