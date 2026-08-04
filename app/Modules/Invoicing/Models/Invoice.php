<?php

namespace App\Modules\Invoicing\Models;

use App\Models\Concerns\HasCustomFields;
use App\Models\TenantModel as Model;
use App\Modules\Accounting\Models\Currency;
use App\Modules\Accounting\Models\JournalEntry;
use App\Modules\Core\Models\FiscalYear;
use App\Modules\Inventory\Models\StockMovement;
use App\Support\ModuleMap;
use App\Traits\Auditable;
use Illuminate\Support\Carbon;

class Invoice extends Model
{
    use Auditable, HasCustomFields;

    public const KIND_SALE = 'sale';

    public const KIND_PURCHASE = 'purchase';

    public const STATUS_DRAFT = 'draft';

    public const STATUS_ISSUED = 'issued';

    public const STATUS_PARTIALLY_PAID = 'partially_paid';

    public const STATUS_PAID = 'paid';

    public const STATUS_VOID = 'void';

    protected $fillable = [
        'recurring_invoice_id', 'period',
        'invoice_number', 'kind', 'currency_code', 'exchange_rate', 'contact_id', 'invoice_date', 'due_date',
        'status', 'subtotal', 'tax_amount', 'tax_inclusive', 'total', 'amount_paid', 'memo',
        'journal_entry_id', 'fiscal_year_id',
    ];

    protected $attributes = [
        'status' => self::STATUS_DRAFT,
        'amount_paid' => 0,
    ];

    protected $casts = [
        'invoice_date' => 'date',
        'due_date' => 'date',
        'period' => 'date',
        'subtotal' => 'decimal:2',
        'tax_amount' => 'decimal:2',
        'tax_inclusive' => 'boolean',
        'total' => 'decimal:2',
        'amount_paid' => 'decimal:2',
        'exchange_rate' => 'decimal:8',
    ];

    protected static function booted()
    {
        static::creating(function (Invoice $invoice) {
            if (empty($invoice->invoice_number)) {
                $invoice->invoice_number = static::nextInvoiceNumber($invoice->kind, $invoice->invoice_date);
            }
        });

        static::created(function (Invoice $invoice) {
            InvoiceEvent::record(
                $invoice,
                InvoiceEvent::CREATED,
                ($invoice->kind === self::KIND_PURCHASE ? 'Bill' : 'Invoice').' raised as a draft',
            );
        });
    }

    public function recurringInvoice()
    {
        return $this->belongsTo(RecurringInvoice::class);
    }

    public function events()
    {
        return $this->hasMany(InvoiceEvent::class)->latest('id');
    }

    public static function nextInvoiceNumber(string $kind, $date = null): string
    {
        $prefix = $kind === self::KIND_PURCHASE ? 'BILL' : 'INV';
        $year = Carbon::parse($date ?? now())->format('Y');

        $last = static::where('invoice_number', 'like', "{$prefix}-{$year}-%")
            ->orderByDesc('invoice_number')
            ->first();

        $lastNumber = $last ? (int) substr($last->invoice_number, -6) : 0;

        return sprintf('%s-%s-%06d', $prefix, $year, $lastNumber + 1);
    }

    public function contact()
    {
        return $this->belongsTo(Contact::class);
    }

    public function lines()
    {
        return $this->hasMany(InvoiceLine::class);
    }

    public function journalEntry()
    {
        return $this->belongsTo(JournalEntry::class);
    }

    public function fiscalYear()
    {
        return $this->belongsTo(FiscalYear::class);
    }

    public function stockMovements()
    {
        return $this->hasManyThrough(
            StockMovement::class,
            InvoiceLine::class,
            'invoice_id',
            'source_id'
        )->where('stock_movements.source_type', ModuleMap::alias(InvoiceLine::class));
    }

    public function isDraft(): bool
    {
        return $this->status === self::STATUS_DRAFT;
    }

    public function isOpen(): bool
    {
        return in_array($this->status, [self::STATUS_ISSUED, self::STATUS_PARTIALLY_PAID]);
    }

    public function outstanding(): float
    {
        return round((float) $this->total - (float) $this->amount_paid, 2);
    }

    /**
     * The currency this invoice is billed in. Null on the column means the base one,
     * which is what every invoice raised before currencies existed is.
     */
    public function currencyCode(): string
    {
        return $this->currency_code ?: Currency::baseCode();
    }

    public function isForeignCurrency(): bool
    {
        return $this->currencyCode() !== Currency::baseCode();
    }

    /**
     * The rate this invoice was posted at.
     *
     * Read from the column rather than looked up, because a rate recorded later for the
     * invoice date must not silently restate an invoice that has already been issued and
     * whose journal entry says something else.
     */
    public function rate(): float
    {
        return (float) ($this->exchange_rate ?: 1);
    }

    /**
     * The invoice in base currency: what the ledger holds for it.
     *
     * Reports that add invoices together have to use these. Summing `total` across
     * invoices in different currencies produces a number, which is precisely how this
     * goes wrong unnoticed.
     */
    public function baseTotal(): float
    {
        return round((float) $this->total * $this->rate(), 2);
    }

    public function basePaid(): float
    {
        return round((float) $this->amount_paid * $this->rate(), 2);
    }

    /**
     * What is still owed, in base, at the rate it was booked at.
     *
     * Deliberately not at today's rate: the receivable was booked at the invoice rate,
     * and the whole difference between that and the rate on the day the money arrives is
     * recognised then, as a realised gain or loss.
     */
    public function baseOutstanding(): float
    {
        return round($this->baseTotal() - $this->basePaid(), 2);
    }
}
