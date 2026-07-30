<?php

namespace App\Modules\Accounting\Models;

use App\Models\TenantModel as Model;
use App\Models\User;
use App\Traits\Auditable;

class BankStatement extends Model
{
    use Auditable;

    public const STATUS_DRAFT = 'draft';

    public const STATUS_IN_PROGRESS = 'in_progress';

    public const STATUS_COMPLETED = 'completed';

    protected $fillable = [
        'account_id', 'statement_date', 'opening_balance', 'closing_balance',
        'status', 'completed_by', 'completed_at',
    ];

    protected $casts = [
        'statement_date' => 'date',
        'opening_balance' => 'decimal:2',
        'closing_balance' => 'decimal:2',
        'completed_at' => 'datetime',
    ];

    protected $attributes = [
        'opening_balance' => 0,
        'closing_balance' => 0,
        'status' => self::STATUS_DRAFT,
    ];

    public function account()
    {
        return $this->belongsTo(Account::class);
    }

    public function lines()
    {
        return $this->hasMany(BankStatementLine::class);
    }

    public function completedBy()
    {
        return $this->belongsTo(User::class, 'completed_by');
    }

    public function isCompleted(): bool
    {
        return $this->status === self::STATUS_COMPLETED;
    }

    /**
     * A statement is reconcilable when every line is either matched or
     * explicitly excluded (nothing left unmatched).
     */
    public function isFullyMatched(): bool
    {
        return ! $this->lines()
            ->where('match_status', BankStatementLine::STATUS_UNMATCHED)
            ->exists();
    }

    public function matchedCount(): int
    {
        return $this->lines()
            ->whereIn('match_status', [
                BankStatementLine::STATUS_AUTO_MATCHED,
                BankStatementLine::STATUS_MANUALLY_MATCHED,
            ])->count();
    }
}
