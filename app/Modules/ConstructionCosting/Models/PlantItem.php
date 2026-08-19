<?php

namespace App\Modules\ConstructionCosting\Models;

use App\Models\TenantModel as Model;
use App\Modules\Accounting\Models\FixedAsset;
use App\Modules\Construction\Models\CostCode;
use App\Modules\Invoicing\Models\Contact;
use App\Traits\Auditable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One machine — `docs/construction-management-plan.md` §7.3.
 *
 * **Ownership decides whether this machine's logs book cost**, and that is the only structural difference between the
 * two halves of plant. An owned machine has no invoice, so its log *is* the cost: internal hire, charged to the job and
 * credited to Plant Internal Hire Recovery by §11. A hired machine has a supplier invoice, which §4.1 makes the
 * general ledger's record of the cost and which reaches the job through §5's allocation — so its log writes nothing
 * and becomes the check against that invoice instead.
 *
 * **The rates are here rather than in a dated table, and that is a deliberate asymmetry with §7.2's labour rates.**
 * A labour rate changes with wage agreements, varies by job, trade and person, and needed five tiers and an effective
 * date. An internal hire rate is one number on one machine, set when it joins the fleet and revised rarely, by the same
 * person who maintains the fleet. So the protection here is the *snapshot* on the log alone: a rate revised today does
 * not restate what has already been approved, but it does re-price outstanding drafts, and there is no way to schedule
 * a change or to charge one job differently. If a customer needs either, `construction_plant_rates` in the shape of
 * `construction_labour_rates` is the extension — this is a smaller design on purpose, not an unfinished one.
 */
class PlantItem extends Model
{
    use Auditable;

    /** No invoice exists, so the log is the cost: internal hire, credited to recovery. */
    public const OWNERSHIP_OWNED = 'owned';

    /** A supplier invoice is the ledger's record; the log is the check against it. */
    public const OWNERSHIP_HIRED = 'hired';

    /** Hired, and the operator is the supplier's — so no labour record accompanies the machine. */
    public const OWNERSHIP_HIRED_WITH_OPERATOR = 'hired_with_operator';

    /** @var array<string, string> */
    public const OWNERSHIPS = [
        self::OWNERSHIP_OWNED => 'Owned — charged to jobs as internal hire',
        self::OWNERSHIP_HIRED => 'Hired — the supplier invoice is the cost',
        self::OWNERSHIP_HIRED_WITH_OPERATOR => 'Hired with operator',
    ];

    public const METER_HOURS = 'hours';

    public const METER_KILOMETRES = 'kilometres';

    protected $table = 'construction_plant_items';

    protected $fillable = [
        'code', 'name', 'category', 'registration', 'ownership',
        'fixed_asset_id', 'supplier_contact_id', 'commitment_id', 'meter_unit', 'default_cost_code_id',
        'working_rate', 'idle_rate', 'standby_rate', 'is_active', 'notes', 'created_by',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    protected $attributes = [
        'ownership' => self::OWNERSHIP_OWNED,
        'meter_unit' => self::METER_HOURS,
        'is_active' => true,
    ];

    protected static function booted(): void
    {
        static::creating(function (self $item): void {
            $item->created_by ??= auth()->id();
        });
    }

    /** The asset behind an owned machine, against which §11 accumulates the depreciation its charges recover. */
    public function fixedAsset(): BelongsTo
    {
        return $this->belongsTo(FixedAsset::class, 'fixed_asset_id');
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Contact::class, 'supplier_contact_id');
    }

    /** The hire order, and what makes §7.3's two-way match possible. */
    public function commitment(): BelongsTo
    {
        return $this->belongsTo(Commitment::class, 'commitment_id');
    }

    public function defaultCostCode(): BelongsTo
    {
        return $this->belongsTo(CostCode::class, 'default_cost_code_id');
    }

    public function logs(): HasMany
    {
        return $this->hasMany(PlantLog::class, 'plant_item_id');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function scopeOwned(Builder $query): Builder
    {
        return $query->where('ownership', self::OWNERSHIP_OWNED);
    }

    public function scopeHired(Builder $query): Builder
    {
        return $query->whereIn('ownership', [self::OWNERSHIP_HIRED, self::OWNERSHIP_HIRED_WITH_OPERATOR]);
    }

    public function isOwned(): bool
    {
        return $this->ownership === self::OWNERSHIP_OWNED;
    }

    public function isHired(): bool
    {
        return ! $this->isOwned();
    }

    /**
     * Whether this machine can be charged at all.
     *
     * A machine with no working rate charges nothing, which is §18.1's healthy-looking figure hiding an absence — so
     * `PlantService` refuses to approve against it rather than booking a day at zero.
     */
    public function hasChargeableRate(): bool
    {
        return (float) ($this->working_rate ?? 0) > 0.0
            || (float) ($this->idle_rate ?? 0) > 0.0
            || (float) ($this->standby_rate ?? 0) > 0.0;
    }

    public function displayName(): string
    {
        return "{$this->code} — {$this->name}";
    }
}
