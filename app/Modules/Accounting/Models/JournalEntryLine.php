<?php

namespace App\Modules\Accounting\Models;

use App\Models\TenantModel as Model;
use App\Traits\Auditable;

class JournalEntryLine extends Model
{
    use Auditable;

    protected $fillable = [
        'journal_entry_id', 'account_id', 'debit_amount', 'credit_amount', 'description', 'reconciled_at',
        'currency_code', 'foreign_debit_amount', 'foreign_credit_amount', 'rate',
    ];

    protected $casts = [
        'debit_amount' => 'decimal:2',
        'credit_amount' => 'decimal:2',
        'reconciled_at' => 'datetime',
            'foreign_debit_amount' => 'decimal:4',
        'foreign_credit_amount' => 'decimal:4',
        'rate' => 'decimal:8',
    ];

    public function journalEntry()
    {
        return $this->belongsTo(JournalEntry::class);
    }

    /**
     * Signed effect on the account's normal-balance side. For a debit-normal
     * bank account this is positive for inflows (debits) and negative for
     * outflows (credits) — matching how bank statement amounts are signed.
     */
    public function getSignedAmountAttribute(): float
    {
        return round((float) $this->debit_amount - (float) $this->credit_amount, 2);
    }

    public function isReconciled(): bool
    {
        return $this->reconciled_at !== null;
    }

    public function account()
    {
        return $this->belongsTo(Account::class);
    }

    /**
     * The bank statement line, if any, that reconciled this ledger line.
     */
    public function bankStatementLine()
    {
        return $this->hasOne(BankStatementLine::class, 'matched_line_id');
    }

    public function getAmountAttribute(): float
    {
        return (float) ($this->debit_amount > 0 ? $this->debit_amount : $this->credit_amount);
    }

    public function getTypeAttribute(): string
    {
        return $this->debit_amount > 0 ? 'debit' : 'credit';
    }
}
