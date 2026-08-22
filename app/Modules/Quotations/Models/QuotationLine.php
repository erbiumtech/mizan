<?php

namespace App\Modules\Quotations\Models;

use App\Models\TenantModel as Model;
use App\Modules\Inventory\Models\Product;
use App\Modules\Invoicing\Models\TaxRate;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One line of a quote.
 *
 * The shape mirrors `invoice_lines` deliberately, so conversion is a **copy rather than a
 * translation** — a translation is how a quote and the invoice raised from it come to
 * disagree about tax, which is the disagreement a customer notices.
 *
 * `discount_pct` is the one field invoice lines do not have: a quote is where discounting is
 * negotiated, and the invoice carries the resulting price rather than the argument.
 */
class QuotationLine extends Model
{
    protected $fillable = [
        'quotation_id', 'product_id', 'description', 'quantity', 'unit_price',
        'discount_pct', 'tax_rate_id', 'tax_amount', 'line_total', 'sort',
    ];

    protected $casts = [
        'quantity' => 'decimal:2',
        'unit_price' => 'decimal:2',
        'discount_pct' => 'decimal:2',
        'tax_amount' => 'decimal:2',
        'line_total' => 'decimal:2',
        'sort' => 'integer',
    ];

    protected $attributes = ['quantity' => 1, 'unit_price' => 0, 'discount_pct' => 0];

    /**
     * Totals are computed on save from quantity, price, discount and the tax rate.
     *
     * On the model rather than in a form, because the quote builder, an import and a
     * supersession copy all write lines — and a hook on one leaves the other two producing
     * a quote whose total does not match its lines.
     */
    protected static function booted(): void
    {
        static::saving(function (self $line): void {
            $net = $line->netTotal();

            $rate = $line->tax_rate_id ? $line->taxRate?->rate : null;

            $line->tax_amount = $rate ? round($net * ((float) $rate / 100), 2) : 0;
            $line->line_total = round($net + (float) $line->tax_amount, 2);
        });
    }

    public function quotation(): BelongsTo
    {
        return $this->belongsTo(Quotation::class);
    }

    /** Guarded on `inventory`, exactly as invoice lines are. */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function taxRate(): BelongsTo
    {
        return $this->belongsTo(TaxRate::class);
    }

    /** Quantity × price, less the discount. The figure tax is charged on. */
    public function netTotal(): float
    {
        $gross = (float) $this->quantity * (float) $this->unit_price;

        return round($gross - ($gross * (float) $this->discount_pct / 100), 2);
    }
}
