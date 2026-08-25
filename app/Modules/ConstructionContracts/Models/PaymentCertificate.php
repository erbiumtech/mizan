<?php

namespace App\Modules\ConstructionContracts\Models;

use App\Models\TenantModel as Model;
use App\Traits\Auditable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * What the certifier issues — an Interim Payment Certificate under FIDIC, a Certificate for Payment under AIA.
 *
 * `docs/construction-management-plan.md` §10. Three properties of this row carry the money.
 *
 * **Cumulative is stored; the period movement is derived.** Every figure here is "to date", which is what both
 * forms print. Storing the period amount and summing for the cumulative means a corrected earlier certificate
 * silently breaks every later total; storing the cumulative makes a correction self-healing — the next
 * certificate's "this period" figure absorbs it, exactly as happens on paper.
 *
 * **Draft computes, issue freezes.** A certificate is not a running balance; it is a statement of a moment handed
 * to a third party who countersigns it and may take it to adjudication. While it is a draft the figures follow the
 * schedule and the claim; on issue they stop. `Invoice::exchangeRate()` makes the same exception for the same
 * reason.
 *
 * **`contract_sum_to_date` is not a column.** It is `contract_sum_original + variations_net_to_date`, both frozen
 * here, so a third stored column could only ever disagree with its own two inputs — and §8.4's G702 mapping
 * calls line 3 derived.
 */
class PaymentCertificate extends Model
{
    use Auditable;

    public const STATUS_DRAFT = 'draft';

    public const STATUS_ISSUED = 'issued';

    public const STATUS_PAID = 'paid';

    public const STATUS_VOID = 'void';

    protected $table = 'construction_payment_certificates';

    protected $fillable = [
        'contract_id', 'progress_claim_id', 'certificate_number', 'sequence',
        'period_start', 'period_end', 'issued_on', 'due_on', 'status',
        'contract_sum_original', 'variations_net_to_date',
        'gross_work_to_date', 'gross_materials_to_date', 'gross_value_to_date',
        'retention_to_date', 'previously_certified', 'previous_gross_value_to_date', 'current_due',
        'certified_by', 'notes', 'void_reason', 'invoice_id',
        // The compliance override (§12): certified knowing the cover was not in place, and who decided that.
        'compliance_override_at', 'compliance_override_by', 'compliance_override_reason',
    ];

    protected $casts = [
        'period_start' => 'date',
        'period_end' => 'date',
        'issued_on' => 'date',
        'due_on' => 'date',
        'sequence' => 'integer',
        'compliance_override_at' => 'datetime',
    ];

    protected $attributes = [
        'status' => self::STATUS_DRAFT,
        'contract_sum_original' => 0,
        'variations_net_to_date' => 0,
        'gross_work_to_date' => 0,
        'gross_materials_to_date' => 0,
        'gross_value_to_date' => 0,
        'retention_to_date' => 0,
        'previously_certified' => 0,
        'previous_gross_value_to_date' => 0,
        'current_due' => 0,
    ];

    public function contract(): BelongsTo
    {
        return $this->belongsTo(Contract::class, 'contract_id');
    }

    /** Nullable: FIDIC 14.6 lets the Engineer certify without a conforming statement. */
    public function progressClaim(): BelongsTo
    {
        return $this->belongsTo(ProgressClaim::class, 'progress_claim_id');
    }

    public function lines(): HasMany
    {
        return $this->hasMany(CertificateLine::class, 'payment_certificate_id');
    }

    public function deductions(): HasMany
    {
        return $this->hasMany(CertificateDeduction::class, 'payment_certificate_id');
    }

    /** Issued and not voided — the certificates that count towards anything. */
    public function scopeLive(Builder $query): Builder
    {
        return $query->whereIn('status', [self::STATUS_ISSUED, self::STATUS_PAID]);
    }

    public function isDraft(): bool
    {
        return $this->status === self::STATUS_DRAFT;
    }

    public function isIssued(): bool
    {
        return in_array($this->status, [self::STATUS_ISSUED, self::STATUS_PAID], true);
    }

    /** G702 line 3, derived. See the class docblock on why it is not stored. */
    public function contractSumToDate(): float
    {
        return round((float) $this->contract_sum_original + (float) $this->variations_net_to_date, 2);
    }

    /**
     * The period movement — G703 column E, and the figure the invoice is raised for.
     *
     * Derived from two cumulative snapshots, which is the direction that survives a correction: if certificate 6
     * was wrong and reissued, this certificate's movement absorbs the difference rather than compounding it.
     */
    public function grossThisPeriod(): float
    {
        /*
         * The work done THIS period, and the subtrahend is gross rather than net on purpose.
         *
         * Netting against `previously_certified` — the cash already paid — would fold the earlier
         * retention into this period's "work", which is how the certificate came to over-certify by
         * exactly the prior retention. Gross against gross leaves a figure that means what its name says,
         * and is what the invoice's job-cost line must carry for the ledger to add up over the contract.
         */
        return round((float) $this->gross_value_to_date - (float) $this->previous_gross_value_to_date, 2);
    }

    /** The last live certificate before this one, or null when this is the first. */
    public function previousLive(): ?self
    {
        return static::query()
            ->where('contract_id', $this->contract_id)
            ->live()
            ->where('sequence', '<', $this->sequence)
            ->orderByDesc('sequence')
            ->first();
    }

    /** The net of every deduction row. Negative reduces the payment — one convention (§10.3). */
    public function deductionsTotal(): float
    {
        return round((float) $this->deductions()->sum('amount'), 2);
    }

    /**
     * What the certificate says is payable now.
     *
     * Computed while the certificate is a draft and read off the stored column once issued, which is the
     * draft-computes / issue-freezes rule in one method. A certificate whose bottom line moved after it was
     * countersigned is the failure this whole design is arranged around.
     */
    public function currentDue(): float
    {
        if ($this->isDraft()) {
            return round((float) $this->gross_value_to_date + $this->deductionsTotal(), 2);
        }

        return (float) $this->current_due;
    }

    /** Retention held on this certificate, from the deduction rows rather than the header column. */
    public function retentionThisPeriod(): float
    {
        return round((float) $this->deductions()->where('kind', CertificateDeduction::KIND_RETENTION)->sum('amount'), 2);
    }

    public function displayName(): string
    {
        return $this->certificate_number;
    }
}
