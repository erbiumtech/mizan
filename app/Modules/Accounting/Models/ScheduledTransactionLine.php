<?php

namespace App\Modules\Accounting\Models;

use App\Models\TenantModel as Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ScheduledTransactionLine extends Model
{
    protected $fillable = [
        'scheduled_transaction_id', 'account_id', 'debit_amount',
        'credit_amount', 'description', 'sort',
    ];

    protected $casts = [
        'debit_amount' => 'decimal:2',
        'credit_amount' => 'decimal:2',
        'sort' => 'integer',
    ];

    public function scheduledTransaction(): BelongsTo
    {
        return $this->belongsTo(ScheduledTransaction::class);
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }
}
