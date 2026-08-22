<?php

namespace App\Modules\Inventory\Models;

use App\Models\TenantModel as Model;
use App\Modules\Accounting\Models\JournalEntry;
use App\Support\ModuleMap;
use App\Traits\Auditable;

class StockMovement extends Model
{
    /**
     * Normalise on write: `source_type` holds a model's stable alias, never its live
     * class name, so the row survives that model moving into a module directory.
     */
    public function setSourceTypeAttribute(?string $value): void
    {
        $this->attributes['source_type'] = $value === null ? null : ModuleMap::alias($value);
    }

    use Auditable;

    /** Construction's two: material out of a site store, and unused material back into it (§6). */
    public const TYPE_ISSUE = 'issue';

    public const TYPE_RETURN = 'return';

    /** Retail's three, added in the same migration so the enum is expanded once (§6). */
    public const TYPE_TRANSFER = 'transfer';

    public const TYPE_WASTE = 'waste';

    public const TYPE_COUNT_ADJUSTMENT = 'count_adjustment';

    protected $fillable = [
        'product_id', 'stock_location_id', 'type', 'quantity', 'unit_cost', 'unit_price', 'total_cost',
        'remaining_quantity', 'movement_date', 'reference', 'journal_entry_id',
        'source_type', 'source_id',
    ];

    protected $casts = [
        'quantity' => 'decimal:2',
        'unit_cost' => 'decimal:2',
        'unit_price' => 'decimal:2',
        'total_cost' => 'decimal:2',
        'remaining_quantity' => 'decimal:2',
        'movement_date' => 'date',
    ];

    public function product()
    {
        return $this->belongsTo(Product::class);
    }

    public function journalEntry()
    {
        return $this->belongsTo(JournalEntry::class);
    }

    /**
     * Where this movement happened.
     *
     * Nullable, and the null means "stock not tracked by location" rather than "location unknown" — a company that has
     * created no locations has none of these set, which is the state a contractor buying everything direct to site is
     * in and which §6 says must keep working. `InventoryService` takes a location on every method and writes it;
     * `InvoiceService` does not, because an invoice has no location until the retail plan gives `stores` one.
     */
    public function stockLocation()
    {
        return $this->belongsTo(StockLocation::class, 'stock_location_id');
    }

    /** Movements at one location — the query both plans added the column for. */
    public function scopeAt($query, StockLocation|int|string|null $location)
    {
        return $location === null
            ? $query
            : $query->where('stock_location_id', $location instanceof StockLocation ? $location->getKey() : $location);
    }

    public function source()
    {
        return $this->morphTo();
    }
}
