<?php

namespace App\Modules\Expenses\Models;

use App\Models\TenantModel as Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One thing bought — docs/saas-launch-plan.md §14.3. The claim's amount is the sum of these. */
class ExpenseClaimLine extends Model
{
    protected $fillable = ['expense_claim_id', 'spent_on', 'expense_category_id', 'description', 'amount', 'receipt_path', 'sort'];

    protected $casts = ['spent_on' => 'date', 'amount' => 'decimal:2', 'sort' => 'integer'];

    protected static function booted(): void
    {
        $sync = fn (self $line) => $line->claim?->recalculateFromLines();

        static::saved($sync);
        static::deleted($sync);
    }

    public function claim(): BelongsTo
    {
        return $this->belongsTo(ExpenseClaim::class, 'expense_claim_id');
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(ExpenseCategory::class, 'expense_category_id');
    }
}
