<?php

namespace App\Modules\ConstructionCosting\Models;

use App\Models\TenantModel as Model;
use App\Modules\Construction\Models\Job;
use App\Modules\Construction\Models\Location;
use App\Modules\Construction\Models\WbsNode;
use App\Traits\Auditable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * What site asked for — `docs/construction-management-plan.md` §5.
 *
 * The demand document, and §5's reason for it is an absence: "nothing here has a demand document". Without one the
 * first record of a need is the order raised to satisfy it, so *what has been asked for and not yet ordered* has no
 * answer and the buyer's queue lives in somebody's inbox.
 *
 * **A requisition commits nothing.** Money is committed when an order is *issued*, so nothing here reaches the cost
 * report. What it has instead is an outstanding quantity per line — `requested − ordered` — derived from the
 * commitment lines that name it, the same way open commitment is derived from reliefs rather than stored.
 */
class Requisition extends Model
{
    use Auditable;

    public const STATUS_DRAFT = 'draft';

    public const STATUS_SUBMITTED = 'submitted';

    public const STATUS_APPROVED = 'approved';

    public const STATUS_REJECTED = 'rejected';

    public const STATUS_PARTIALLY_ORDERED = 'partially_ordered';

    public const STATUS_ORDERED = 'ordered';

    public const STATUS_CANCELLED = 'cancelled';

    /**
     * The statuses a buyer may raise an order from.
     *
     * Approved, or already part-ordered — the second because a request satisfied by two orders a month apart is
     * ordinary, and it must not have to be re-approved in between.
     *
     * @var array<int, string>
     */
    public const ORDERABLE_STATUSES = [self::STATUS_APPROVED, self::STATUS_PARTIALLY_ORDERED];

    protected $table = 'construction_requisitions';

    protected $fillable = [
        'number', 'job_id', 'wbs_node_id', 'location_id', 'status', 'required_by',
        'requested_by', 'requested_on', 'approved_at', 'approved_by', 'rejection_reason', 'notes',
    ];

    protected $casts = [
        'required_by' => 'date',
        'requested_on' => 'date',
        'approved_at' => 'datetime',
    ];

    protected $attributes = [
        'status' => self::STATUS_DRAFT,
    ];

    public function job(): BelongsTo
    {
        return $this->belongsTo(Job::class, 'job_id');
    }

    public function wbsNode(): BelongsTo
    {
        return $this->belongsTo(WbsNode::class, 'wbs_node_id');
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class, 'location_id');
    }

    public function lines(): HasMany
    {
        return $this->hasMany(RequisitionLine::class, 'requisition_id');
    }

    /** The buyer's queue: approved and not yet fully ordered, soonest needed first. */
    public function scopeToOrder(Builder $query): Builder
    {
        return $query->whereIn('status', self::ORDERABLE_STATUSES)
            ->orderByRaw('required_by is null')
            ->orderBy('required_by');
    }

    public function isEditable(): bool
    {
        return in_array($this->status, [self::STATUS_DRAFT, self::STATUS_REJECTED], true);
    }

    public function isOrderable(): bool
    {
        return in_array($this->status, self::ORDERABLE_STATUSES, true);
    }

    public function isClosed(): bool
    {
        return in_array($this->status, [self::STATUS_ORDERED, self::STATUS_CANCELLED], true);
    }

    /** What site thinks it will cost. An estimate, and the approval decision is the only thing that reads it. */
    public function estimatedTotal(): float
    {
        return round((float) $this->lines()->sum('estimated_amount'), 2);
    }

    /** Whether anything on it is still to be ordered — what decides `ordered` from `partially_ordered`. */
    public function hasOutstanding(): bool
    {
        return $this->lines->contains(fn (RequisitionLine $line): bool => $line->outstandingQuantity() > 0.0);
    }

    public function displayName(): string
    {
        return $this->number;
    }
}
