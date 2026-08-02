<?php

namespace App\Modules\Accounting\Models;

use App\Models\TenantModel as Model;
use App\Traits\Auditable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A standing monthly payment to a beneficiary.
 *
 * What it does not hold is a running record of what has been paid: that is the
 * payments it raised, found through the relation. A counter here would drift the
 * first time a month was regenerated or a payment deleted.
 */
class BeneficiarySubscription extends Model
{
    use Auditable;

    protected $fillable = [
        'beneficiary_id', 'transaction_type_id', 'company_bank_account_id',
        'description', 'amount', 'due_day', 'starts_on', 'ends_on', 'is_active', 'notes',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'due_day' => 'integer',
        'starts_on' => 'date',
        'ends_on' => 'date',
        'is_active' => 'boolean',
    ];

    protected $attributes = [
        'due_day' => 1,
        'is_active' => true,
    ];

    public function beneficiary(): BelongsTo
    {
        return $this->belongsTo(Beneficiary::class);
    }

    /** Its own type where it has one, otherwise the beneficiary's. */
    public function transactionType(): BelongsTo
    {
        return $this->belongsTo(TransactionType::class);
    }

    public function companyBankAccount(): BelongsTo
    {
        return $this->belongsTo(CompanyBankAccount::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function resolvedTransactionTypeId(): ?int
    {
        return $this->transaction_type_id ?? $this->beneficiary?->transaction_type_id;
    }

    /**
     * Is this subscription running in the month starting $period?
     *
     * Compared by month rather than by day: a subscription starting on the 15th
     * still bills for that month, and one ending on the 3rd bills for its last
     * month in full. A monthly agreement is not pro-rated by this system.
     */
    public function coversPeriod(Carbon $period): bool
    {
        if (! $this->is_active) {
            return false;
        }

        if ($this->starts_on->startOfMonth()->greaterThan($period->copy()->startOfMonth())) {
            return false;
        }

        return $this->ends_on === null
            || $this->ends_on->startOfMonth()->greaterThanOrEqualTo($period->copy()->startOfMonth());
    }

    /**
     * When the payment for this month should be dated.
     *
     * The due day, clamped to the length of the month — a subscription due on the
     * 31st is due on the 28th of February, not in March. Never before the run
     * date: back-dating a payment that has not been sent yet would post it into
     * the ledger ahead of itself and, on a closed month, not post at all.
     */
    public function valueDateFor(Carbon $period, ?Carbon $runningOn = null): Carbon
    {
        $runningOn ??= Carbon::now();
        $month = $period->copy()->startOfMonth();

        $due = $month->copy()->day(min($this->due_day, $month->daysInMonth));

        return $due->lessThan($runningOn->copy()->startOfDay())
            ? $runningOn->copy()->startOfDay()
            : $due;
    }
}
