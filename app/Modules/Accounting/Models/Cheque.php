<?php

namespace App\Modules\Accounting\Models;

use App\Models\TenantModel as Model;
use App\Modules\Core\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One number from a book, printed once — docs/saas-launch-plan.md §8.6.
 * Void and reprint each write a row; a number is never reused.
 */
class Cheque extends Model
{
    public const STATUS_PRINTED = 'printed';

    public const STATUS_VOID = 'void';

    public const STATUS_STOPPED = 'stopped';

    public const STATUS_CLEARED = 'cleared';

    protected $fillable = [
        'cheque_book_id', 'company_bank_account_id', 'cheque_no', 'payment_id', 'payee_name', 'amount',
        'cheque_date', 'crossed', 'status', 'printed_at', 'printed_by', 'cleared_at', 'void_reason',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'cheque_date' => 'date',
        'crossed' => 'boolean',
        'printed_at' => 'datetime',
        'cleared_at' => 'datetime',
    ];

    public function book(): BelongsTo
    {
        return $this->belongsTo(ChequeBook::class, 'cheque_book_id');
    }

    public function companyBankAccount(): BelongsTo
    {
        return $this->belongsTo(CompanyBankAccount::class);
    }

    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }

    public function printedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'printed_by');
    }

    public function scopeOutstanding(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_PRINTED);
    }

    public function isOutstanding(): bool
    {
        return $this->status === self::STATUS_PRINTED;
    }
}
