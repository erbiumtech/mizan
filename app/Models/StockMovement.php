<?php

namespace App\Models;

use App\Traits\Auditable;
use Illuminate\Database\Eloquent\Model;

class StockMovement extends Model
{
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
