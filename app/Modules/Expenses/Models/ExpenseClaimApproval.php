<?php

namespace App\Modules\Expenses\Models;

use App\Models\TenantModel as Model;
use App\Modules\Core\Models\User;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One step of a claim's approval chain — docs/saas-launch-plan.md §14.3, LeaveApproval's shape. */
class ExpenseClaimApproval extends Model
{
    protected $fillable = ['expense_claim_id', 'step', 'role', 'approved_by', 'approved_at'];

    protected $casts = ['step' => 'integer', 'approved_at' => 'datetime'];

    public function claim(): BelongsTo
    {
        return $this->belongsTo(ExpenseClaim::class, 'expense_claim_id');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }
}
