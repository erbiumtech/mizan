<?php

namespace App\Modules\ConstructionCosting\Models;

use App\Models\TenantModel as Model;
use App\Traits\Auditable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;

/**
 * Money promised and not yet invoiced — `docs/construction-management-plan.md` §5.
 *
 * **One table for purchase orders, subcontracts and plant hire.** The four-column report and the relief mechanism
 * have to behave identically for all three, or "committed" means different things in one column; two tables would
 * make every commitment query a `UNION`, "and a `UNION` is where one branch silently gains a filter the other does
 * not and the report still renders".
 *
 * A subcontract is **both** a commitment and a contract: `contract_id` points at the `construction_contracts` row
 * that carries the schedule, the variations, the certificates and the retention, while this row carries the money
 * committed under it. §5's table records why that is a link rather than a choice between the two.
 *
 * **The open balance is computed**, never stored: `Σ lines.amount − Σ reliefs`. A stored balance is a second place
 * for the same figure to live, and the first thing that goes wrong is a receipt that relieves and a stored total
 * that does not move.
 */
class Commitment extends Model
{
    use Auditable;

    public const TYPE_PURCHASE_ORDER = 'purchase_order';

    public const TYPE_SUBCONTRACT = 'subcontract';

    public const TYPE_PLANT_HIRE = 'plant_hire';

    public const TYPE_MANUAL = 'manual';

    public const STATUS_DRAFT = 'draft';

    public const STATUS_PENDING_APPROVAL = 'pending_approval';

    public const STATUS_APPROVED = 'approved';

    public const STATUS_ISSUED = 'issued';

    public const STATUS_PARTIALLY_RELIEVED = 'partially_relieved';

    public const STATUS_CLOSED = 'closed';

    public const STATUS_CANCELLED = 'cancelled';

    /**
     * The statuses that put money on the four-column report's committed column.
     *
     * **Issued and beyond, never approved-but-not-issued.** An approved order the supplier has not been sent is a
     * decision inside this company; a commitment is a promise somebody else is relying on. Counting the first would
     * report money as committed that could still be withdrawn with a phone call and no consequence.
     *
     * @var array<int, string>
     */
    public const COMMITTING_STATUSES = [
        self::STATUS_ISSUED,
        self::STATUS_PARTIALLY_RELIEVED,
    ];

    protected $table = 'construction_commitments';

    protected $fillable = [
        'number', 'type', 'contact_id', 'contract_id', 'status', 'currency_code', 'exchange_rate',
        'order_date', 'required_by', 'payment_terms_days', 'retention_percent',
        'description', 'supplier_reference',
        'approved_at', 'approved_by', 'issued_at', 'issued_by',
        'closed_at', 'closed_by', 'close_reason',
    ];

    protected $casts = [
        'order_date' => 'date',
        'required_by' => 'date',
        'approved_at' => 'datetime',
        'issued_at' => 'datetime',
        'closed_at' => 'datetime',
        'payment_terms_days' => 'integer',
    ];

    protected $attributes = [
        'type' => self::TYPE_PURCHASE_ORDER,
        'status' => self::STATUS_DRAFT,
    ];

    public function lines(): HasMany
    {
        return $this->hasMany(CommitmentLine::class, 'commitment_id');
    }

    public function reliefs(): HasManyThrough
    {
        return $this->hasManyThrough(
            CommitmentRelief::class,
            CommitmentLine::class,
            'commitment_id',
            'commitment_line_id',
        );
    }

    /** Orders that put money on the committed column — see COMMITTING_STATUSES for why not `approved`. */
    public function scopeCommitting(Builder $query): Builder
    {
        return $query->whereIn('status', self::COMMITTING_STATUSES);
    }

    public function scopeOpen(Builder $query): Builder
    {
        return $query->whereNotIn('status', [self::STATUS_CLOSED, self::STATUS_CANCELLED]);
    }

    public function isDraft(): bool
    {
        return $this->status === self::STATUS_DRAFT;
    }

    public function isIssued(): bool
    {
        return in_array($this->status, self::COMMITTING_STATUSES, true);
    }

    public function isClosed(): bool
    {
        return in_array($this->status, [self::STATUS_CLOSED, self::STATUS_CANCELLED], true);
    }

    /** What was ordered. */
    public function orderedTotal(): float
    {
        return round((float) $this->lines()->sum('amount'), 2);
    }

    /**
     * What has been relieved against it — received, certified, invoiced, cancelled or closed out.
     *
     * The column is qualified because this is a `hasManyThrough` and **both** joined tables have an `amount`:
     * unqualified, SQLite refuses the query outright, which is the good outcome. MySQL would have been within its
     * rights to pick either one, and picking the line's amount would have made every order look fully relieved the
     * moment anything was received against it.
     */
    public function relievedTotal(): float
    {
        return round((float) $this->reliefs()->sum((new CommitmentRelief)->getTable().'.amount'), 2);
    }

    /**
     * The open balance: what is still promised.
     *
     * Floored at zero for reading rather than for arithmetic — an over-relief is a real condition worth finding, so
     * `overRelieved()` answers it rather than this method hiding it.
     */
    public function openTotal(): float
    {
        return round(max(0.0, $this->orderedTotal() - $this->relievedTotal()), 2);
    }

    /**
     * More relieved than ordered, which is the state a heuristic "committed = ordered − invoiced" leaves behind
     * and cannot clear. Here it is a question with an answer.
     */
    public function overRelieved(): bool
    {
        return $this->relievedTotal() > $this->orderedTotal();
    }

    public function displayName(): string
    {
        return $this->number.($this->description ? " — {$this->description}" : '');
    }
}
