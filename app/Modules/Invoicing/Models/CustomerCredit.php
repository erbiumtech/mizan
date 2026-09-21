<?php

namespace App\Modules\Invoicing\Models;

use App\Models\TenantModel as Model;
use App\Modules\Accounting\Models\JournalEntry;
use App\Traits\Auditable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Money this customer has paid that no invoice has claimed yet.
 *
 * Whose it is, how much arrived, and how much of it is left — the money itself is in 2600 Customer
 * Advances. Nothing edits one of these: a credit is created by a receipt and reduced by being applied, and
 * both go through `InvoiceService`, which posts as it writes.
 */
class CustomerCredit extends Model
{
    use Auditable;

    protected $fillable = [
        'contact_id', 'received_on', 'amount', 'applied_amount', 'reference', 'notes', 'journal_entry_id',
    ];

    protected $casts = [
        'received_on' => 'date',
        'amount' => 'decimal:2',
        'applied_amount' => 'decimal:2',
    ];

    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }

    public function journalEntry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class);
    }

    /** What is left to put against an invoice. */
    public function remaining(): float
    {
        return round((float) $this->amount - (float) $this->applied_amount, 2);
    }

    public function isSpent(): bool
    {
        return $this->remaining() < 0.005;
    }

    /** Credits with something left on them — what an invoice can actually be settled from. */
    public function scopeAvailable(Builder $query): Builder
    {
        return $query->whereColumn('applied_amount', '<', 'amount');
    }

    /** "12 Aug 2026 — 50,000 (30,000 left) · TT-4471", the label every picker needs. */
    public function label(): string
    {
        return implode(' ', array_filter([
            $this->received_on?->format('j M Y').' —',
            number_format((float) $this->amount, 2),
            '('.number_format($this->remaining(), 2).' left)',
            $this->reference ? '· '.$this->reference : null,
        ]));
    }
}
