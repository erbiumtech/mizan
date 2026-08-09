<?php

namespace App\Modules\Accounting\Models;

use App\Models\TenantModel as Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One account's plan for one month.
 *
 * There is no "annual" row. The yearly figure is the sum of these, which is the
 * property that keeps a per-month adjustment from silently disagreeing with the
 * total shown beside it.
 */
class BudgetLine extends Model
{
    protected $fillable = ['budget_id', 'account_id', 'period_start', 'amount'];

    protected $casts = [
        'period_start' => 'date',
        'amount' => 'decimal:2',
    ];

    public function budget(): BelongsTo
    {
        return $this->belongsTo(Budget::class);
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }
}
