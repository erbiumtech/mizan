<?php

namespace App\Modules\Accounting\Models;

use App\Models\TenantModel as Model;
use App\Traits\Auditable;

class BankStatementLine extends Model
{
    use Auditable;

    public const STATUS_UNMATCHED = 'unmatched';

    public const STATUS_AUTO_MATCHED = 'auto_matched';

    public const STATUS_MANUALLY_MATCHED = 'manually_matched';

    public const STATUS_EXCLUDED = 'excluded';

    protected $fillable = [
        'bank_statement_id', 'transaction_date', 'description', 'reference',
        'amount', 'matched_line_id', 'match_status',
    ];

    protected $casts = [
        'transaction_date' => 'date',
        'amount' => 'decimal:2',
    ];

    protected $attributes = [
        'match_status' => self::STATUS_UNMATCHED,
    ];

    public function bankStatement()
    {
        return $this->belongsTo(BankStatement::class);
    }

    public function matchedLine()
    {
        return $this->belongsTo(JournalEntryLine::class, 'matched_line_id');
    }

    public function isMatched(): bool
    {
        return in_array($this->match_status, [
            self::STATUS_AUTO_MATCHED,
            self::STATUS_MANUALLY_MATCHED,
        ], true);
    }
}
