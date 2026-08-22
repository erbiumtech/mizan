<?php

namespace App\Modules\ConstructionContracts\Models;

use App\Models\TenantModel as Model;
use App\Traits\Auditable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One claimed line — `docs/construction-management-plan.md` §10.2.
 *
 * **Value is authoritative; percent and quantity are inputs**, and `measurement_input` records which one the
 * person actually typed. All three resolve to a value and the value is what the certificate sums.
 *
 * Why not percent alone: a variation that grows a remeasured line's quantity leaves the stored percent measured
 * against a stale denominator, so the line reads 87% while the money is fully certified. Why not quantity alone:
 * it has nothing to say about a lump-sum line. Recording which input produced the value is what lets a later
 * reader reproduce the intent rather than guess it.
 */
class ProgressClaimLine extends Model
{
    use Auditable;

    public const INPUT_PERCENT = 'percent';

    public const INPUT_QUANTITY = 'quantity';

    public const INPUT_VALUE = 'value';

    /** Nothing-or-everything: an activity-schedule line pays only when complete, which NEC4 Option A requires. */
    public const INPUT_MILESTONE = 'milestone';

    protected $table = 'construction_progress_claim_lines';

    protected $fillable = [
        'progress_claim_id', 'contract_item_id', 'measurement_input',
        'cumulative_percent', 'cumulative_quantity', 'cumulative_work_value', 'cumulative_materials_value',
        'notes',
    ];

    protected $attributes = [
        'measurement_input' => self::INPUT_PERCENT,
        'cumulative_work_value' => 0,
        'cumulative_materials_value' => 0,
    ];

    public function claim(): BelongsTo
    {
        return $this->belongsTo(ProgressClaim::class, 'progress_claim_id');
    }

    public function contractItem(): BelongsTo
    {
        return $this->belongsTo(ContractItem::class, 'contract_item_id');
    }

    /**
     * Resolve whichever input was given into a work value.
     *
     * The one place the three inputs meet, so the resolution cannot differ between the claim screen, an import
     * and the certificate that reads it.
     */
    public function resolveWorkValue(): float
    {
        $scheduled = (float) ($this->contractItem?->scheduled_value ?? 0);

        return round(match ($this->measurement_input) {
            self::INPUT_PERCENT => $scheduled * ((float) ($this->cumulative_percent ?? 0)) / 100,
            self::INPUT_QUANTITY => (float) ($this->cumulative_quantity ?? 0) * (float) ($this->contractItem?->rate ?? 0),
            // Nothing or everything, and 100% is the only value that means everything.
            self::INPUT_MILESTONE => ((float) ($this->cumulative_percent ?? 0)) >= 100 ? $scheduled : 0.0,
            default => (float) $this->cumulative_work_value,
        }, 2);
    }
}
