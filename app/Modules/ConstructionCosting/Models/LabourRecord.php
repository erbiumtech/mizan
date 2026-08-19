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
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * A day's work by one person on one cost code — `docs/construction-management-plan.md` §7.1.
 *
 * **Minutes, because a rate multiplied by a rounded decimal of hours accumulates visible error across a month.** The
 * same choice `timesheet_entries` and attendance already made, and the reason `hours()` below is a read rather than a
 * column.
 *
 * **The snapshot is the point of this table.** `cost_rate_per_hour`, `overtime_multiplier` and `burden_percent` are
 * written at approval and never recomputed, so a wage revision, a corrected rate row or a deleted rate row cannot
 * restate a month that has been reported on. §7.2 makes the dated rate table the first line of defence; this is the
 * second, and it is the one that holds when somebody edits the first.
 *
 * **Two cost entries, not one** (§7.3): the labour entry and a separate burden entry against the same cost code,
 * flagged `is_burden`. "Labour cost" therefore stays one number while burden stays separable, and reversing the record
 * reverses both halves because both are named here.
 */
class LabourRecord extends Model
{
    use Auditable;

    public const STATUS_DRAFT = 'draft';

    public const STATUS_APPROVED = 'approved';

    public const STATUS_REVERSED = 'reversed';

    protected $table = 'construction_labour_records';

    protected $fillable = [
        'worker_id', 'job_id', 'wbs_node_id', 'cost_code_id', 'trade_id', 'worked_on',
        'normal_minutes', 'overtime_minutes', 'status',
        'labour_rate_id', 'cost_rate_per_hour', 'overtime_multiplier', 'burden_percent',
        'labour_amount', 'burden_amount', 'cost_entry_id', 'burden_entry_id',
        'approved_at', 'approved_by', 'reversed_at', 'reversed_by', 'reversal_reason',
        'description', 'notes', 'source_type', 'source_id', 'created_by',
    ];

    protected $casts = [
        'worked_on' => 'date',
        'normal_minutes' => 'integer',
        'overtime_minutes' => 'integer',
        'approved_at' => 'datetime',
        'reversed_at' => 'datetime',
    ];

    protected $attributes = [
        'status' => self::STATUS_DRAFT,
        'normal_minutes' => 0,
        'overtime_minutes' => 0,
    ];

    protected static function booted(): void
    {
        static::creating(function (self $record): void {
            $record->created_by ??= auth()->id();
        });
    }

    /**
     * The morph alias, written through `ModuleMap::alias()`.
     *
     * `source_type` is a plain column and `enforceMorphMap()` does not cover those — §18.2 names this family. Without
     * the mutator the fully-qualified class name goes in, and the day that class moves the query that traces a labour
     * record back to the timesheet entry it was imported from stops matching with no error at all.
     */
    public function setSourceTypeAttribute(?string $value): void
    {
        $this->attributes['source_type'] = $value ? ModuleMap::alias($value) : null;
    }

    public function worker(): BelongsTo
    {
        return $this->belongsTo(Worker::class, 'worker_id');
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

    /** The trade worked *as*, which is not always the trade on the worker's own record. */
    public function trade(): BelongsTo
    {
        return $this->belongsTo(Trade::class, 'trade_id');
    }

    /** Which rate row the snapshot came from, so the figures on this row can be traced rather than trusted. */
    public function labourRate(): BelongsTo
    {
        return $this->belongsTo(LabourRate::class, 'labour_rate_id');
    }

    public function costEntry(): BelongsTo
    {
        return $this->belongsTo(CostEntry::class, 'cost_entry_id');
    }

    public function burdenEntry(): BelongsTo
    {
        return $this->belongsTo(CostEntry::class, 'burden_entry_id');
    }

    /** The timesheet entry or import this came from, where it did not come from a site sheet. */
    public function source(): MorphTo
    {
        return $this->morphTo(__FUNCTION__, 'source_type', 'source_id');
    }

    public function scopeDraft(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_DRAFT);
    }

    public function scopeApproved(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_APPROVED);
    }

    /**
     * Records for one date.
     *
     * `whereDate`, because `worked_on` is `date`-cast and therefore stored with a time — the trap
     * `CostPeriod::scopeStarting()` documents, and one this suite has now made three times.
     *
     * **Not named `scopeOn()`**, which would read better: `Model::on()` is Eloquent's own static
     * connection-switcher, so `LabourRecord::on('2026-08-01')` would silently ask for a database connection called
     * `2026-08-01` instead of filtering by date.
     */
    public function scopeWorkedOn(Builder $query, string $date): Builder
    {
        return $query->whereDate('worked_on', $date);
    }

    public function scopeForJobTree(Builder $query, Job $root): Builder
    {
        return $query->whereIn('job_id', Job::query()->inSubtree($root)->select('id'));
    }

    public function isDraft(): bool
    {
        return $this->status === self::STATUS_DRAFT;
    }

    public function isApproved(): bool
    {
        return $this->status === self::STATUS_APPROVED;
    }

    public function isReversed(): bool
    {
        return $this->status === self::STATUS_REVERSED;
    }

    public function totalMinutes(): int
    {
        return (int) $this->normal_minutes + (int) $this->overtime_minutes;
    }

    /**
     * Hours, for reading only.
     *
     * Never the basis of a calculation: minutes are, which is the whole reason the columns are minutes. This exists
     * because a site sheet is discussed in hours and a report that only prints 450 makes everybody do the division.
     */
    public function hours(): float
    {
        return round($this->totalMinutes() / 60, 2);
    }

    /** Labour plus burden — what this day actually cost the job. */
    public function totalCost(): float
    {
        return round((float) $this->labour_amount + (float) $this->burden_amount, 2);
    }

    public function displayName(): string
    {
        return ($this->worker?->name ?? 'Labour').' — '.$this->worked_on?->format('d M Y');
    }
}
