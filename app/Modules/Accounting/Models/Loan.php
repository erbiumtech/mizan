<?php

namespace App\Modules\Accounting\Models;

use App\Models\TenantModel as Model;
use App\Traits\Auditable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Money borrowed and repaid in instalments, with the schedule worked out.
 *
 * The thing a spreadsheet gets wrong and this does not: every instalment is the
 * same amount, but the split inside it moves month by month — interest is
 * charged on what is still owed, so it shrinks while the principal portion
 * grows. Booking a flat split puts the wrong number in interest expense every
 * single month and leaves the liability nowhere near zero at the end.
 */
class Loan extends Model
{
    use Auditable;

    protected $fillable = [
        'name', 'lender', 'liability_account_id', 'interest_account_id',
        'payment_account_id', 'principal', 'annual_rate', 'term_months',
        'starts_on', 'is_active', 'notes',
    ];

    protected $casts = [
        'principal' => 'decimal:2',
        'annual_rate' => 'decimal:4',
        'term_months' => 'integer',
        'starts_on' => 'date',
        'is_active' => 'boolean',
    ];

    protected $attributes = [
        'annual_rate' => 0,
        'is_active' => true,
    ];

    public function liabilityAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'liability_account_id');
    }

    public function interestAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'interest_account_id');
    }

    public function paymentAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'payment_account_id');
    }

    public function instalments(): HasMany
    {
        return $this->hasMany(LoanInstalment::class)->orderBy('number');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /** The monthly rate as a fraction: 12% a year is 0.01 a month. */
    public function monthlyRate(): float
    {
        return (float) $this->annual_rate / 100 / 12;
    }

    /** Instalments recorded so far. */
    public function paidCount(): int
    {
        return $this->instalments()->whereNotNull('journal_entry_id')->count();
    }

    /**
     * What is still owed according to the schedule.
     *
     * Taken from the last recorded instalment's closing balance rather than from
     * the liability account, and the difference is the point: this is what the
     * agreement says is left, which is the figure to reconcile the account
     * against rather than a restatement of it.
     */
    public function scheduledOutstanding(): float
    {
        // reorder(), not orderByDesc(): the instalments() relation already sorts
        // ascending, and a second ORDER BY appended to the first does nothing —
        // the query still returns instalment 1, so a fully repaid loan reports
        // the balance it had after its opening payment.
        $last = $this->instalments()
            ->whereNotNull('journal_entry_id')
            ->reorder('number', 'desc')
            ->first();

        return round((float) ($last->closing_balance ?? $this->principal), 2);
    }

    public function totalInterest(): float
    {
        return round((float) $this->instalments()->sum('interest'), 2);
    }

    /** The next instalment with no entry against it, or null when finished. */
    public function nextDue(): ?LoanInstalment
    {
        return $this->instalments()->whereNull('journal_entry_id')->orderBy('number')->first();
    }
}
