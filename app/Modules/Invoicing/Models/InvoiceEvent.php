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
