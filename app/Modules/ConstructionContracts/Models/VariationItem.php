<?php

namespace App\Modules\ConstructionContracts\Models;

use App\Models\TenantModel as Model;
use App\Modules\Construction\Models\CostCode;
use App\Modules\Construction\Models\WbsNode;
use App\Traits\Auditable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One line of a variation — `docs/construction-management-plan.md` §9.
 *
 * Four actions, and the difference between them is what keeps a certificate arithmetically possible:
 *
 *  - **`add`** writes a new contract item carrying `source_variation_id`, which is how a change order prints
 *    appended to the continuation sheet rather than folded into the original bill.
 *  - **`omit`** writes a **negative** item rather than reducing the line it omits. Reducing the original
 *    destroys the audit trail and breaks certificates already issued, because "completed to date" would then
 *    exceed the "scheduled value" it is measured against.
 *  - **`remeasure`** and **`rate_change`** are the one permitted in-place edit, and they are safe precisely
 *    because on a remeasured contract the quantity was always provisional — it is a bill of *approximate*
 *    quantities — and every certificate line carries its own frozen cumulative value regardless. The old
 *    figures are kept here, so "what was it before VO-12" needs no diff of the schedule.
 */
class VariationItem extends Model
{
    use Auditable;

    public const ACTION_ADD = 'add';

    public const ACTION_OMIT = 'omit';

    public const ACTION_REMEASURE = 'remeasure';

    public const ACTION_RATE_CHANGE = 'rate_change';

    protected $table = 'construction_variation_items';

    protected $fillable = [
        'variation_id', 'action', 'contract_item_id', 'item_no', 'description',
        'cost_code_id', 'wbs_node_id', 'unit', 'quantity', 'rate', 'amount',
        'previous_quantity', 'previous_rate', 'resulting_item_id',
    ];

    protected $attributes = [
        'action' => self::ACTION_ADD,
        'amount' => 0,
    ];

    /**
     * The amount follows quantity × rate where both are given.
     *
     * An omission is expected to arrive negative and is left alone: a caller that means "take 750,000 off"
     * writes −750,000, and flipping the sign here would silently double-negate the ones that already did.
     */
    protected static function booted(): void
    {
        static::saving(function (self $item): void {
            if ($item->quantity !== null && $item->rate !== null) {
                $item->amount = round((float) $item->quantity * (float) $item->rate, 2);
            }
        });
    }

    public function variation(): BelongsTo
    {
        return $this->belongsTo(Variation::class, 'variation_id');
    }

    /** The line this acts on — null for an `add`, which has no existing line. */
    public function contractItem(): BelongsTo
    {
        return $this->belongsTo(ContractItem::class, 'contract_item_id');
    }

    /** The line incorporation wrote, which makes incorporation traceable in both directions. */
    public function resultingItem(): BelongsTo
    {
        return $this->belongsTo(ContractItem::class, 'resulting_item_id');
    }

    public function costCode(): BelongsTo
    {
        return $this->belongsTo(CostCode::class, 'cost_code_id');
    }

    public function wbsNode(): BelongsTo
    {
        return $this->belongsTo(WbsNode::class, 'wbs_node_id');
    }

    /** Whether this line edits an existing item rather than writing a new one. */
    public function editsInPlace(): bool
    {
        return in_array($this->action, [self::ACTION_REMEASURE, self::ACTION_RATE_CHANGE], true);
    }
}
