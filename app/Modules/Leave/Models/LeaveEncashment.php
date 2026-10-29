<?php

namespace App\Modules\Leave\Models;

use App\Models\TenantModel as Model;
use App\Modules\Core\Models\FiscalYear;
use App\Modules\Core\Models\User;
use App\Modules\Employees\Models\Employee;
use App\Traits\Auditable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Encashment outside a separation — docs/saas-launch-plan.md §14.4: a request that,
 * once approved, debits the balance and adds a payslip input for the month named.
 */
class LeaveEncashment extends Model
{
    use Auditable;

    public const STATUS_PENDING = 'pending';

    public const STATUS_APPROVED = 'approved';

    public const STATUS_REFUSED = 'refused';

    protected $fillable = [
        'employee_id', 'leave_type_id', 'days', 'amount', 'month', 'fiscal_year_id', 'status', 'reason',
        'requested_by', 'decided_by', 'decided_at', 'refusal_reason', 'leave_adjustment_id',
    ];

    protected $casts = ['days' => 'decimal:1', 'amount' => 'decimal:2', 'decided_at' => 'datetime'];

    protected $attributes = ['status' => self::STATUS_PENDING];

    protected static function booted(): void
    {
        static::creating(fn (self $e) => $e->requested_by ??= auth()->id());
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function leaveType(): BelongsTo
    {
        return $this->belongsTo(LeaveType::class);
    }

    public function fiscalYear(): BelongsTo
    {
        return $this->belongsTo(FiscalYear::class);
    }

    public function decider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }

    public function adjustment(): BelongsTo
    {
        return $this->belongsTo(LeaveAdjustment::class, 'leave_adjustment_id');
    }

    public function isPending(): bool
    {
        return $this->status === self::STATUS_PENDING;
    }
}
