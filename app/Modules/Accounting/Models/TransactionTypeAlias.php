<?php

namespace App\Modules\Accounting\Models;

use App\Models\TenantModel as Model;
use App\Traits\Auditable;

/**
 * Another word for a category — `docs/ai-command-bot-plan.md` §3.2.
 *
 * Auditable, unlike {@see CommandUtterance}: an alias is a piece of company configuration somebody edits,
 * and "who taught it that بجلی means utilities" is a question with an answer worth keeping. The utterance
 * log records what was said; this records what the company decided.
 */
class TransactionTypeAlias extends Model
{
    use Auditable;

    protected $fillable = ['transaction_type_id', 'alias', 'locale'];

    /**
     * Lower-cased on the way in, with `mb_strtolower` rather than `strtolower`.
     *
     * The ASCII version leaves multi-byte characters untouched, so Urdu aliases would round-trip in
     * whatever case they arrived in and the unique index would happily accept two spellings of one word.
     */
    public function setAliasAttribute(?string $value): void
    {
        $this->attributes['alias'] = $value === null ? null : mb_strtolower(trim($value));
    }

    public function transactionType()
    {
        return $this->belongsTo(TransactionType::class);
    }
}
