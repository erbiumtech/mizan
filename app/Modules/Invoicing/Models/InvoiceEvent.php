<?php

namespace App\Modules\Invoicing\Models;

use App\Models\TenantModel as Model;
use App\Modules\Core\Models\User;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One thing that happened to an invoice.
 *
 * Deliberately not Auditable: this *is* the audit of a document's life, and logging
 * changes to the log is noise.
 */
class InvoiceEvent extends Model
{
    public const CREATED = 'created';

    public const ISSUED = 'issued';

    /** Somebody produced the PDF — the closest thing to "sent" this app can witness. */
    public const PRINTED = 'printed';

    public const PAYMENT = 'payment';

    public const VOIDED = 'voided';

    /**
     * A credit note was raised against this invoice.
     *
     * Recorded against the **invoice**, not the credit note — the credit note gets its own
     * `created` and `issued` events like any document. This is the entry that makes the
     * invoice's own history say it was corrected, which is the thing somebody reading a
     * reported invoice a year later needs to see without knowing to go looking elsewhere.
     */
    public const CREDITED = 'credited';

    /**
     * A debit note was raised against this bill — `docs/erpnext-gap-plan.md` Phase 5.
     *
     * Its own event rather than `CREDITED` on the other side of the ledger, because the bill's history is
     * read by somebody asking what happened to a supplier's charge and "credited" is the wrong verb for
     * money coming back to us. `color()` needs no arm for it: the default is `warning`, which is what a
     * correction should look like.
     */
    public const DEBITED = 'debited';

    /**
     * An overdue reminder was emailed to the customer — Phase 5.
     *
     * Recorded here rather than in a column on the invoice, and that is what made dunning cheap: the event
     * log already exists, is already shown on the invoice, and already carries a date. `latest` of these is
     * how the reminder service knows not to write again tomorrow, so the repeat interval needed no schema at
     * all — and somebody arguing with a customer about whether they were chased has the answer on the
     * document.
     */
    public const REMINDED = 'reminded';

    protected $fillable = ['invoice_id', 'event', 'description', 'amount', 'caused_by'];

    protected $casts = ['amount' => 'decimal:2'];

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    public function causer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'caused_by');
    }

    /**
     * Record an event against an invoice.
     *
     * Attributed to whoever is signed in, and to nobody when the actor is the
     * scheduler or a command — which is honest, and better than blaming a user who
     * was not there.
     */
    public static function record(Invoice $invoice, string $event, string $description, ?float $amount = null): self
    {
        return static::create([
            'invoice_id' => $invoice->getKey(),
            'event' => $event,
            'description' => $description,
            'amount' => $amount,
            'caused_by' => auth()->id(),
        ]);
    }

    public function color(): string
    {
        return match ($this->event) {
            self::ISSUED => 'info',
            self::PAYMENT => 'success',
            self::VOIDED => 'danger',
            self::PRINTED => 'gray',
            default => 'warning',
        };
    }
}
