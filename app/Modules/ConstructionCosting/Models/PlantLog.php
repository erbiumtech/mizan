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
 * What a machine did on a day — `docs/construction-management-plan.md` §7.3.
 *
 * **Three unit columns, because plant is charged three ways.** Working, idle and standby are separate rates in every
 * hire agreement in the industry, and one blended "hours" column would make whoever fills the sheet do the blending in
 * their head — losing the breakdown that answers "what did we pay for a crane to stand still", which is the question
 * plant control exists for.
 *
 * **An approved log books cost only for an owned machine.** A hired machine's cost is its supplier invoice, which §4.1
 * makes the general ledger's record and which reaches the job through §5's allocation; a cost entry here as well would
 * charge the job twice for the same excavator. For hired plant the log is the *check* — `charge_amount` is what the
 * agreement says the days were worth, and `PlantHireMatch` compares it with what has actually been invoiced.
 *
 * **The meter is evidence, not the basis of the charge.** Engine hours legitimately differ from charged hours, so a
 * mismatch is never refused; a meter that went backwards is, because a meter cannot.
 */
class PlantLog extends Model
{
    use Auditable;

    public const STATUS_DRAFT = 'draft';

    public const STATUS_APPROVED = 'approved';

    public const STATUS_REVERSED = 'reversed';

    protected $table = 'construction_plant_logs';

    protected $fillable = [
        'plant_item_id', 'job_id', 'wbs_node_id', 'cost_code_id', 'logged_on',
        'working_units', 'idle_units', 'standby_units', 'meter_start', 'meter_end',
        'fuel_quantity', 'operator_worker_id', 'downtime_reason', 'status',
        'working_rate', 'idle_rate', 'standby_rate', 'charge_amount', 'cost_entry_id',
        'approved_at', 'approved_by', 'reversed_at', 'reversed_by', 'reversal_reason',
        'description', 'notes', 'created_by',
    ];

    protected $casts = [
        'logged_on' => 'date',
        'approved_at' => 'datetime',
        'reversed_at' => 'datetime',
    ];

    protected $attributes = [
        'status' => self::STATUS_DRAFT,
        'working_units' => 0,
        'idle_units' => 0,
        'standby_units' => 0,
    ];

    protected static function booted(): void
    {
        static::creating(function (self $log): void {
            $log->created_by ??= auth()->id();
        });
    }

    public function plantItem(): BelongsTo
    {
        return $this->belongsTo(PlantItem::class, 'plant_item_id');
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

    /** This company's operator, where it supplies one. Null on hired-with-operator plant. */
    public function operator(): BelongsTo
    {
        return $this->belongsTo(Worker::class, 'operator_worker_id');
    }

    public function costEntry(): BelongsTo
    {
        return $this->belongsTo(CostEntry::class, 'cost_entry_id');
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
     * Logs for one date.
     *
     * `whereDate`, because `logged_on` is `date`-cast and therefore stored with a time — the trap
     * `CostPeriod::scopeStarting()` documents and this suite has now made three times. Named `loggedOn` rather than
     * `on` for the reason `LabourRecord::scopeWorkedOn()` records: `Model::on()` is Eloquent's connection-switcher.
     */
    public function scopeLoggedOn(Builder $query, string $date): Builder
    {
        return $query->whereDate('logged_on', $date);
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

    /** Every unit the machine was on site for, however it was charged. */
    public function totalUnits(): float
    {
        return round(
            (float) $this->working_units + (float) $this->idle_units + (float) $this->standby_units,
            2,
        );
    }

    /**
     * The meter movement, or null where either reading is missing.
     *
     * A read rather than a column: two readings and their difference is one fact stored twice, and the stored copy is
     * the one that goes stale when somebody corrects a reading.
     */
    public function meterMovement(): ?float
    {
        if ($this->meter_start === null || $this->meter_end === null) {
            return null;
        }

        return round((float) $this->meter_end - (float) $this->meter_start, 2);
    }

    /**
     * The charge this log would produce from a set of rates, with a null rate meaning **not charged**.
     *
     * Stated rather than assumed: idle falling back to the working rate would silently inflate every job that ever had
     * a machine standing, and that is the shape of error §18.1 is about. The screen prints "not charged" so the
     * company's choice is visible on the row.
     */
    public function chargeFrom(?float $working, ?float $idle, ?float $standby): float
    {
        return round(
            ((float) $this->working_units * (float) ($working ?? 0))
            + ((float) $this->idle_units * (float) ($idle ?? 0))
            + ((float) $this->standby_units * (float) ($standby ?? 0)),
            2,
        );
    }

    public function displayName(): string
    {
        return ($this->plantItem?->code ?? 'Plant').' — '.$this->logged_on?->format('d M Y');
    }
}
