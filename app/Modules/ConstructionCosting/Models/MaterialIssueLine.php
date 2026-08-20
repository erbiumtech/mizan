<?php

namespace App\Modules\ConstructionCosting\Models;

use App\Models\TenantModel as Model;
use App\Modules\Construction\Models\CostCode;
use App\Modules\Construction\Models\Job;
use App\Modules\Construction\Models\WbsNode;
use App\Modules\Inventory\Models\Product;
use App\Traits\Auditable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One product off one docket — `docs/construction-management-plan.md` §6.
 *
 * **The job is on the line, not the header**, for the reason every other line in this suite gives: one docket out of a
 * development's store serves several of its jobs, and one docket per job is a docket nobody writes.
 *
 * **`quantity` is what left the store; `wastage_quantity` is how much of it was wasted.** The usable part is the
 * difference, and both halves leave stock — the waste physically went out of the store too. What separates them is the
 * movement type: `issue` for the usable part, `waste` for the rest, so "what did we waste" is a query on a type rather
 * than a column somebody has to remember to subtract.
 *
 * `unit_cost` and `amount` are **frozen at posting**. They cannot be recomputed afterwards even in principle: the lots
 * they came from have been consumed, so there is nothing left to revalue against.
 */
class MaterialIssueLine extends Model
{
    use Auditable;

    protected $table = 'construction_material_issue_lines';

    protected $fillable = [
        'material_issue_id', 'job_id', 'wbs_node_id', 'cost_code_id', 'product_id',
        'quantity', 'wastage_quantity', 'wastage_reason', 'unit_cost', 'amount',
        'returned_quantity', 'description',
    ];

    protected $attributes = [
        'wastage_quantity' => 0,
        'returned_quantity' => 0,
    ];

    public function materialIssue(): BelongsTo
    {
        return $this->belongsTo(MaterialIssue::class, 'material_issue_id');
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

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'product_id');
    }

    /** The part that was built in rather than wasted. */
    public function usableQuantity(): float
    {
        return round((float) $this->quantity - (float) $this->wastage_quantity, 4);
    }

    /** What is still out on this line — issued, not wasted, not brought back. */
    public function outstandingQuantity(): float
    {
        return round($this->usableQuantity() - (float) $this->returned_quantity, 4);
    }

    public function hasWastage(): bool
    {
        return (float) $this->wastage_quantity > 0.0;
    }

    /** The value of the wasted part, at the same blended rate the whole line was valued at. */
    public function wastageValue(): float
    {
        return round((float) $this->wastage_quantity * (float) $this->unit_cost, 2);
    }

    public function displayName(): string
    {
        return ($this->product?->sku ?? 'Material')." ×{$this->quantity}";
    }
}
