<?php

namespace App\Modules\Billing\Models;

use App\Models\TenantModel as Model;
use App\Modules\Core\Models\FiscalYear;
use App\Modules\Invoicing\Models\Contact;
use App\Modules\Invoicing\Models\Invoice;
use App\Modules\Payroll\Support\PayrollMonth;
use App\Traits\Auditable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A month's bill to the client: what it was built from, and the invoice it built.
 */
class BillingRun extends Model
{
    use Auditable;

    protected $fillable = [
        'contact_id', 'month', 'fiscal_year_id', 'invoice_date', 'due_date',
        'currency', 'exchange_rate', 'invoice_id', 'notes',
    ];

    protected $attributes = [
        'currency' => 'EUR',
    ];

    protected $casts = [
        'invoice_date' => 'date',
        'due_date' => 'date',
        'exchange_rate' => 'decimal:6',
    ];

    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }

    public function fiscalYear(): BelongsTo
    {
        return $this->belongsTo(FiscalYear::class);
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    public function periodStart(): \Carbon\Carbon
    {
        return PayrollMonth::firstDay($this->month, $this->fiscalYear);
    }

    public function periodEnd(): \Carbon\Carbon
    {
        return PayrollMonth::lastDay($this->month, $this->fiscalYear);
    }

    public function periodLabel(): string
    {
        return $this->periodStart()->format('F Y');
    }

    /**
     * The invoice may still be rebuilt while it is a draft. Once issued it has
     * been posted to the ledger and sent, and rewriting it would change a
     * document the client is holding.
     */
    public function isRebuildable(): bool
    {
        return $this->invoice === null || $this->invoice->isDraft();
    }

    /** The invoice total in the client's currency, at the rate held here. */
    public function totalInClientCurrency(): ?float
    {
        $rate = (float) $this->exchange_rate;

        if (! $this->invoice || $rate <= 0) {
            return null;
        }

        return round((float) $this->invoice->total / $rate, 2);
    }
}
