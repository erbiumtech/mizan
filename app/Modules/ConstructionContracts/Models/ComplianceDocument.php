<?php

namespace App\Modules\ConstructionContracts\Models;

use App\Models\TenantModel as Model;
use App\Modules\Construction\Models\Document;
use App\Modules\Invoicing\Models\Contact;
use App\Traits\Auditable;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One insurance certificate, licence, waiver or bond — `docs/construction-management-plan.md` §12.
 *
 * **There is no status column, and that is the design.** §12 calls a stored status the most dangerous silent failure on
 * the payable side: "a row with a stored `status = 'verified'` and an `expires_on` three months in the past pays a
 * subcontractor with no cover, and the screen says everything is fine". So the status is derived from the dates every
 * time it is asked for, against the date being asked about — which is what makes "was this valid on the 14th of June"
 * a question with an answer.
 *
 * `daysUntilExpiry()` is **signed rather than clamped**, following `EmployeeDocument`: "expired forty days ago" is a
 * different problem from "expires in forty days", and a clamp at zero loses the difference.
 */
class ComplianceDocument extends Model
{
    use Auditable;

    public const STATUS_VALID = 'valid';

    public const STATUS_EXPIRING = 'expiring';

    public const STATUS_EXPIRED = 'expired';

    public const STATUS_NOT_YET_EFFECTIVE = 'not_yet_effective';

    /** Received and not yet read by anybody, which is not the same as satisfied. */
    public const STATUS_UNVERIFIED = 'unverified';

    public const STATUS_MISSING = 'missing';

    public const STATUS_WAIVED = 'waived';

    public const SCOPE_COMPANY = 'company';

    public const SCOPE_CONTRACT = 'contract';

    public const SCOPE_PERIOD = 'period';

    /** How many days before expiry the daily run starts warning. */
    public const WARN_THRESHOLDS = [60, 30, 14, 7, 1];

    protected $table = 'construction_compliance_documents';

    protected $fillable = [
        'contact_id', 'contract_id', 'kind', 'scope', 'period_start', 'period_end', 'covers_certificate_id',
        'reference', 'issuer', 'issued_on', 'effective_from', 'expires_on', 'amount_covered',
        'received_on', 'verified_by', 'verified_at', 'waived_by', 'waived_at', 'waiver_reason',
        'document_id', 'expiry_notified_at_days', 'notes',
    ];

    protected $casts = [
        'period_start' => 'date',
        'period_end' => 'date',
        'issued_on' => 'date',
        'effective_from' => 'date',
        'expires_on' => 'date',
        'received_on' => 'date',
        'verified_at' => 'datetime',
        'waived_at' => 'datetime',
        'expiry_notified_at_days' => 'integer',
    ];

    protected $attributes = [
        'scope' => self::SCOPE_COMPANY,
    ];

    public function contract(): BelongsTo
    {
        return $this->belongsTo(Contract::class, 'contract_id');
    }

    /**
     * Whose document it is — the same guarded coupling `Contract::contact()` explains.
     *
     * Contacts belong to Invoicing, so without that module the column stays null and the register is a register of
     * documents against contracts rather than against companies. Smaller, not broken (§18.1).
     */
    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class, 'contact_id');
    }

    /** The payment this waiver covers, where it is a per-period document. */
    public function coversCertificate(): BelongsTo
    {
        return $this->belongsTo(PaymentCertificate::class, 'covers_certificate_id');
    }

    /** The scan, in the ISO 19650 register rather than in a column here (§15). */
    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class, 'document_id');
    }

    /**
     * **Signed, not clamped**, following `EmployeeDocument::daysUntilExpiry()`.
     *
     * "Expired forty days ago" is a different problem from "expires in forty days", and the sign is what tells them
     * apart. Null where there is no expiry at all — a trade licence with no end date is not expiring.
     */
    public function daysUntilExpiry(string|Carbon|null $asOf = null): ?int
    {
        if (! $this->expires_on) {
            return null;
        }

        $asOf = $asOf ? Carbon::parse($asOf) : now();

        return (int) $asOf->startOfDay()->diffInDays($this->expires_on->copy()->startOfDay(), false);
    }

    /**
     * What this document is, **as at a date**.
     *
     * Derived every time. The date matters: certifying a June payment in August has to ask whether the cover was valid
     * in June, and a status computed only against today cannot answer that.
     */
    public function statusOn(string|Carbon|null $asOf = null, int $graceDays = 0): string
    {
        if ($this->waived_at !== null) {
            return self::STATUS_WAIVED;
        }

        $at = $asOf ? Carbon::parse($asOf) : now();

        if ($this->effective_from && $at->lt($this->effective_from)) {
            return self::STATUS_NOT_YET_EFFECTIVE;
        }

        $days = $this->daysUntilExpiry($at);

        if ($days !== null && $days < -1 * $graceDays) {
            return self::STATUS_EXPIRED;
        }

        // Unverified after the expiry check, deliberately: an expired certificate nobody verified is expired, and
        // reporting it as merely unverified would understate it.
        if ($this->verified_at === null) {
            return self::STATUS_UNVERIFIED;
        }

        if ($days !== null && $days <= max(self::WARN_THRESHOLDS)) {
            return self::STATUS_EXPIRING;
        }

        return self::STATUS_VALID;
    }

    /** Whether this document satisfies a requirement as at a date — the only question certification asks. */
    public function satisfiesOn(string|Carbon|null $asOf = null, int $graceDays = 0): bool
    {
        return in_array($this->statusOn($asOf, $graceDays), [
            self::STATUS_VALID,
            self::STATUS_EXPIRING,
            self::STATUS_WAIVED,
        ], true);
    }

    /** Whether it covers the period a certificate is for, which is what makes a per-period waiver checkable. */
    public function coversPeriod(?Carbon $periodEnd): bool
    {
        if ($this->scope !== self::SCOPE_PERIOD || $periodEnd === null) {
            return true;
        }

        if ($this->covers_certificate_id !== null) {
            return true;
        }

        return ($this->period_start === null || $this->period_start->lte($periodEnd))
            && ($this->period_end === null || $this->period_end->gte($periodEnd));
    }

    public function scopeForContact(Builder $query, int|string|null $contactId): Builder
    {
        return $query->where('contact_id', $contactId);
    }

    /** Expiring or expired, which is what the daily run and the register's default view read. */
    public function scopeNeedingAttention(Builder $query, int $withinDays = 60): Builder
    {
        return $query->whereNull('waived_at')
            ->whereNotNull('expires_on')
            ->whereDate('expires_on', '<=', now()->addDays($withinDays));
    }

    public function isWaived(): bool
    {
        return $this->waived_at !== null;
    }

    public function label(): string
    {
        return str_replace('_', ' ', ucfirst($this->kind));
    }
}
