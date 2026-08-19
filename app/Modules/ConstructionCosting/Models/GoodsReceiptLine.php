<?php

namespace App\Modules\ConstructionCosting\Models;

use App\Models\TenantModel as Model;
use App\Modules\Construction\Models\CostCode;
use App\Modules\Construction\Models\Job;
use App\Modules\Construction\Models\WbsNode;
use App\Traits\Auditable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One delivered line — `docs/construction-management-plan.md` §5 and §6.
 *
 * **`destination` is §6's fork.** `direct` is the default and touches no stock at all, which is what keeps this usable
 * by a contractor who buys everything straight to site and tracks no stock — "most of them, most of the time".
 * `store` needs a stock location to exist, and until Phase 8 delivers `stock_locations` the service refuses it: a
 * receipt accepted into a store that cannot record the movement would be costed as though it had been stocked, and
 * materials-on-site would be wrong with nothing saying so.
 *
 * **The job and the cost code are on the line, not read through the order**, because a delivery with no order — which
 * happens — still has to say where the cost lands.
 */
class GoodsReceiptLine extends Model
{
    use Auditable;

    public const DESTINATION_DIRECT = 'direct';

    public const DESTINATION_STORE = 'store';

    protected $table = 'construction_goods_receipt_lines';

    protected $fillable = [
        'goods_receipt_id', 'commitment_line_id', 'job_id', 'wbs_node_id', 'cost_code_id', 'product_id',
        'description', 'quantity', 'unit_of_measure', 'unit_rate', 'amount', 'destination', 'cost_entry_id',
    ];

    protected $attributes = [
        'amount' => 0,
        'destination' => self::DESTINATION_DIRECT,
    ];

    /** The amount follows quantity × rate, as it does on an order line and a claim line. */
    protected static function booted(): void
    {
        static::saving(function (self $line): void {
            if ($line->quantity !== null && $line->unit_rate !== null) {
                $line->amount = round((float) $line->quantity * (float) $line->unit_rate, 2);
            }
        });
    }

    public function receipt(): BelongsTo
    {
        return $this->belongsTo(GoodsReceipt::class, 'goods_receipt_id');
    }

    public function commitmentLine(): BelongsTo
    {
        return $this->belongsTo(CommitmentLine::class, 'commitment_line_id');
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

    /** The accrual this line raised, which is what makes posting idempotent. */
    public function costEntry(): BelongsTo
    {
        return $this->belongsTo(CostEntry::class, 'cost_entry_id');
    }

    public function isPosted(): bool
    {
        return $this->cost_entry_id !== null;
    }

    public function goesToStore(): bool
    {
        return $this->destination === self::DESTINATION_STORE;
    }
}
