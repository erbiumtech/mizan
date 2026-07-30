<?php

namespace App\Models;

use App\Models\TenantModel as Model;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\StockMovement;

class InvoiceLine extends Model
{
    protected $fillable = [
        'invoice_id', 'product_id', 'description', 'quantity', 'unit_price',
        'line_total', 'account_id',
    ];

    protected $casts = [
        'quantity' => 'decimal:2',
        'unit_price' => 'decimal:2',
        'line_total' => 'decimal:2',
    ];

    public function invoice()
    {
        return $this->belongsTo(Invoice::class);
    }

    public function product()
    {
        return $this->belongsTo(Product::class);
    }

    public function account()
    {
        return $this->belongsTo(Account::class);
    }

    public function stockMovement()
    {
        return $this->morphOne(StockMovement::class, 'source');
    }
}
