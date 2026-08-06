<?php

namespace App\Modules\PersonalFinance\Models;

use App\Models\TenantModel as Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One side of a personal entry: an amount against an account, as either a debit
 * or a credit, never both.
 *
 * Deliberately does NOT use BelongsToOwner. It carries no user_id of its own —
 * its owner is its entry's owner, and the scope on PersonalEntry is what keeps
 * lines private. Giving the line a second copy of the owner would be a second
 * thing to keep in step.
 */
class PersonalEntryLine extends Model
{
    protected $fillable = [
        'personal_entry_id',
        'personal_account_id',
        'debit',
        'credit',
    ];

    protected $casts = [
        'debit' => 'decimal:2',
        'credit' => 'decimal:2',
    ];

    public function entry(): BelongsTo
    {
        return $this->belongsTo(PersonalEntry::class, 'personal_entry_id');
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(PersonalAccount::class, 'personal_account_id');
    }

    /** Positive on a debit, negative on a credit. */
    public function signedAmount(): float
    {
        return round((float) $this->debit - (float) $this->credit, 2);
    }
}
