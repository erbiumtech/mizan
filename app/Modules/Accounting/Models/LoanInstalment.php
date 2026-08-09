<?php

namespace App\Modules\Accounting\Models;

use App\Models\TenantModel as Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One row of the amortisation table: what is owed before it, what the payment
 * splits into, and what is left after.
 */
class LoanInstalment extends Model
{
    protected $fillable = [
        'loan_id', 'number', 'due_on', 'opening_balance', 'payment',
        'interest', 'principal', 'closing_balance', 'journal_entry_id',
    ];

    protected $casts = [
        'number' => 'integer',
        'due_on' => 'date',
        'opening_balance' => 'decimal:2',
        'payment' => 'decimal:2',
        'interest' => 'decimal:2',
        'principal' => 'decimal:2',
        'closing_balance' => 'decimal:2',
    ];

    public function loan(): BelongsTo
    {
        return $this->belongsTo(Loan::class);
    }

    public function journalEntry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class);
    }

    public function isRecorded(): bool
    {
        return $this->journal_entry_id !== null;
    }
}
