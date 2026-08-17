<?php

namespace App\Modules\ConstructionCosting\Models;

use App\Models\TenantModel as Model;
use App\Modules\Construction\Models\CostCode;
use App\Modules\Construction\Models\Job;
use App\Modules\Construction\Models\WbsNode;
use App\Support\ModuleMap;
use App\Traits\Auditable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An immutable statement of cost — `docs/construction-management-plan.md` §3.2.
 *
 * *This much value, of this cost type, landed on this job, WBS node and cost code, incurred on this date, in
 * this cost period, caused by this document, and here is its relationship to the general ledger.*
 *
 * **The invariant this whole design rests on**: the sum of `amount` over a job's entries, filtered by nothing
 * but the period, **is** the job's cost. No `is_active`, no soft delete, no current-version flag — because "a
 * flag that must be filtered is a flag somebody forgets, and the query that forgets it is a cost report that is
 * wrong and looks fine". A correction is a further row, never an edit to this one once it has hardened.
 *
 * Three details are load-bearing rather than incidental, and each is enforced here:
 *
 *  - **One signed amount.** A debit/credit pair would import an accounting form that buys nothing and invites
 *    "which column does a credit note go in", which two developers answer differently.
 *  - **`cost_type` is snapshotted** from the code at the moment of recording. Re-typing a cost code in June must
 *    not restate March's labour/material split — the same reasoning that stores `invoice_lines.tax_amount`.
 *  - **`gl_treatment` is a column**, never inferred from `journal_entry_id IS NULL`. `pending` and `memo` look
 *    identical as a null, and a null meaning "we do not know which" is how a sub-ledger drifts for a year.
 */
class CostEntry extends Model
{
    use Auditable;

    public const KIND_ACTUAL = 'actual';

    public const KIND_ACCRUAL = 'accrual';

    public const KIND_ALLOCATION = 'allocation';

    public const KIND_RECLASS = 'reclass';

    public const KIND_REVERSAL = 'reversal';

    /** The GL posting is the source of truth and this mirrors it. */
    public const GL_MIRRORED = 'mirrored';

    /** This is the source and it has reached the GL. */
    public const GL_POSTED = 'posted';

    /** It should reach the GL and has not yet — the one §4's report chases. */
    public const GL_PENDING = 'pending';

    /** It deliberately never will: burden at a rate, internal plant, a notional comparison. */
    public const GL_MEMO = 'memo';

    protected $table = 'construction_cost_entries';

    protected $fillable = [
        'job_id', 'wbs_node_id', 'cost_code_id', 'cost_type', 'kind', 'amount',
        'quantity', 'unit_of_measure', 'unit_rate', 'incurred_on', 'posting_period', 'is_late_for_period',
        'fiscal_year_id', 'gl_treatment', 'journal_entry_id', 'gl_account_id', 'posted_to_gl_at',
        'batch_id', 'contact_id', 'employee_id', 'worker_id', 'is_burden',
        'reverses_id', 'reversed_by_id', 'description', 'reference',
        'source_type', 'source_id', 'created_by',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'quantity' => 'decimal:4',
        'unit_rate' => 'decimal:4',
        'incurred_on' => 'date',
        'posting_period' => 'date',
        'is_late_for_period' => 'boolean',
        'is_burden' => 'boolean',
        'posted_to_gl_at' => 'datetime',
    ];

    protected $attributes = [
        'kind' => self::KIND_ACTUAL,
        'gl_treatment' => self::GL_PENDING,
        'is_late_for_period' => false,
        'is_burden' => false,
    ];

    /**
     * The morph alias, written through `ModuleMap::alias()`.
     *
     * `source_type` is a **plain column**, and `enforceMorphMap()` does not cover those — §18.2 names this
     * table among the five exposed. Without the mutator the fully-qualified class name goes into the column, and
     * the day that class moves the query stops matching with no error at all.
     */
    public function setSourceTypeAttribute(?string $value): void
    {
        $this->attributes['source_type'] = $value ? ModuleMap::alias($value) : null;
    }

    public function job(): BelongsTo
    {
        return $this->belongsTo(Job::class, 'job_id');
    }

    public function wbsNode(): BelongsTo
    {
        return $this->belongsTo(WbsNode::class, 'wbs_node_id');
    }

    public function costCode(): BelongsTo
    {
        return $this->belongsTo(CostCode::class, 'cost_code_id');
    }

    public function batch(): BelongsTo
    {
        return $this->belongsTo(CostBatch::class, 'batch_id');
    }

    public function reverses(): BelongsTo
    {
        return $this->belongsTo(self::class, 'reverses_id');
    }

    public function reversedBy(): BelongsTo
    {
        return $this->belongsTo(self::class, 'reversed_by_id');
    }

    /**
     * The period this entry was posted into.
     *
     * A method rather than a relation, deliberately: both sides are `date`-cast and stored with a time
     * component, so a `belongsTo` keyed on `posting_period = period_start` matches nothing on SQLite and
     * something on MySQL. `CostPeriod::scopeStarting()` is the one place that comparison is written.
     */
    public function period(): ?CostPeriod
    {
        return CostPeriod::query()->starting($this->posting_period)->first();
    }

    /**
     * Whether this entry may still be edited in place — §3.3.
     *
     * Hardens when either its period closes or it reaches the GL. Before that, editing is allowed and audited,
     * because "forcing a reversal pair for a typo made ten seconds ago produces three rows where one is true,
     * and a cost report full of ±5,000 pairs is unreadable". The line sits exactly where `journal_entries`
     * already draws it.
     */
    public function isEditable(): bool
    {
        if ($this->posted_to_gl_at !== null) {
            return false;
        }

        // Through `starting()`, never a bare equality: see that scope. A `where('period_start', …)` here found
        // no closed period at all, so an entry in a signed-off month reported itself editable.
        return ! (CostPeriod::query()
            ->starting($this->posting_period)
            ->whereIn('status', [CostPeriod::STATUS_CLOSED, CostPeriod::STATUS_RECONCILED])
            ->exists());
    }

    public function isReversal(): bool
    {
        return $this->kind === self::KIND_REVERSAL;
    }

    public function isReversed(): bool
    {
        return $this->reversed_by_id !== null;
    }

    /** A cost that will never reach the general ledger, and says so rather than looking unposted. */
    public function isMemoOnly(): bool
    {
        return $this->gl_treatment === self::GL_MEMO;
    }

    public function scopeInPeriod(Builder $query, string $periodStart): Builder
    {
        return $query->whereDate('posting_period', $periodStart);
    }

    /**
     * Every entry on a job and its sub-jobs.
     *
     * Rolls up through the job tree's materialised path, so a development's report includes its towers — §1.2's
     * whole reason for the hierarchy, and the figure the board asks for.
     */
    public function scopeForJobTree(Builder $query, Job $root): Builder
    {
        return $query->whereIn(
            'job_id',
            Job::query()->inSubtree($root)->select('id'),
        );
    }

    /** Entries that should have reached the GL and have not — what §4's reconciliation chases. */
    public function scopeAwaitingGl(Builder $query): Builder
    {
        return $query->where('gl_treatment', self::GL_PENDING);
    }

    /** Deliberately never posting, so a reconciliation can exclude them without guessing. */
    public function scopeMemoOnly(Builder $query): Builder
    {
        return $query->where('gl_treatment', self::GL_MEMO);
    }
}
