<?php

namespace App\Modules\ConstructionContracts\Models;

use App\Models\TenantModel as Model;
use App\Support\ModuleMap;
use App\Traits\Auditable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * One line of the bottom half of a certificate — `docs/construction-management-plan.md` §10.3.
 *
 * **Every deduction is a row, and the sign convention is stated once: a negative amount reduces the payment.**
 *
 * Generalising the bottom half of the certificate into one child table is what makes an NCR deduction traceable to
 * the NCR, advance recovery auditable against the contract terms, and liquidated damages a first-class fact rather
 * than a note in a memo field. It is also what makes the dual-standard claim hold: the only figure FIDIC uses that
 * AIA does not — advance recovery — is a *row* rather than a column, so an AIA certificate simply has no such row
 * (§8.4).
 */
class CertificateDeduction extends Model
{
    use Auditable;

    public const KIND_RETENTION = 'retention';

    public const KIND_RETENTION_RELEASE = 'retention_release';

    public const KIND_ADVANCE_RECOVERY = 'advance_recovery';

    public const KIND_NCR = 'ncr';

    public const KIND_LIQUIDATED_DAMAGES = 'liquidated_damages';

    public const KIND_BACK_CHARGE = 'back_charge';

    public const KIND_CONTRA_CHARGE = 'contra_charge';

    public const KIND_PREVIOUS_CERTIFICATES = 'previous_certificates';

    public const KIND_TAX_WITHHELD = 'tax_withheld';

    public const KIND_UNFIXED_MATERIALS = 'unfixed_materials_adjustment';

    public const KIND_OTHER = 'other';

    /**
     * The kinds this module computes for itself, which is what `is_automatic` records.
     *
     * Named here because the certification service writes them and the screen must not offer them as manual
     * additions: two retention rows on one certificate is a double deduction nobody notices until the
     * subcontractor does.
     *
     * @var array<int, string>
     */
    public const AUTOMATIC_KINDS = [
        self::KIND_RETENTION,
        self::KIND_ADVANCE_RECOVERY,
        self::KIND_PREVIOUS_CERTIFICATES,
    ];

    protected $table = 'construction_certificate_deductions';

    protected $fillable = [
        'payment_certificate_id', 'kind', 'description', 'amount',
        'source_type', 'source_id', 'account_id', 'is_automatic', 'approved_by',
    ];

    protected $casts = [
        'is_automatic' => 'boolean',
    ];

    protected $attributes = [
        'is_automatic' => false,
    ];

    /**
     * The morph alias, written through `ModuleMap::alias()`.
     *
     * `source_type` is a **plain column** and `enforceMorphMap()` does not cover those — §18.2 names this table
     * first among the five exposed. Without the mutator the fully-qualified class name goes into the column and
     * the day that class moves, the query that traces a deduction back to its NCR stops matching silently.
     */
    public function setSourceTypeAttribute(?string $value): void
    {
        $this->attributes['source_type'] = $value ? ModuleMap::alias($value) : null;
    }

    public function certificate(): BelongsTo
    {
        return $this->belongsTo(PaymentCertificate::class, 'payment_certificate_id');
    }

    /** The NCR, the back-charge, the retention movement — whatever this deduction is evidence of. */
    public function source(): MorphTo
    {
        return $this->morphTo(__FUNCTION__, 'source_type', 'source_id');
    }

    public function reducesPayment(): bool
    {
        return (float) $this->amount < 0;
    }
}
