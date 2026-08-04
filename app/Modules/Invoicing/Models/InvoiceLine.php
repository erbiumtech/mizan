<?php

namespace App\Modules\Invoicing\Models;

use App\Modules\Accounting\Models\Account;
use App\Models\TenantModel as Model;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\StockMovement;

class InvoiceLine extends Model
{
    protected $fillable = [
        'invoice_id', 'product_id', 'description', 'quantity', 'unit_price',
        'line_total', 'account_id', 'tax_rate_id', 'tax_amount',
    ];

    protected $casts = [
        'quantity' => 'decimal:2',
        'unit_price' => 'decimal:2',
        'line_total' => 'decimal:2',
        'tax_amount' => 'decimal:2',
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

    public function taxRate()
    {
        return $this->belongsTo(TaxRate::class);
    }

    /**
     * The line without its tax.
     *
     * On an inclusive invoice line_total is gross, so the revenue is what is left
     * after the tax comes out; on an exclusive one line_total is already net.
     */
    public function netAmount(): float
    {
        return round((float) $this->line_total - (float) $this->tax_amount, 2);
    }

    public function stockMovement()
    {
        return $this->morphOne(StockMovement::class, 'source');
    }
}
