<?php

namespace App\Modules\Invoicing\Models;

use App\Models\Concerns\HasCustomFields;
use App\Modules\Core\Models\FiscalYear;
use App\Modules\Accounting\Models\JournalEntry;
use App\Models\TenantModel as Model;
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
        'invoice_number', 'kind', 'contact_id', 'invoice_date', 'due_date',
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
        'subtotal' => 'decimal:2',
        'tax_amount' => 'decimal:2',
        'tax_inclusive' => 'boolean',
        'total' => 'decimal:2',
        'amount_paid' => 'decimal:2',
    ];

    protected static function booted()
    {
        static::creating(function (Invoice $invoice) {
            if (empty($invoice->invoice_number)) {
                $invoice->invoice_number = static::nextInvoiceNumber($invoice->kind, $invoice->invoice_date);
            }
        });
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
}
