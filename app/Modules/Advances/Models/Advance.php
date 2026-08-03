<?php

namespace App\Modules\Advances\Models;

use App\Models\TenantModel as Model;
use App\Modules\Employees\Models\Employee;
use App\Traits\Auditable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Money lent to an employee, recovered from payroll in monthly instalments.
 *
 * Recovered and remaining are derived from the recovery ledger rather than kept
 * as columns. A stored balance drifts the first time a payslip is corrected or
 * deleted, and the balance is the number somebody is owed — it has to be a
 * consequence of what actually happened, not a second record of it.
 */
class Advance extends Model
{
    use Auditable;

    public const STATUS_ACTIVE = 'active';

    public const STATUS_SETTLED = 'settled';

    public const STATUS_CANCELLED = 'cancelled';

    protected $fillable = [
        'employee_id', 'total_amount', 'monthly_instalment',
        'started_on', 'status', 'reference', 'notes',
    ];

    protected $casts = [
        'total_amount' => 'decimal:2',
        'monthly_instalment' => 'decimal:2',
        'started_on' => 'date',
    ];

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function recoveries(): HasMany
    {
        return $this->hasMany(AdvanceRecovery::class);
    }

    /** Still being recovered, and started. */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_ACTIVE);
    }

    /**
     * Pass a payslip id to measure the advance as it stood before that payslip
     * touched it. Payroll recalculates a payslip on every save, and counting its
     * own recovery against it would shrink its deduction a little each time.
     */
    public function recoveredAmount(?int $excludingPayslipId = null): float
    {
        return round((float) $this->recoveries()
            ->when($excludingPayslipId, fn ($query) => $query->where(
                // Recoveries recorded by hand carry no payslip and always count.
                fn ($inner) => $inner->whereNull('payslip_id')->orWhere('payslip_id', '!=', $excludingPayslipId)
            ))
            ->sum('amount'), 2);
    }

    public function remainingAmount(?int $excludingPayslipId = null): float
    {
        return round(max(0, (float) $this->total_amount - $this->recoveredAmount($excludingPayslipId)), 2);
    }

    public function isSettled(): bool
    {
        return $this->status === self::STATUS_SETTLED || $this->remainingAmount() <= 0;
    }

    /**
     * What to deduct this month: the instalment, or whatever is left if that is
     * less. Without the floor the last instalment would over-recover and the
     * employee would be owed money back.
     */
    public function instalmentDue(?int $excludingPayslipId = null): float
    {
        if ($this->status !== self::STATUS_ACTIVE) {
            return 0.0;
        }

        return round(min((float) $this->monthly_instalment, $this->remainingAmount($excludingPayslipId)), 2);
    }

    /**
     * Close it once nothing is left, so it stops appearing as active and stops
     * being deducted.
     */
    public function settleIfCleared(): void
    {
        if ($this->status === self::STATUS_ACTIVE && $this->remainingAmount() <= 0) {
            $this->update(['status' => self::STATUS_SETTLED]);
        }
    }
}
