<?php

namespace App\Modules\Invoicing\Models;

use App\Models\Concerns\HasCustomFields;
use App\Models\TenantModel as Model;
use App\Modules\Accounting\Models\Currency;
use App\Modules\Accounting\Models\JournalEntry;
use App\Modules\Core\Models\FiscalYear;
use App\Modules\Inventory\Models\StockMovement;
use App\Modules\Projects\Models\Project;
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

    /**
     * FBR reporting state — a second axis, deliberately not more values on
     * `status`. See the migration and docs/fbr-digital-invoicing-plan.md §2.
     *
     * `not_required` is the default and covers every invoice at a company that
     * is not integrated, which today is all of them.
     */
    public const FBR_NOT_REQUIRED = 'not_required';

    public const FBR_PENDING = 'pending';

    public const FBR_SUBMITTED = 'submitted';

    public const FBR_ACCEPTED = 'accepted';

    public const FBR_REJECTED = 'rejected';

    public const FBR_CANCELLED = 'cancelled';

    protected $fillable = [
        'recurring_invoice_id', 'period',
        'invoice_number', 'kind', 'currency_code', 'exchange_rate', 'contact_id', 'project_id', 'invoice_date', 'due_date',
        'status', 'subtotal', 'tax_amount', 'tax_inclusive', 'total', 'amount_paid', 'memo',
        'journal_entry_id', 'fiscal_year_id',
        'fbr_status', 'fbr_irn', 'fbr_usin', 'fbr_reported_at', 'fbr_qr_payload',
    ];

    protected $attributes = [
        'status' => self::STATUS_DRAFT,
        'amount_paid' => 0,
        'fbr_status' => self::FBR_NOT_REQUIRED,
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
        'fbr_reported_at' => 'datetime',
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

    /**
     * The engagement this invoice belongs to — GnuCash's "job".
     *
     * A guarded soft dependency on Projects, the same shape as Billing's on
     * Advances: Invoicing must stay sellable to a company that does not run
     * projects, so every surface that offers this field checks
     * modules()->enabled('projects') first and the column is simply never
     * filled. The relation itself is harmless either way — the table exists in
     * every tenant, because licensing decides what is offered, not what is
     * migrated.
     */
    public function project()
    {
        // Imported properly rather than written as an inline FQCN. The first
        // version used the long form and ModuleBoundaryTest reported the
        // coupling as not existing — the lint reads `use` statements, so an
        // inline reference is a dependency the tool built to track them cannot
        // see. Hiding it would have been worse than declaring it.
        return $this->belongsTo(Project::class);
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

    public function fbrSubmissions()
    {
        return $this->hasMany(FbrSubmission::class)->latest('id');
    }

    /**
     * Does FBR currently consider this invoice a live sales-tax invoice?
     *
     * Only `accepted` counts. A rejected one never landed, a cancelled one has
     * been withdrawn, and `pending` / `submitted` are in flight — none of those
     * is something FBR is holding against the company, which is why only this
     * one constrains what may be done locally.
     */
    public function isFbrLive(): bool
    {
        return $this->fbr_status === self::FBR_ACCEPTED;
    }

    /**
     * When the 72-hour correction window closes, or null if it never opened.
     *
     * Counted from FBR's acceptance rather than from local issuance — see
     * config/fbr.php, where that assumption is flagged as still needing
     * confirmation.
     */
    public function fbrCorrectionWindowClosesAt(): ?Carbon
    {
        if (! $this->isFbrLive() || $this->fbr_reported_at === null) {
            return null;
        }

        return $this->fbr_reported_at->copy()->addHours(
            (int) setting('fbr.correction_window_hours', 72),
        );
    }

    /**
     * May this invoice still be cancelled or edited at FBR without going to the
     * Commissioner?
     *
     * False when it was never reported, which is deliberate: callers ask this to
     * decide whether a *remote* correction is available, and for an unreported
     * invoice there is nothing remote to correct. Whether a LOCAL void is
     * allowed is a different question, and InvoiceService owns it.
     */
    public function fbrCorrectionWindowOpen(): bool
    {
        $closesAt = $this->fbrCorrectionWindowClosesAt();

        return $closesAt !== null && $closesAt->isFuture();
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
