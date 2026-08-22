<?php

namespace App\Modules\ConstructionCosting\Models;

use App\Models\TenantModel as Model;
use App\Modules\Construction\Models\Job;
use App\Traits\Auditable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * What an hour of labour costs, from a date — `docs/construction-management-plan.md` §7.2.
 *
 * **A dated table, not a rate column, and this is the load-bearing decision of §7.** "A wage revision effective the
 * first of April must not restate March's job cost. A `cost_rate_per_hour` column on the worker does exactly that,
 * silently, the moment somebody edits it: every historical record recomputes, every closed period's cost changes, and
 * there is no journal, no audit and no report of what moved."
 *
 * The dated table is the first line of defence; the snapshot on the labour record is the second.
 *
 * **Which columns are set is what makes a row specific.** All four scope columns are nullable, and §7.2's ladder is
 * `job+trade -> job -> worker/employee -> trade -> company default`. A row with every scope column null is that last
 * tier. A row is a candidate for a question only if none of its set columns contradicts it, so a rate for job 7 can
 * never be picked for job 9 — the filtering and the ordering both live in {@see LabourRateService}, deliberately not
 * in an index, because nulls in a unique index are distinct in both MySQL and SQLite.
 */
class LabourRate extends Model
{
    use Auditable;

    /**
     * The tiers of §7.2's ladder, most specific first, with the score each carries.
     *
     * Named here rather than left as magic numbers in the resolver, because the *order* is the plan's decision and a
     * reader should be able to check it against §7.2 without reading a comparison function. Note that job-level beats
     * worker-level: a site allowance applies to everybody on that site, including the people who carry their own
     * rate elsewhere.
     */
    public const TIER_JOB_AND_TRADE = 5;

    public const TIER_JOB = 4;

    public const TIER_PERSON = 3;

    public const TIER_TRADE = 2;

    public const TIER_COMPANY_DEFAULT = 1;

    protected $table = 'construction_labour_rates';

    protected $fillable = [
        'job_id', 'trade_id', 'worker_id', 'employee_id',
        'cost_rate_per_hour', 'overtime_multiplier', 'burden_percent',
        'effective_from', 'effective_to', 'notes', 'created_by',
    ];

    protected $casts = [
        'effective_from' => 'date',
        'effective_to' => 'date',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $rate): void {
            $rate->created_by ??= auth()->id();
        });
    }

    public function job(): BelongsTo
    {
        return $this->belongsTo(Job::class, 'job_id');
    }

    public function trade(): BelongsTo
    {
        return $this->belongsTo(Trade::class, 'trade_id');
    }

    public function worker(): BelongsTo
    {
        return $this->belongsTo(Worker::class, 'worker_id');
    }

    /**
     * Rates in force on a date — `effective_from` reached and `effective_to` not yet passed.
     *
     * **`whereDate`, not `where`, and the difference is a whole day.** Laravel's `date` cast serialises through the
     * model's datetime format, so `effective_from` is stored as `2026-04-01 00:00:00` — and
     * `'2026-04-01 00:00:00' <= '2026-04-01'` is false as a string comparison. A plain `where` therefore missed every
     * rate on the exact day it came into force, which is the day a wage revision is dated to. Two tests caught it.
     */
    public function scopeInForceOn(Builder $query, string $date): Builder
    {
        return $query->whereDate('effective_from', '<=', $date)
            ->where(fn (Builder $q) => $q->whereNull('effective_to')->orWhereDate('effective_to', '>=', $date));
    }

    /**
     * Which tier of §7.2's ladder this row sits on.
     *
     * Computed from which scope columns are set rather than stored, for the reason everything in this suite is: a
     * stored tier is a second place for the same fact to live, and the first thing that goes wrong is a row edited
     * from job-level to company-level whose tier does not follow.
     */
    public function tier(): int
    {
        $person = $this->worker_id !== null || $this->employee_id !== null;

        return match (true) {
            $this->job_id !== null && $this->trade_id !== null => self::TIER_JOB_AND_TRADE,
            $this->job_id !== null => self::TIER_JOB,
            $person => self::TIER_PERSON,
            $this->trade_id !== null => self::TIER_TRADE,
            default => self::TIER_COMPANY_DEFAULT,
        };
    }

    /**
     * What this row is a rate *for*, in words, which is the column the register cannot do without.
     *
     * **Not named `scopeDescription()`**, however well that would read: a public method whose name begins with
     * `scope` is a local query scope to Eloquent, so the model would grow a `->description()` query method that
     * throws when anything calls it.
     */
    public function appliesTo(): string
    {
        return match ($this->tier()) {
            self::TIER_JOB_AND_TRADE => "{$this->job?->code} · {$this->trade?->code}",
            self::TIER_JOB => (string) $this->job?->code,
            self::TIER_PERSON => $this->worker?->displayName() ?? "Employee #{$this->employee_id}",
            self::TIER_TRADE => (string) $this->trade?->code,
            default => 'Company default',
        };
    }

    public function displayName(): string
    {
        return $this->appliesTo().' from '.$this->effective_from?->format('d M Y');
    }
}
