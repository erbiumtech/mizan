<?php

namespace App\Modules\ConstructionCosting\Models;

use App\Models\TenantModel as Model;
use App\Modules\Construction\Models\CostCode;
use App\Traits\Auditable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One thing site asked for — `docs/construction-management-plan.md` §5.
 *
 * **The outstanding quantity is `requested − ordered`, computed**, and the ordered figure comes from the commitment
 * lines that name this one. Many order lines to one request line, because forty tonnes ordered as twenty now and
 * twenty in March is ordinary — a single "ordered" column on this row could only remember one of them, and would
 * report the request as satisfied the moment the first order went out.
 *
 * **Cancelled orders do not count as ordered.** An order raised and then cancelled leaves the request outstanding,
 * which is the state site is actually in; counting it would leave a need nobody is chasing and nothing showing it.
 */
class RequisitionLine extends Model
{
    use Auditable;

    protected $table = 'construction_requisition_lines';

    protected $fillable = [
        'requisition_id', 'cost_code_id', 'product_id', 'description',
        'quantity', 'unit_of_measure', 'estimated_rate', 'estimated_amount', 'notes',
    ];

    /** The estimate follows quantity × rate where both are given, exactly as an order line's amount does. */
    protected static function booted(): void
    {
        static::saving(function (self $line): void {
            if ($line->quantity !== null && $line->estimated_rate !== null) {
                $line->estimated_amount = round((float) $line->quantity * (float) $line->estimated_rate, 2);
            }
        });
    }

    public function requisition(): BelongsTo
    {
        return $this->belongsTo(Requisition::class, 'requisition_id');
    }

    public function costCode(): BelongsTo
    {
        return $this->belongsTo(CostCode::class, 'cost_code_id');
    }

    /** The order lines raised against this request — several, where it was ordered in parts. */
    public function commitmentLines(): HasMany
    {
        return $this->hasMany(CommitmentLine::class, 'requisition_line_id');
    }

    /**
     * How much has actually been ordered, ignoring cancelled orders.
     *
     * A cancelled order leaves the request outstanding, because that is the state site is in — and counting it
     * would leave a need nobody is chasing with nothing on any screen showing it.
     */
    public function orderedQuantity(): float
    {
        return round((float) $this->commitmentLines()
            ->whereHas('commitment', fn ($query) => $query->whereNot('status', Commitment::STATUS_CANCELLED))
            ->sum('quantity'), 4);
    }

    public function outstandingQuantity(): float
    {
        return round(max(0.0, (float) $this->quantity - $this->orderedQuantity()), 4);
    }

    public function isFullyOrdered(): bool
    {
        return $this->outstandingQuantity() <= 0.0;
    }
}
