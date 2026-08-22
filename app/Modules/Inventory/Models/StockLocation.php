<?php

namespace App\Modules\Inventory\Models;

use App\Models\TenantModel as Model;
use App\Modules\Accounting\Models\Account;
use App\Traits\Auditable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Where stock is — `docs/construction-management-plan.md` §6, `docs/retail-stores-pos-plan.md` §2.1.
 *
 * **Owned by Inventory on purpose, and that ownership is the whole decision.** Two plans found the same gap and each
 * had a fix that would have hurt the other: retail wanted `stock_movements.store_id -> stores`, which would have made a
 * building site either a fake shop carrying till settings and a POS registration, or a second nullable location column
 * — "on-hand a sum over two dimensions, wrong at every location and correct in total". Putting the location here means
 * `construction_jobs.stock_location_id` and `stores.stock_location_id` both point at one table, and neither module
 * depends on the other.
 *
 * A `transit` location is worth explaining, because it looks like a workaround and is not: a transfer between two
 * locations is two movements, and without somewhere to be in between, stock that has left one store and not arrived at
 * the other is either at both or at neither.
 */
class StockLocation extends Model
{
    use Auditable;

    public const KIND_WAREHOUSE = 'warehouse';

    public const KIND_SHOP = 'shop';

    public const KIND_SITE = 'site';

    public const KIND_VAN = 'van';

    public const KIND_TRANSIT = 'transit';

    /** @var array<string, string> */
    public const KINDS = [
        self::KIND_WAREHOUSE => 'Warehouse — a central store',
        self::KIND_SHOP => 'Shop — a retail outlet',
        self::KIND_SITE => 'Site store — a construction site',
        self::KIND_VAN => 'Van — stock on a vehicle',
        self::KIND_TRANSIT => 'In transit — between two locations',
    ];

    protected $table = 'stock_locations';

    protected $fillable = [
        'code', 'name', 'kind', 'address', 'inventory_account_id', 'is_active', 'notes', 'created_by',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    protected $attributes = [
        'kind' => self::KIND_WAREHOUSE,
        'is_active' => true,
    ];

    protected static function booted(): void
    {
        static::creating(function (self $location): void {
            $location->created_by ??= auth()->id();
        });
    }

    /**
     * The account this location's stock sits in, where a company splits inventory by location.
     *
     * Null means the product's own account applies, which is what happens today and has to keep happening.
     */
    public function inventoryAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'inventory_account_id');
    }

    public function movements(): HasMany
    {
        return $this->hasMany(StockMovement::class, 'stock_location_id');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function scopeOfKind(Builder $query, string $kind): Builder
    {
        return $query->where('kind', $kind);
    }

    public function isSite(): bool
    {
        return $this->kind === self::KIND_SITE;
    }

    public function displayName(): string
    {
        return "{$this->code} — {$this->name}";
    }
}
