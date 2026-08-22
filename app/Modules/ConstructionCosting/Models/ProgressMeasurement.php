<?php

namespace App\Modules\ConstructionCosting\Models;

use App\Models\TenantModel as Model;
use App\Modules\Construction\Models\CostCode;
use App\Modules\Construction\Models\Job;
use App\Modules\Construction\Models\WbsNode;
use App\Traits\Auditable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * How much of a control account is physically done — `docs/construction-management-plan.md` §14.
 *
 * **Earned value is measured physically and never derived from cost.** §14 is unusually blunt about why: if it
 * comes from cost then it equals actual cost, the cost performance index is exactly 1.00, and every job in the
 * system reads *precisely on budget* forever. "This is the commonest way an earned-value implementation becomes
 * decorative, and it is a silent failure of the purest kind — every number is present and none of them means
 * anything."
 *
 * So `earned_value` is the element's budget at completion times percent complete, **computed at measurement time
 * and frozen** — because the budget moves when a revision is approved and last month's earned value must not.
 *
 * Not to be confused with a claim line, which §14 also warns about: a claim line measures a *contract item* and
 * produces revenue; this measures a *control account* and produces earned value against budget. They are
 * reconciled through the WBS node they share, never by forcing one to be the other — and where the two disagree
 * on a remeasured contract, that is not a bug, it is the margin.
 */
class ProgressMeasurement extends Model
{
    use Auditable;

    public const METHOD_UNITS = 'units_completed';

    public const METHOD_MILESTONE = 'incremental_milestone';

    public const METHOD_WEIGHTED_STEPS = 'weighted_steps';

    public const METHOD_PERCENT = 'percent_complete';

    public const METHOD_LEVEL_OF_EFFORT = 'level_of_effort';

    protected $table = 'construction_progress_measurements';

    protected $fillable = [
        'job_id', 'wbs_node_id', 'cost_code_id', 'period_start', 'method',
        'quantity_completed', 'quantity_total', 'percent_complete',
        'earned_value', 'budget_at_completion', 'measured_against_version_id',
        'measured_by', 'measured_on', 'locked_at', 'notes',
    ];

    protected $casts = [
        'period_start' => 'date',
        'quantity_completed' => 'decimal:4',
        'quantity_total' => 'decimal:4',
        'percent_complete' => 'decimal:4',
        'earned_value' => 'decimal:2',
        'budget_at_completion' => 'decimal:2',
        'measured_on' => 'date',
        'locked_at' => 'datetime',
    ];

    protected $attributes = [
        'method' => self::METHOD_PERCENT,
        'percent_complete' => 0,
        'earned_value' => 0,
    ];

    public function job(): BelongsTo
    {
        return $this->belongsTo(Job::class, 'job_id');
    }

    public function costCode(): BelongsTo
    {
        return $this->belongsTo(CostCode::class, 'cost_code_id');
    }

    public function wbsNode(): BelongsTo
    {
        return $this->belongsTo(WbsNode::class, 'wbs_node_id');
    }

    public function measuredAgainst(): BelongsTo
    {
        return $this->belongsTo(JobBudget::class, 'measured_against_version_id');
    }

    /** A locked measurement fed a certificate and an earned-value report: it is evidence, not a draft. */
    public function isLocked(): bool
    {
        return $this->locked_at !== null;
    }

    /**
     * Percent complete derived from quantities where the method is units-based, else taken as given.
     *
     * Units-completed is the only method where the percentage is a *consequence* rather than a judgement, so it
     * is derived — a surveyor typing both a quantity and a disagreeing percentage would otherwise leave the
     * report unable to say which was meant.
     */
    public function derivedPercent(): float
    {
        if ($this->method === self::METHOD_UNITS
            && $this->quantity_total !== null
            && (float) $this->quantity_total != 0.0) {
            return round(((float) $this->quantity_completed / (float) $this->quantity_total) * 100, 4);
        }

        return (float) $this->percent_complete;
    }

    public function scopeInPeriod(Builder $query, string $periodStart): Builder
    {
        return $query->whereDate('period_start', $periodStart);
    }

    public function scopeUpTo(Builder $query, string $periodStart): Builder
    {
        return $query->whereDate('period_start', '<=', $periodStart);
    }

    public function scopeForJobTree(Builder $query, Job $root): Builder
    {
        return $query->whereIn('job_id', Job::query()->inSubtree($root)->select('id'));
    }
}
