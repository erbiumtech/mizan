<?php

namespace App\Modules\ConstructionCosting\Models;

use App\Models\TenantModel as Model;
use App\Modules\Accounting\Models\JournalEntry;
use App\Modules\Construction\Models\Job;
use App\Traits\Auditable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One job's work-in-progress position for one month — `docs/construction-management-plan.md` §4.4.
 *
 * **The one stored total in this plan that is not a performance materialisation**, and §4.4 argues the exception rather
 * than assuming it: "a WIP position is a judgement at a point in time — the surveyor's forecast, the surveyed percentage,
 * the loss provision — not a derivation from immutable facts."
 *
 * `locked_at` is the whole mechanism. Unlocked, this row is recomputed from today's facts every time somebody opens it.
 * Locked, it is frozen — because the month was signed off, reported to a bank and used to compute a bonus, and
 * recomputing it with today's forecast would restate all three silently.
 *
 * **Exactly one of `contract_asset` and `contract_liability` is non-zero.** They are opposite sides of the balance sheet:
 * costs and recognised profit in excess of billings against billings in excess of both. Two columns rather than one
 * signed figure, because a sign convention is something somebody gets backwards.
 */
class WipSnapshot extends Model
{
    use Auditable;

    public const METHOD_COST_TO_COST = 'cost_to_cost';

    public const METHOD_SURVEYED = 'surveyed';

    public const METHOD_MILESTONE = 'milestone';

    /** @var array<string, string> */
    public const METHODS = [
        self::METHOD_COST_TO_COST => 'Cost to cost',
        self::METHOD_SURVEYED => 'Surveyed',
        self::METHOD_MILESTONE => 'Milestone',
    ];

    protected $table = 'construction_wip_snapshots';

    protected $fillable = [
        'job_id', 'period_start', 'percent_complete_method', 'percent_complete',
        'cost_to_date', 'accrued_to_date', 'forecast_final_cost', 'forecast_run_id',
        'contract_sum_original', 'variations_approved', 'variations_pending', 'contract_value',
        'revenue_recognised', 'billings_to_date', 'provision_for_loss',
        'contract_asset', 'contract_liability',
        'locked_at', 'locked_by', 'previous_snapshot_id', 'journal_entry_id', 'posted_at',
        'notes', 'created_by',
    ];

    protected $casts = [
        'period_start' => 'date',
        'percent_complete' => 'decimal:4',
        'cost_to_date' => 'decimal:2',
        'accrued_to_date' => 'decimal:2',
        'forecast_final_cost' => 'decimal:2',
        'contract_sum_original' => 'decimal:2',
        'variations_approved' => 'decimal:2',
        'variations_pending' => 'decimal:2',
        'contract_value' => 'decimal:2',
        'revenue_recognised' => 'decimal:2',
        'billings_to_date' => 'decimal:2',
        'provision_for_loss' => 'decimal:2',
        'contract_asset' => 'decimal:2',
        'contract_liability' => 'decimal:2',
        'locked_at' => 'datetime',
        'posted_at' => 'datetime',
    ];

    public function job(): BelongsTo
    {
        return $this->belongsTo(Job::class);
    }

    public function forecastRun(): BelongsTo
    {
        return $this->belongsTo(ForecastRun::class);
    }

    public function previousSnapshot(): BelongsTo
    {
        return $this->belongsTo(self::class, 'previous_snapshot_id');
    }

    public function journalEntry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class);
    }

    public function isLocked(): bool
    {
        return $this->locked_at !== null;
    }

    public function isPosted(): bool
    {
        return $this->journal_entry_id !== null;
    }

    /** Whether this month is running at a loss on today's forecast. */
    public function isLossMaking(): bool
    {
        return (float) $this->provision_for_loss > 0.0;
    }

    /**
     * Total cost including accruals, which is what a WIP position is built on.
     *
     * §3.5 keeps actual and accrued apart on the cost report because mixing them makes CPI move when nothing happened on
     * site. A balance-sheet position is the other case: a delivery received and not invoiced is cost incurred, and
     * excluding it would understate the position by whatever had not been billed yet.
     */
    public function totalCost(): float
    {
        return round((float) $this->cost_to_date + (float) $this->accrued_to_date, 2);
    }

    /** The position as one signed figure: positive is an asset, negative a liability. For a movement, never a report. */
    public function netPosition(): float
    {
        return round((float) $this->contract_asset - (float) $this->contract_liability, 2);
    }

    public function scopeForPeriod(Builder $query, string $periodStart): Builder
    {
        return $query->whereDate('period_start', CostPeriod::startFor($periodStart)->toDateString());
    }

    public function scopeLocked(Builder $query): Builder
    {
        return $query->whereNotNull('locked_at');
    }

    public function methodLabel(): string
    {
        return self::METHODS[$this->percent_complete_method] ?? $this->percent_complete_method;
    }

    public function displayName(): string
    {
        return ($this->job?->code ?? 'Job').' WIP — '.$this->period_start->format('F Y')
            .($this->isLocked() ? ' (locked)' : '');
    }
}
