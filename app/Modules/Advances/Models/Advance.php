<?php

namespace App\Modules\Advances\Models;

use App\Models\TenantModel as Model;
use App\Modules\Employees\Models\Employee;
use App\Traits\Auditable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

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
        'started_on', 'recovery_starts_on', 'skipped_months', 'status', 'reference', 'notes',
    ];

    protected $casts = [
        'total_amount' => 'decimal:2',
        'monthly_instalment' => 'decimal:2',
        'started_on' => 'date',
        'recovery_starts_on' => 'date',
        'skipped_months' => 'array',
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
     * Whether this advance is recovered from the payroll month beginning `$periodOn`.
     *
     * Two ways it is not: recovery has not started yet — an advance given in August
     * whose agreement says deductions begin in October — or the month is one somebody
     * chose to skip. A caller that does not know which month it is asking about gets
     * `true`, which is how every caller behaved before there was a schedule.
     *
     * Compared as `Y-m` rather than as dates: a payroll month is a month, and the two
     * sides of the comparison are a payslip period and a date somebody picked off a
     * calendar, which will not agree on the day.
     */
    public function recoversIn(?string $periodOn): bool
    {
        if ($periodOn === null) {
            return true;
        }

        $month = Carbon::parse($periodOn)->format('Y-m');

        if ($this->recovery_starts_on && $month < $this->recovery_starts_on->format('Y-m')) {
            return false;
        }

        return ! in_array($month, $this->skipped_months ?? [], true);
    }

    /**
     * What to deduct this month: the instalment, or whatever is left if that is
     * less. Without the floor the last instalment would over-recover and the
     * employee would be owed money back.
     *
     * Nothing at all in a month the schedule does not recover in. Skipping does not
     * write anything off — the balance is untouched, so the advance simply runs one
     * month longer.
     */
    public function instalmentDue(?int $excludingPayslipId = null, ?string $periodOn = null): float
    {
        if ($this->status !== self::STATUS_ACTIVE || ! $this->recoversIn($periodOn)) {
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
