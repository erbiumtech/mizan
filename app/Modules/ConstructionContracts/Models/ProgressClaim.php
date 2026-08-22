<?php

namespace App\Modules\ConstructionContracts\Models;

use App\Models\TenantModel as Model;
use App\Traits\Auditable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * What the contractor submits — a Statement under FIDIC, an Application for Payment under AIA.
 *
 * `docs/construction-management-plan.md` §10.1. **Its own table, separate from the certificate**, because they are
 * two documents by two parties with different dates and different legal effect: the time bar for a claim runs from
 * here, the payment period runs from the certificate.
 *
 * The claimed figures on this row stay exactly as submitted. The certifier's own numbers live on the certificate,
 * and keeping both is what makes **applied versus certified** answerable — "the single figure every commercial
 * manager asks for", and one that a single-table design cannot produce without inventing shadow columns.
 */
class ProgressClaim extends Model
{
    use Auditable;

    public const STATUS_DRAFT = 'draft';

    public const STATUS_SUBMITTED = 'submitted';

    public const STATUS_UNDER_REVIEW = 'under_review';

    public const STATUS_CERTIFIED = 'certified';

    public const STATUS_REJECTED = 'rejected';

    public const STATUS_SUPERSEDED = 'superseded';

    protected $table = 'construction_progress_claims';

    protected $fillable = [
        'contract_id', 'claim_number', 'period_start', 'period_end', 'submitted_on', 'status',
        'claimed_work_to_date', 'claimed_materials_to_date', 'claimed_variations_to_date',
        'claimed_gross_to_date', 'notes', 'submitted_by',
    ];

    protected $casts = [
        'period_start' => 'date',
        'period_end' => 'date',
        'submitted_on' => 'date',
    ];

    protected $attributes = [
        'status' => self::STATUS_DRAFT,
        'claimed_work_to_date' => 0,
        'claimed_materials_to_date' => 0,
        'claimed_variations_to_date' => 0,
        'claimed_gross_to_date' => 0,
    ];

    public function contract(): BelongsTo
    {
        return $this->belongsTo(Contract::class, 'contract_id');
    }

    public function lines(): HasMany
    {
        return $this->hasMany(ProgressClaimLine::class, 'progress_claim_id');
    }

    /** The certificate issued against it, if one has been. */
    public function certificate(): HasOne
    {
        return $this->hasOne(PaymentCertificate::class, 'progress_claim_id');
    }

    public function scopeAwaitingCertification(Builder $query): Builder
    {
        return $query->whereIn('status', [self::STATUS_SUBMITTED, self::STATUS_UNDER_REVIEW]);
    }

    public function isEditable(): bool
    {
        return in_array($this->status, [self::STATUS_DRAFT, self::STATUS_UNDER_REVIEW], true);
    }

    /**
     * The gross the contractor claims, cumulative.
     *
     * Read off the lines rather than the header while the claim is open, and stored on submission — the same
     * split the certificate uses, for the same reason: a submitted document is a statement of a moment.
     */
    public function linesGross(): float
    {
        return (float) $this->lines()->sum('cumulative_work_value')
            + (float) $this->lines()->sum('cumulative_materials_value');
    }
}
