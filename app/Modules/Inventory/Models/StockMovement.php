<?php

namespace App\Modules\Inventory\Models;

use App\Support\ModuleMap;
use App\Modules\Accounting\Models\JournalEntry;
use App\Models\TenantModel as Model;
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

    protected $fillable = [
        'product_id', 'type', 'quantity', 'unit_cost', 'unit_price', 'total_cost',
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

    public function source()
    {
        return $this->morphTo();
    }
}
