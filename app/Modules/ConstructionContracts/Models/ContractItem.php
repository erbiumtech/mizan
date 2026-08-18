<?php

namespace App\Modules\ConstructionContracts\Models;

use App\Models\TenantModel as Model;
use App\Modules\Construction\Models\CostCode;
use App\Modules\Construction\Models\WbsNode;
use App\Traits\Auditable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One line of the item schedule — a BoQ item under FIDIC, a Schedule of Values line under AIA.
 *
 * `docs/construction-management-plan.md` §8.2. **The same row for both**: AIA fills `item_no`, `description`
 * and `scheduled_value` and leaves unit, quantity and rate null; FIDIC fills unit, quantity and rate and
 * derives the scheduled value. "The difference is which columns are null, not which table you are in" — which
 * is the proof the dual-standard model holds rather than a claim about it.
 *
 * **`scheduled_value` is recomputed while the contract is draft and frozen at execution.** It is the figure
 * the client signed. If it moved when somebody edited a quantity on a remeasured line, G703's "work completed
 * from previous applications" could exceed its "scheduled value" and the form would be arithmetically
 * impossible — a printed page that cannot be right, which is worse than a number that is merely wrong.
 */
class ContractItem extends Model
{
    use Auditable;

    public const TYPE_MEASURED = 'measured';

    public const TYPE_LUMP_SUM = 'lump_sum';

    public const TYPE_PROVISIONAL_SUM = 'provisional_sum';

    public const TYPE_PRIME_COST_SUM = 'prime_cost_sum';

    public const TYPE_DAYWORKS = 'dayworks';

    public const TYPE_CONTINGENCY = 'contingency';

    public const TYPE_MILESTONE = 'milestone';

    public const TYPE_ADVANCE = 'advance';

    public const TYPE_ADJUSTMENT = 'adjustment';

    protected $table = 'construction_contract_items';

    protected $fillable = [
        'contract_id', 'parent_id', 'item_no', 'sort', 'wbs_node_id', 'cost_code_id', 'classification_code',
        'description', 'item_type', 'unit', 'quantity', 'rate', 'scheduled_value',
        'retention_applies', 'materials_allowed', 'source_variation_id', 'supersedes_item_id', 'is_active',
    ];

    protected $casts = [
        'retention_applies' => 'boolean',
        'materials_allowed' => 'boolean',
        'is_active' => 'boolean',
        'sort' => 'integer',
    ];

    protected $attributes = [
        'item_type' => self::TYPE_MEASURED,
        'retention_applies' => true,
        'materials_allowed' => false,
        'is_active' => true,
        'scheduled_value' => 0,
        'sort' => 0,
    ];

    /**
     * The scheduled value follows quantity × rate **only while the contract is draft**.
     *
     * Written here rather than in a form because the schedule also arrives by import and by §9's variation
     * approval, and a rule enforced on one screen is a rule the other two walk past. Once the contract is
     * executed the stored figure stands, whatever anybody edits — see the class docblock for what breaks
     * otherwise.
     */
    protected static function booted(): void
    {
        static::saving(function (self $item): void {
            if ($item->quantity === null || $item->rate === null) {
                return;
            }

            if (! ($item->contract?->isDraft() ?? true)) {
                return;
            }

            $item->scheduled_value = round((float) $item->quantity * (float) $item->rate, 2);
        });
    }

    public function contract(): BelongsTo
    {
        return $this->belongsTo(Contract::class, 'contract_id');
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id');
    }

    public function wbsNode(): BelongsTo
    {
        return $this->belongsTo(WbsNode::class, 'wbs_node_id');
    }

    public function costCode(): BelongsTo
    {
        return $this->belongsTo(CostCode::class, 'cost_code_id');
    }

    public function supersedes(): BelongsTo
    {
        return $this->belongsTo(self::class, 'supersedes_item_id');
    }

    /** Lines somebody may claim against: active, and not a heading with lines under it. */
    public function scopeClaimable(Builder $query): Builder
    {
        return $query->where('is_active', true)
            ->whereDoesntHave('children');
    }

    /** A line written by an approved variation, which prints appended to the schedule rather than folded in. */
    public function isVariationLine(): bool
    {
        return $this->source_variation_id !== null;
    }

    /**
     * An omission carries a negative scheduled value rather than reducing the line it omits (§9).
     *
     * Reducing the original destroys the audit trail *and* breaks certificates already issued, because column
     * D would then exceed column C. "A negative line is ugly on the page and correct in the ledger."
     */
    public function isOmission(): bool
    {
        return (float) $this->scheduled_value < 0;
    }

    public function displayName(): string
    {
        return "{$this->item_no} — ".str($this->description)->limit(60);
    }
}
