<?php

namespace App\Modules\Quotations\Services;

use App\Modules\Invoicing\Models\Invoice;
use App\Modules\Quotations\Models\Quotation;
use App\Support\TenantTransaction;
use InvalidArgumentException;
use RuntimeException;

/**
 * Sending, revising, accepting and converting quotes.
 *
 * Three rules govern this class, and the third is the one that needed a decision.
 *
 * **1. Nothing here posts.** Not a journal entry, not a pending one. A quote is an offer.
 *
 * **2. A sent quote is revised by supersession, never by edit.** The customer has version 1;
 * editing it makes this system disagree with their inbox, and nothing can then say which
 * version was agreed.
 *
 * **3. Conversion produces a DRAFT invoice, and stops.**
 *
 * That third rule is where docs/crms-plan.md §13 said phase 5 was blocked, and it is worth
 * recording what the block actually was rather than treating it as resolved.
 * `docs/fbr-digital-invoicing-plan.md` establishes that a reported invoice may only be
 * cancelled or edited **within 72 hours**. So an invoice raised automatically by accepting a
 * quote is something that, once transmitted, nobody can take back — and this codebase's
 * `void()` no longer means what it did.
 *
 * The resolution is narrow and deliberate: **this creates the draft and nothing else.**
 * Issuing it, and therefore transmitting it, stays the explicit act it already is in
 * Invoicing. The credit-note gap is real and remains Invoicing's — it is not papered over
 * here, and nothing in this class brings it closer.
 */
class QuotationService
{
    /** Send a quote. Its figures are fixed from here on. */
    public function send(Quotation $quotation): Quotation
    {
        if (! $quotation->isDraft()) {
            throw new InvalidArgumentException("This quote is already {$quotation->status} and cannot be sent again.");
        }

        if ($quotation->lines()->doesntExist()) {
            throw new InvalidArgumentException('A quote with no lines is not an offer.');
        }

        $quotation->recalculate();
        $quotation->update(['status' => Quotation::STATUS_SENT]);

        return $quotation;
    }

    /**
     * Revise a sent quote: version 2, pointing at version 1.
     *
     * Version 1 becomes `superseded` and stays exactly as it was sent — that is the whole
     * point. Its lines are copied rather than moved, so both versions remain reproducible and
     * "what did we actually offer them in March" has an answer.
     */
    public function revise(Quotation $quotation): Quotation
    {
        if ($quotation->isAccepted()) {
            throw new InvalidArgumentException(
                'This quote has been accepted. Revising it would change what was agreed — raise a new quote instead.'
            );
        }

        if ($quotation->invoice_id) {
            throw new InvalidArgumentException('This quote has already become an invoice and cannot be revised.');
        }

        return TenantTransaction::run(function () use ($quotation): Quotation {
            $revision = $quotation->replicate([
                'number', 'status', 'version', 'supersedes_id', 'invoice_id',
                'accepted_at', 'declined_at', 'decline_reason',
            ]);

            $revision->fill([
                'number' => Quotation::nextNumber(),
                'status' => Quotation::STATUS_DRAFT,
                'version' => $quotation->version + 1,
                'supersedes_id' => $quotation->getKey(),
                'issue_date' => now()->toDateString(),
            ])->save();

            foreach ($quotation->lines as $line) {
                $copy = $line->replicate(['quotation_id']);
                $copy->quotation_id = $revision->getKey();
                $copy->save();
            }

            $revision->recalculate();

            // Only now, so a failure above leaves the original sent quote untouched.
            $quotation->update(['status' => Quotation::STATUS_SUPERSEDED]);

            return $revision->refresh();
        });
    }

    /**
     * Accept a quote.
     *
     * **An expired quote cannot be accepted** (§12.10). Checked against the date rather than
     * only the stored status, because the status changes when the sweep runs and a quote that
     * lapsed this morning must not be acceptable this afternoon.
     */
    public function accept(Quotation $quotation): Quotation
    {
        if (! $quotation->isSent()) {
            throw new InvalidArgumentException(
                "Only a sent quote can be accepted; this one is {$quotation->status}."
            );
        }

        if ($quotation->hasExpired()) {
            throw new InvalidArgumentException(
                'This quote expired on '.$quotation->valid_until->format('d M Y')
                .'. Revise it and send the new version — the prices may no longer hold.'
            );
        }

        $quotation->update([
            'status' => Quotation::STATUS_ACCEPTED,
            'accepted_at' => now(),
        ]);

        return $quotation;
    }

    public function decline(Quotation $quotation, string $reason): Quotation
    {
        if (! $quotation->isSent()) {
            throw new InvalidArgumentException("Only a sent quote can be declined; this one is {$quotation->status}.");
        }

        if (trim($reason) === '') {
            throw new InvalidArgumentException('A declined quote needs a reason — it is what win/loss reads.');
        }

        $quotation->update([
            'status' => Quotation::STATUS_DECLINED,
            'declined_at' => now(),
            'decline_reason' => trim($reason),
        ]);

        return $quotation;
    }

    /**
     * Expire quotes whose validity has passed.
     *
     * **One transition, one notification** — not a daily nag. The health-check alerts taught
     * that lesson: a job that mails the same warning every morning trains somebody to filter
     * it.
     *
     * @return int quotes expired
     */
    public function expireLapsed(?string $on = null): int
    {
        return Quotation::query()->expirable($on)->update(['status' => Quotation::STATUS_EXPIRED]);
    }

    /**
     * Whether a quote can become an invoice at all.
     *
     * Guarded on `invoicing`. `quotations` declares it as a requirement — §2: a quote whose
     * whole point is becoming an invoice, and which can never convert, is a PDF generator —
     * so this should always be true in practice. It is checked anyway, because a licence can
     * be revoked out from under live data.
     */
    public function canConvert(): bool
    {
        return modules()->enabled('invoicing');
    }

    /**
     * Turn an accepted quote into a **draft** invoice.
     *
     * Copies lines, tax rates and currency, so the invoice charges what the quote offered
     * rather than a recalculation of it. **The invoice — not the quote — is what posts**, and
     * only when somebody issues it.
     *
     * Deliberately stops at draft. See the class docblock: since FBR digital invoicing a
     * transmitted invoice cannot be freely voided after 72 hours, so an invoice created and
     * issued by one button press would be something nobody can take back. Issuing stays the
     * deliberate act it already is.
     */
    public function convertToInvoice(Quotation $quotation): Invoice
    {
        if (! $this->canConvert()) {
            throw new RuntimeException(
                'A quote cannot become an invoice without the Invoicing module: there is nothing for it to become.'
            );
        }

        if (! $quotation->isAccepted()) {
            throw new InvalidArgumentException(
                "Only an accepted quote becomes an invoice; this one is {$quotation->status}."
            );
        }

        if ($quotation->invoice_id) {
            throw new InvalidArgumentException(
                'This quote has already been invoiced. Converting again would bill the customer twice.'
            );
        }

        if (! $quotation->contact_id) {
            throw new InvalidArgumentException(
                'This quote is addressed to a lead rather than a customer. Convert the lead first — '
                .'an invoice needs a party the ledger can bill.'
            );
        }

        return TenantTransaction::run(function () use ($quotation): Invoice {
            $invoice = Invoice::create([
                'kind' => Invoice::KIND_SALE,
                // DRAFT, always. Issuing transmits, and transmission is what cannot be undone.
                'status' => Invoice::STATUS_DRAFT,
                'contact_id' => $quotation->contact_id,
                'currency_code' => $quotation->currency_code,
                'exchange_rate' => $quotation->exchange_rate,
                'invoice_date' => now()->toDateString(),
                'subtotal' => $quotation->subtotal,
                'tax_amount' => $quotation->tax_total,
                'total' => $quotation->total,
                'memo' => "From quote {$quotation->number}",
            ]);

            foreach ($quotation->lines as $line) {
                $invoice->lines()->create([
                    'product_id' => $line->product_id,
                    // The discount is already in the price. An invoice line carries what is
                    // being charged, not the negotiation that produced it.
                    'description' => $line->description
                        .((float) $line->discount_pct > 0 ? " (less {$line->discount_pct}%)" : ''),
                    'quantity' => $line->quantity,
                    'unit_price' => (float) $line->quantity > 0
                        ? round($line->netTotal() / (float) $line->quantity, 2)
                        : $line->netTotal(),
                    'line_total' => $line->netTotal(),
                    'tax_rate_id' => $line->tax_rate_id,
                    'tax_amount' => $line->tax_amount,
                ]);
            }

            $quotation->update(['invoice_id' => $invoice->getKey()]);

            activity('Quotation')
                ->performedOn($quotation)
                ->causedBy(auth()->user())
                ->event('converted')
                ->withProperties(['invoice_id' => $invoice->getKey()])
                ->log("Quote {$quotation->number} became draft invoice {$invoice->invoice_number}");

            return $invoice->refresh();
        });
    }
}
