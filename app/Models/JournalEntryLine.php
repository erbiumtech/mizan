<?php

namespace App\Models;

use App\Traits\Auditable;
use Illuminate\Database\Eloquent\Model;

class JournalEntryLine extends Model
{
    use Auditable;

    protected $fillable = [
        'journal_entry_id', 'account_id', 'debit_amount', 'credit_amount', 'description',
    ];

    protected $casts = [
        'debit_amount' => 'decimal:2',
        'credit_amount' => 'decimal:2',
    ];

    public function journalEntry()
    {
        return $this->belongsTo(JournalEntry::class);
    }

    public function account()
    {
        return $this->belongsTo(Account::class);
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
