<?php

namespace App\Modules\ConstructionContracts\Models;

use App\Models\TenantModel as Model;
use App\Modules\Construction\Models\Document;
use App\Modules\Construction\Models\Job;
use App\Support\ModuleMap;
use App\Traits\Auditable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * One back-charge against a subcontract — `docs/construction-management-plan.md` §12.
 *
 * **The state machine is the model.** A back-charge is a claim against somebody who will read it, so each state says
 * something different about what the company may do:
 *
 *  - `draft` — incurred and priced, nobody told. **Cannot be deducted**, and this is the state §12 is about.
 *  - `notified` — notice served on the date recorded. Deductible under most subcontracts.
 *  - `disputed` — contested. Still deductible; the dispute goes where the contract says disputes go.
 *  - `agreed` — settled, possibly for less than notified, with both figures kept.
 *  - `applied` — off a certificate, which names it.
 *  - `withdrawn` — dropped, with a reason, because somebody outside this company was told about it.
 *
 * **`notified_on` is the whole point of the table.** Almost every subcontract requires notice before a back-charge may
 * be deducted, so an incurred-but-unnotified charge is money the company will not get and does not yet know it has
 * lost. `unnotified()` is that number.
 */
class BackCharge extends Model
{
    use Auditable;

    public const STATUS_DRAFT = 'draft';

    public const STATUS_NOTIFIED = 'notified';

    public const STATUS_DISPUTED = 'disputed';

    public const STATUS_AGREED = 'agreed';

    public const STATUS_APPLIED = 'applied';

    public const STATUS_WITHDRAWN = 'withdrawn';

    /**
     * The states a deduction may be taken from.
     *
     * `draft` is absent deliberately — that is the notice rule. `disputed` is present, and it is the one worth
     * defending: most subcontracts let the main contractor deduct a notified charge and send the argument to
     * adjudication, and a register that refused would be a register people worked around.
     */
    public const APPLICABLE_STATUSES = [self::STATUS_NOTIFIED, self::STATUS_DISPUTED, self::STATUS_AGREED];

    /** Still open against the account: priced, not applied, not dropped. */
    public const OPEN_STATUSES = [
        self::STATUS_DRAFT, self::STATUS_NOTIFIED, self::STATUS_DISPUTED, self::STATUS_AGREED,
    ];

    protected $table = 'construction_back_charges';

    protected $fillable = [
        'contract_id', 'job_id', 'reference', 'kind', 'source_type', 'source_id',
        'description', 'incurred_on', 'amount', 'markup_percent', 'total_amount', 'status',
        'notified_on', 'notified_by', 'notice_document_id',
        'disputed_on', 'dispute_reason',
        'agreed_on', 'agreed_amount', 'agreed_by',
        'applied_certificate_id', 'applied_by', 'applied_on',
        'withdrawn_at', 'withdrawn_by', 'withdrawal_reason', 'notes',
    ];

    protected $casts = [
        'incurred_on' => 'date',
        'notified_on' => 'date',
        'disputed_on' => 'date',
        'agreed_on' => 'date',
        'applied_on' => 'date',
        'withdrawn_at' => 'datetime',
    ];

    protected $attributes = [
        'status' => self::STATUS_DRAFT,
        'amount' => 0,
        'total_amount' => 0,
    ];

    /**
     * The morph alias, written through `ModuleMap::alias()`.
     *
     * `back_charges.source_type` is one of the five plain-column morphs §18.2 names: `enforceMorphMap()` does not cover
     * a column written by hand, so without this mutator the fully-qualified class name goes in and the query that
     * traces a back-charge to its NCR stops matching the day that class moves — silently.
     */
    public function setSourceTypeAttribute(?string $value): void
    {
        $this->attributes['source_type'] = $value ? ModuleMap::alias($value) : null;
    }

    public function contract(): BelongsTo
    {
        return $this->belongsTo(Contract::class, 'contract_id');
    }

    public function job(): BelongsTo
    {
        return $this->belongsTo(Job::class, 'job_id');
    }

    /** The NCR, punch item or diary entry this charge is evidence of — null until §16 exists. */
    public function source(): MorphTo
    {
        return $this->morphTo(__FUNCTION__, 'source_type', 'source_id');
    }

    /** The notice itself, in §15's register rather than in a column here. */
    public function noticeDocument(): BelongsTo
    {
        return $this->belongsTo(Document::class, 'notice_document_id');
    }

    public function appliedCertificate(): BelongsTo
    {
        return $this->belongsTo(PaymentCertificate::class, 'applied_certificate_id');
    }

    /**
     * What would come off a certificate: the agreed figure where one exists, otherwise the notified total.
     *
     * Both are kept on the row rather than one overwriting the other, so a concession stays visible — "notified 240,000,
     * settled at 180,000" is the fact somebody needs at final account, and a single column loses the first half of it.
     */
    public function recoverableAmount(): float
    {
        return round((float) ($this->agreed_amount ?? $this->total_amount), 2);
    }

    /** Cost plus markup. Recomputed while the charge is a draft; frozen the moment notice is served. */
    public function computedTotal(): float
    {
        return round((float) $this->amount * (1 + ((float) ($this->markup_percent ?? 0) / 100)), 2);
    }

    public function isDraft(): bool
    {
        return $this->status === self::STATUS_DRAFT;
    }

    public function isNotified(): bool
    {
        return $this->notified_on !== null;
    }

    public function isApplied(): bool
    {
        return $this->status === self::STATUS_APPLIED;
    }

    /** Whether a deduction may be taken from it — the notice rule, in one method. */
    public function isApplicable(): bool
    {
        return in_array($this->status, self::APPLICABLE_STATUSES, true) && $this->isNotified();
    }

    public function isEditable(): bool
    {
        return $this->isDraft();
    }

    /**
     * **The exposure.** Priced, incurred, and nobody told — so nothing may be deducted.
     *
     * §12's sentence, as a query: money the company will not get and does not yet know it has lost.
     */
    public function scopeUnnotified(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_DRAFT);
    }

    /** Notified or settled, and not yet taken off a certificate. */
    public function scopeAwaitingApplication(Builder $query): Builder
    {
        return $query->whereIn('status', self::APPLICABLE_STATUSES)->whereNotNull('notified_on');
    }

    public function scopeOpen(Builder $query): Builder
    {
        return $query->whereIn('status', self::OPEN_STATUSES);
    }

    public function label(): string
    {
        return $this->reference.' — '.str_replace('_', ' ', $this->kind);
    }
}
