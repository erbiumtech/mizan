<?php

namespace App\Modules\Invoicing\Models;

use App\Models\TenantModel as Model;
use App\Modules\Accounting\Models\Account;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One line of a standing agreement — the template an invoice line is copied from.
 */
class RecurringInvoiceLine extends Model
{
    protected $fillable = [
        'recurring_invoice_id', 'description', 'quantity', 'unit_price',
        'account_id', 'tax_rate_id', 'sort',
    ];

    protected $casts = [
        'quantity' => 'decimal:2',
        'unit_price' => 'decimal:2',
        'sort' => 'integer',
    ];

    public function recurringInvoice(): BelongsTo
    {
        return $this->belongsTo(RecurringInvoice::class);
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    public function taxRate(): BelongsTo
    {
        return $this->belongsTo(TaxRate::class);
    }

    public function lineTotal(): float
    {
        return round((float) $this->quantity * (float) $this->unit_price, 2);
    }
}
