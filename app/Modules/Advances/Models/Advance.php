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

    /**
     * The state that means "waiting for someone to approve it": its list puts these rows first
     * (App\Filament\Support\PendingFirst). [column, values], or [column, null] for "while empty".
     */
    public const PENDING_APPROVAL = ['status', [self::STATUS_REQUESTED]];

    /** Applied for and not yet decided — docs/saas-launch-plan.md §14.3. Payroll deducts nothing. */
    public const STATUS_REQUESTED = 'requested';

    public const STATUS_REFUSED = 'refused';

    /** Deductions stopped for a while; nothing written off, the advance runs longer. */
    public const STATUS_PAUSED = 'paused';

    public const STATUS_ACTIVE = 'active';

    public const STATUS_SETTLED = 'settled';

    public const STATUS_CANCELLED = 'cancelled';

    protected $fillable = [
        'employee_id', 'advance_type_id', 'total_amount', 'interest_amount', 'monthly_instalment', 'instalments',
        'started_on', 'recovery_starts_on', 'skipped_months', 'status', 'reference', 'notes',
        'requested_by', 'requested_at', 'decided_by', 'decided_at', 'refusal_reason', 'paused_at', 'paused_reason',
    ];

    protected $casts = [
        'total_amount' => 'decimal:2',
        'monthly_instalment' => 'decimal:2',
        'started_on' => 'date',
        'recovery_starts_on' => 'date',
        'skipped_months' => 'array',
        'interest_amount' => 'decimal:2',
        'instalments' => 'integer',
        'requested_at' => 'datetime',
        'decided_at' => 'datetime',
        'paused_at' => 'datetime',
    ];

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function type(): BelongsTo
    {
        return $this->belongsTo(AdvanceType::class, 'advance_type_id');
    }

    /** What has to come back: the amount plus any interest the type adds. */
    public function totalRecoverable(): float
    {
        return round((float) $this->total_amount + (float) $this->interest_amount, 2);
    }

    /**
     * The months ahead and what each takes — docs/saas-launch-plan.md §14.3. Projected from what is
     * left: skipped months are stepped over, and a paused advance projects nothing until resumed.
     *
     * @return array<int, array{month: string, amount: float}>
     */
    public function schedule(?string $from = null, int $limit = 60): array
    {
        if ($this->status !== self::STATUS_ACTIVE || (float) $this->monthly_instalment <= 0) {
            return [];
        }

        $left = $this->remainingAmount();
        $month = Carbon::parse($from ?? now())->startOfMonth();

        $first = $this->firstRecoveryMonth();

        if ($first !== null && $month->lt($first)) {
            $month = $first->copy();
        }

        $rows = [];

        while ($left > 0.005 && count($rows) < $limit) {
            if (! in_array($month->format('Y-m'), $this->skipped_months ?? [], true)) {
                $take = round(min((float) $this->monthly_instalment, $left), 2);
                $rows[] = ['month' => $month->format('Y-m'), 'amount' => $take];
                $left = round($left - $take, 2);
            }

            $month->addMonth();
        }

        return $rows;
    }

    /** Instalments the schedule said were due by a date, against what was recorded — the defaulters' figure. */
    public function arrearsAt(string|Carbon $asOf): float
    {
        if (! in_array($this->status, [self::STATUS_ACTIVE, self::STATUS_PAUSED], true) || (float) $this->monthly_instalment <= 0) {
            return 0.0;
        }

        $asOf = Carbon::parse($asOf)->startOfMonth();
        $start = ($this->firstRecoveryMonth() ?? $this->started_on)?->copy()->startOfMonth();

        if ($start === null) {
            return 0.0;
        }

        $expected = 0.0;
        $month = $start->copy();

        // Up to the month before the one asked about: this month's payroll may not have run yet.
        while ($month->lt($asOf) && $expected < $this->totalRecoverable()) {
            if (! in_array($month->format('Y-m'), $this->skipped_months ?? [], true)) {
                $expected = round(min($this->totalRecoverable(), $expected + (float) $this->monthly_instalment), 2);
            }

            $month->addMonth();
        }

        return round(max(0, $expected - $this->recoveredAmount()), 2);
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
        return round(max(0, $this->totalRecoverable() - $this->recoveredAmount($excludingPayslipId)), 2);
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
        $first = $this->firstRecoveryMonth();

        if ($first !== null && $month < $first->format('Y-m')) {
            return false;
        }

        return ! in_array($month, $this->skipped_months ?? [], true);
    }

    /** Company setting: when recovery may start relative to when the advance is given — B17. */
    public const RECOVERY_STARTS_SETTING = 'advances.recovery_starts';

    /** Never before the month the advance is given — the default. */
    public const RECOVERY_FROM_MONTH_GIVEN = 'month_given';

    /** From the month after it is given. */
    public const RECOVERY_FROM_MONTH_AFTER = 'month_after';

    /** Any payroll month not yet raised, before the advance was given included — how it behaved before the setting. */
    public const RECOVERY_FROM_ANY_MONTH = 'any_month';

    /**
     * The first month this advance may be deducted in.
     *
     * The start month somebody chose wins. Without one, the company's rule decides (Company Settings → Advances):
     * by default never before the month the advance was given, so an advance handed over on 30 October is not
     * taken from a September payslip raised afterwards — which a blank start used to allow.
     */
    public function firstRecoveryMonth(): ?Carbon
    {
        if ($this->recovery_starts_on) {
            return $this->recovery_starts_on->copy()->startOfMonth();
        }

        if (! $this->started_on) {
            return null;
        }

        return match (setting(self::RECOVERY_STARTS_SETTING, self::RECOVERY_FROM_MONTH_GIVEN)) {
            self::RECOVERY_FROM_ANY_MONTH => null,
            self::RECOVERY_FROM_MONTH_AFTER => $this->started_on->copy()->startOfMonth()->addMonthNoOverflow(),
            default => $this->started_on->copy()->startOfMonth(),
        };
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
