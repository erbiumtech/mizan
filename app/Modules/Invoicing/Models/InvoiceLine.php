<?php

namespace App\Modules\Invoicing\Models;

use App\Models\TenantModel as Model;
use App\Modules\Accounting\Models\Account;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\StockMovement;

class InvoiceLine extends Model
{
    protected $fillable = [
        'invoice_id', 'product_id', 'description', 'quantity', 'unit_price',
        'line_total', 'account_id', 'tax_rate_id', 'tax_amount',
        'service_from', 'service_to',
    ];

    protected $casts = [
        'service_from' => 'date',
        'service_to' => 'date',
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

    /**
     * How many calendar months the service on this line spans, or null when the line has no service dates.
     *
     * Counted in months rather than days because that is how `DeferralService` recognises: one posting at
     * each month end, so 1 September to 31 August is twelve and 15 September to 14 October — which touches
     * two months — is two. Whole months are what the schedule can express, and a fraction would be a claim
     * about which day the customer got their value that nobody is in a position to make.
     */
    public function serviceMonths(): ?int
    {
        if ($this->service_from === null || $this->service_to === null) {
            return null;
        }

        if ($this->service_to->lessThan($this->service_from)) {
            return null;
        }

        return (int) $this->service_from->copy()->startOfMonth()
            ->diffInMonths($this->service_to->copy()->startOfMonth()) + 1;
    }

    public function stockMovement()
    {
        return $this->morphOne(StockMovement::class, 'source');
    }
}
