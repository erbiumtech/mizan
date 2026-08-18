<?php

namespace App\Modules\ConstructionContracts\Models;

use App\Models\TenantModel as Model;
use App\Support\ModuleMap;
use App\Traits\Auditable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * A variation under FIDIC, a change order under AIA — `docs/construction-management-plan.md` §9.
 *
 * **The two scopes on this model are the point of the class**, and §9 calls the rule behind them the sharpest in
 * the section:
 *
 *  - `agreed()` — approved **and not** provisionally priced. What the certificate's `variations_net_to_date` and
 *    G702 line 2 read. Money the parties have agreed.
 *  - `forecast()` — approved **or** approved in principle. What the cost report reads. Money the job is already
 *    spending.
 *
 * "One boolean, two audiences, and conflating them is how a job reports a margin it does not have for two
 * quarters running." Which is why **no bare `where('status', 'approved')` appears anywhere in this module** —
 * asserted against the source in `ConstructionVariationTest`, because the rule is one line of code away from
 * being broken by somebody being helpful.
 *
 * `approved_in_principle` exists because it is the state construction lives in: instructed, work proceeding,
 * price disputed for four months.
 */
class Variation extends Model
{
    use Auditable;

    public const STATUS_DRAFT = 'draft';

    public const STATUS_SUBMITTED = 'submitted';

    public const STATUS_PRICED = 'priced';

    public const STATUS_APPROVED_IN_PRINCIPLE = 'approved_in_principle';

    public const STATUS_APPROVED = 'approved';

    public const STATUS_REJECTED = 'rejected';

    public const STATUS_INCORPORATED = 'incorporated';

    public const ORIGIN_PROVISIONAL_SUM = 'provisional_sum_expenditure';

    public const ORIGIN_DAYWORKS = 'dayworks';

    public const METHOD_CONTRACT_RATES = 'rates_in_contract';

    public const METHOD_PRO_RATA = 'pro_rata_rates';

    public const METHOD_NEW_RATE = 'new_rate_agreed';

    public const METHOD_DAYWORKS = 'dayworks';

    public const METHOD_LUMP_SUM = 'lump_sum';

    public const METHOD_COST_PLUS = 'cost_plus_percentage';

    /**
     * The statuses that mean the variation is *live* — approved either way.
     *
     * Named once because three places ask, and a fourth place spelling it out itself is the beginning of the
     * drift this class exists to prevent.
     *
     * @var array<int, string>
     */
    public const LIVE_STATUSES = [self::STATUS_APPROVED, self::STATUS_APPROVED_IN_PRINCIPLE];

    protected $table = 'construction_variations';

    protected $fillable = [
        'contract_id', 'variation_number', 'origin', 'source_type', 'source_id',
        'title', 'description', 'justification', 'valuation_method', 'status',
        'quoted_amount', 'assessed_amount', 'approved_amount',
        'is_price_provisional', 'provisional_confidence',
        'time_impact_days', 'time_granted_days', 'eot_status',
        'instructed_on', 'submitted_on', 'priced_on', 'approved_on', 'approved_by',
        'incorporated_at', 'rejection_reason',
    ];

    protected $casts = [
        'is_price_provisional' => 'boolean',
        'instructed_on' => 'date',
        'submitted_on' => 'date',
        'priced_on' => 'date',
        'approved_on' => 'date',
        'incorporated_at' => 'datetime',
        'time_impact_days' => 'integer',
        'time_granted_days' => 'integer',
    ];

    protected $attributes = [
        'origin' => 'engineer_instruction',
        'valuation_method' => self::METHOD_CONTRACT_RATES,
        'status' => self::STATUS_DRAFT,
        'is_price_provisional' => false,
        'eot_status' => 'none',
    ];

    /**
     * The morph alias, written through `ModuleMap::alias()`.
     *
     * `source_type` is a **plain column** and `enforceMorphMap()` does not cover those — §18.2 names this table
     * among the five exposed. Without the mutator the fully-qualified class name goes into the column, and the
     * day that class moves the query stops matching with no error at all.
     */
    public function setSourceTypeAttribute(?string $value): void
    {
        $this->attributes['source_type'] = $value ? ModuleMap::alias($value) : null;
    }

    public function contract(): BelongsTo
    {
        return $this->belongsTo(Contract::class, 'contract_id');
    }

    /** The RFI, NCR or instruction that caused it. */
    public function source(): MorphTo
    {
        return $this->morphTo(__FUNCTION__, 'source_type', 'source_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(VariationItem::class, 'variation_id');
    }

    /**
     * Money the parties have agreed — what a certificate may include.
     *
     * Approved **and not provisional**. A provisionally priced variation in this scope is money certified that
     * nobody agreed, which is the failure §9 is written around.
     */
    public function scopeAgreed(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_APPROVED)->where('is_price_provisional', false);
    }

    /**
     * Money the job is already spending — what the cost report and the forecast read.
     *
     * Approved **or** approved in principle, provisional or not. Leaving a provisional variation out here
     * forecasts a cost the job is already incurring as zero.
     */
    public function scopeForecast(Builder $query): Builder
    {
        return $query->whereIn('status', self::LIVE_STATUSES);
    }

    /** Awaiting a decision — the register's default view, and the list somebody chases. */
    public function scopeOutstanding(Builder $query): Builder
    {
        return $query->whereIn('status', [self::STATUS_SUBMITTED, self::STATUS_PRICED]);
    }

    public function isAgreed(): bool
    {
        return $this->status === self::STATUS_APPROVED && ! $this->is_price_provisional;
    }

    public function isLive(): bool
    {
        return in_array($this->status, self::LIVE_STATUSES, true);
    }

    public function isIncorporated(): bool
    {
        return $this->status === self::STATUS_INCORPORATED;
    }

    /**
     * The figure that counts, in the order the parties settle it.
     *
     * Approved, else assessed, else quoted. A variation approved in principle at the certifier's assessment is
     * the ordinary case, and reading `approved_amount` alone would forecast it as nothing.
     */
    public function effectiveAmount(): float
    {
        return (float) ($this->approved_amount ?? $this->assessed_amount ?? $this->quoted_amount ?? 0);
    }

    /** The sum of the priced items, which is what pricing writes into `assessed_amount`. */
    public function itemsTotal(): float
    {
        return (float) $this->items()->sum('amount');
    }

    public function displayName(): string
    {
        return "{$this->variation_number} — {$this->title}";
    }
}
