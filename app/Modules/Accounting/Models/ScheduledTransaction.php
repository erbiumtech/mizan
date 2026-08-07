<?php

namespace App\Modules\Accounting\Models;

use App\Models\TenantModel as Model;
use App\Traits\Auditable;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A standing instruction to raise the same journal entry on a rhythm: rent on
 * the 1st, the loan instalment on the 5th, the annual licence every July.
 *
 * It raises DRAFTS, never posted entries. That is the same rule recurring
 * invoices follow and for the same reason: an entry reaching the ledger is a
 * decision somebody makes after reading it, and a cron job is not somebody. It
 * also keeps segregation of duties intact — a schedule that posted directly
 * would be a way to put anything in the books without an approver.
 */
class ScheduledTransaction extends Model
{
    use Auditable;

    protected $fillable = [
        'name', 'memo', 'reference', 'entry_type', 'interval_months',
        'day_of_month', 'starts_on', 'ends_on', 'is_active',
    ];

    protected $casts = [
        'interval_months' => 'integer',
        'day_of_month' => 'integer',
        'starts_on' => 'date',
        'ends_on' => 'date',
        'is_active' => 'boolean',
    ];

    protected $attributes = [
        'entry_type' => 'general',
        'interval_months' => 1,
        'day_of_month' => 1,
        'is_active' => true,
    ];

    /** How the intervals we offer are named. */
    public const INTERVALS = [
        1 => 'Monthly',
        3 => 'Quarterly',
        6 => 'Every six months',
        12 => 'Yearly',
    ];

    public function lines(): HasMany
    {
        return $this->hasMany(ScheduledTransactionLine::class)->orderBy('sort')->orderBy('id');
    }

    /** The entries this schedule has already raised. */
    public function entries(): HasMany
    {
        return $this->hasMany(JournalEntry::class, 'source_id')
            ->where('source_type', \App\Support\ModuleMap::alias(static::class));
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function intervalLabel(): string
    {
        return self::INTERVALS[$this->interval_months] ?? "Every {$this->interval_months} months";
    }

    /** Debits and credits are equal, which is what makes the entry postable. */
    public function isBalanced(): bool
    {
        return abs($this->totalDebits() - $this->totalCredits()) < 0.005;
    }

    public function totalDebits(): float
    {
        return round((float) $this->lines->sum('debit_amount'), 2);
    }

    public function totalCredits(): float
    {
        return round((float) $this->lines->sum('credit_amount'), 2);
    }

    /**
     * Every date this schedule falls due, from its start up to and including
     * $upTo.
     *
     * The day is clamped to the month's length rather than rolled forward: a
     * schedule set to the 31st fires on 28 February, because the alternative is
     * skipping February entirely, and a rent that quietly misses a month is
     * worse than one dated two days early.
     *
     * @return array<int, CarbonImmutable>
     */
    public function occurrencesUpTo(CarbonImmutable $upTo): array
    {
        $interval = max(1, $this->interval_months);
        $end = $this->ends_on !== null
            ? CarbonImmutable::parse($this->ends_on)->startOfDay()
            : null;

        if ($end !== null && $end->lessThan($upTo)) {
            $upTo = $end;
        }

        $dates = [];
        $anchor = CarbonImmutable::parse($this->starts_on)->startOfMonth();
        $guard = 0;

        while ($guard++ < 1200) {
            $date = $anchor->setDay(min($this->day_of_month, $anchor->daysInMonth));

            if ($date->greaterThan($upTo)) {
                break;
            }

            // The first occurrence never predates the start: a schedule beginning
            // on the 20th with a day_of_month of 1 starts next month, not three
            // weeks before it was agreed.
            if (! $date->lessThan(CarbonImmutable::parse($this->starts_on)->startOfDay())) {
                $dates[] = $date;
            }

            $anchor = $anchor->addMonths($interval);
        }

        return $dates;
    }
}
