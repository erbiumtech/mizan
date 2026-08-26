<?php

namespace App\Modules\Accounting\Models;

use App\Models\TenantModel as Model;

/**
 * One thing somebody said to the command bot — `docs/ai-command-bot-plan.md` §9.
 *
 * Deliberately not `Auditable`. The activity log records who changed a row and how; this table *is* the
 * record, and logging changes to it would be a log of a log. Rows are written once and updated only to
 * close out their `outcome`.
 */
class CommandUtterance extends Model
{
    public const OUTCOME_BOOKED = 'booked';

    public const OUTCOME_NEEDS_INPUT = 'needs_input';

    public const OUTCOME_CANCELLED = 'cancelled';

    public const OUTCOME_FAILED = 'failed';

    protected $fillable = [
        'user_id', 'utterance', 'transcript', 'locale', 'resolver', 'parsed', 'resolved',
        'direction', 'amount', 'transaction_type_id', 'register_account_id', 'entry_date',
        'outcome', 'outcome_reason', 'journal_entry_id',
    ];

    protected $casts = [
        'parsed' => 'array',
        'resolved' => 'array',
        'amount' => 'decimal:2',
        'entry_date' => 'date',
    ];

    public function transactionType()
    {
        return $this->belongsTo(TransactionType::class);
    }

    public function registerAccount()
    {
        return $this->belongsTo(Account::class, 'register_account_id');
    }

    public function journalEntry()
    {
        return $this->belongsTo(JournalEntry::class);
    }

    /** Commands that resolved to nothing — the raw material for the alias table (§3.2). */
    public function scopeUnresolved($query)
    {
        return $query->whereNull('transaction_type_id')
            ->whereIn('outcome', [self::OUTCOME_NEEDS_INPUT, self::OUTCOME_CANCELLED]);
    }

    /** Dictated rather than typed. A null transcript means somebody used the keyboard. */
    public function scopeDictated($query)
    {
        return $query->whereNotNull('transcript');
    }

    /**
     * **Speech errors, as distinct from parse errors** — §5.
     *
     * The person dictated, read what came back, and changed it before submitting. That edit is the only
     * direct evidence the recogniser got it wrong, and it is why the transcript is stored separately from
     * the utterance rather than overwritten by it.
     *
     * Pair with `unresolved()` to tell the two failure modes apart: a corrected transcript that then
     * resolved fine is the recogniser's fault; an uncorrected one that failed to resolve is the model's.
     */
    public function scopeMistranscribed($query)
    {
        return $query->whereNotNull('transcript')->whereColumn('transcript', '!=', 'utterance');
    }

    /** Did the person have to fix what was heard? */
    public function wasCorrected(): bool
    {
        return $this->transcript !== null && $this->transcript !== $this->utterance;
    }
}
