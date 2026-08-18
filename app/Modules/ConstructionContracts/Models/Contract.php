<?php

namespace App\Modules\ConstructionContracts\Models;

use App\Models\TenantModel as Model;
use App\Modules\Construction\Models\Job;
use App\Modules\ConstructionContracts\Support\ContractVocabulary;
use App\Modules\Invoicing\Models\Contact;
use App\Traits\Auditable;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One contract on a job — the head contract we bill, or a subcontract we pay.
 *
 * `docs/construction-management-plan.md` §8. **One table for both**, separated by `side`, because the
 * arithmetic is mirrored and "staged retention release is the fiddliest logic in the suite, so a second copy
 * of it will diverge". The precedent is `invoices.kind`.
 *
 * Three things about this model carry money.
 *
 *  - **The revised contract sum is computed, and it reads *agreed* variations only.** A provisionally priced
 *    variation is excluded from the certified sum and included in the forecast (§9), which is why there are
 *    two accessors here and not one number. Conflating them "is how a job reports a margin it does not have
 *    for two quarters running".
 *  - **Expiry of the defects period is computed** from `practical_completion_date + defects_period_days`,
 *    never stored. A stored expiry stops agreeing with a completion date somebody corrected last week.
 *  - **`contract_standard` freezes on first certification.** It drives the numbering series, the release rule
 *    and the printed form of documents already issued; flipping it in month fourteen would re-label and
 *    re-print certificates one to thirteen with no event recording any of it.
 */
class Contract extends Model
{
    use Auditable;

    public const SIDE_RECEIVABLE = 'receivable';

    public const SIDE_PAYABLE = 'payable';

    public const STATUS_DRAFT = 'draft';

    public const STATUS_EXECUTED = 'executed';

    public const STATUS_IN_PROGRESS = 'in_progress';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_CLOSED = 'closed';

    public const STATUS_TERMINATED = 'terminated';

    public const BASIS_LUMP_SUM = 'lump_sum';

    public const BASIS_REMEASURED = 'remeasured';

    public const BASIS_MIXED = 'mixed';

    public const BASIS_COST_PLUS = 'cost_plus';

    public const BASIS_TARGET_COST = 'target_cost';

    public const RELEASE_FIDIC_TWO_STAGE = 'fidic_two_stage';

    public const RELEASE_AIA_SUBSTANTIAL = 'aia_substantial';

    public const RELEASE_SINGLE_STAGE = 'single_stage';

    public const RELEASE_CUSTOM = 'custom';

    protected $table = 'construction_contracts';

    protected $fillable = [
        'job_id', 'side', 'contract_standard', 'fidic_book', 'contract_number', 'contact_id',
        'parent_contract_id', 'title', 'scope_summary', 'measurement_basis', 'classification_system',
        'currency_code', 'exchange_rate', 'contract_sum',
        'retention_percent', 'retention_limit_percent', 'retention_limit_amount', 'retention_release_rule',
        'retention_first_release_pct', 'materials_retention_percent',
        'advance_payment_amount', 'advance_recovery_start_pct', 'advance_recovery_rate_pct',
        'liquidated_damages_per_day', 'liquidated_damages_cap_pct',
        'payment_terms_days', 'certification_period_days', 'minimum_certificate_amount',
        'contract_date', 'commencement_date', 'time_for_completion_days', 'contract_completion_date',
        'extended_completion_date', 'practical_completion_date', 'defects_period_days',
        'final_completion_date', 'status',
    ];

    protected $casts = [
        'contract_date' => 'date',
        'commencement_date' => 'date',
        'contract_completion_date' => 'date',
        'extended_completion_date' => 'date',
        'practical_completion_date' => 'date',
        'final_completion_date' => 'date',
        'time_for_completion_days' => 'integer',
        'defects_period_days' => 'integer',
        'payment_terms_days' => 'integer',
        'certification_period_days' => 'integer',
    ];

    protected $attributes = [
        'side' => self::SIDE_RECEIVABLE,
        'contract_standard' => ContractVocabulary::FIDIC,
        'measurement_basis' => self::BASIS_LUMP_SUM,
        'retention_release_rule' => self::RELEASE_FIDIC_TWO_STAGE,
        'status' => self::STATUS_DRAFT,
        'contract_sum' => 0,
    ];

    public function job(): BelongsTo
    {
        return $this->belongsTo(Job::class, 'job_id');
    }

    /**
     * The employer on a receivable contract, the subcontractor on a payable one.
     *
     * A guarded coupling rather than a requirement: Contacts belong to Invoicing, and §18 keeps this module
     * sellable to a contractor whose books are somewhere else. Without Invoicing the picker is absent and the
     * column stays null — the contract is still a contract, which is §18.1's "smaller, never broken".
     */
    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class, 'contact_id');
    }

    /** The head contract above a subcontract, which is what makes back-to-back retention reportable. */
    public function parentContract(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_contract_id');
    }

    public function subcontracts(): HasMany
    {
        return $this->hasMany(self::class, 'parent_contract_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(ContractItem::class, 'contract_id')->orderBy('sort')->orderBy('item_no');
    }

    public function variations(): HasMany
    {
        return $this->hasMany(Variation::class, 'contract_id');
    }

    /**
     * The net of **agreed** variations — what a certificate's `variations_net_to_date` and G702 line 2 read.
     *
     * Agreed means approved and not provisionally priced (§9). Anything else in this figure is money certified
     * that nobody agreed, which is the failure that section is written around.
     */
    public function agreedVariationsNet(): float
    {
        return (float) $this->variations()->agreed()->sum('approved_amount');
    }

    /**
     * The revised contract sum — original plus agreed variations, **computed**.
     *
     * A stored revised sum is a number two people quote in a meeting and one of them has stale. The
     * computed-not-stored rule applies here with unusual force for exactly that reason.
     */
    public function revisedSum(): float
    {
        return round((float) $this->contract_sum + $this->agreedVariationsNet(), 2);
    }

    /**
     * The sum the **forecast** uses: original plus everything live, provisional included.
     *
     * Deliberately a different number from `revisedSum()`, and the difference is the point — it is the money the
     * job is already spending and has not yet agreed. Read through `effectiveAmount()` rather than
     * `approved_amount`, because a variation approved in principle has an assessed figure and no approved one,
     * and summing the approved column would forecast it as nothing.
     */
    public function forecastSum(): float
    {
        $live = $this->variations()->forecast()->get()
            ->sum(fn (Variation $variation): float => $variation->effectiveAmount());

        return round((float) $this->contract_sum + $live, 2);
    }

    /**
     * The words this contract's standard uses.
     *
     * Every screen asks here rather than matching on the standard itself — §8.3's whole point.
     */
    public function vocabulary(): ContractVocabulary
    {
        return ContractVocabulary::for($this->contract_standard);
    }

    public function scopeReceivable(Builder $query): Builder
    {
        return $query->where('side', self::SIDE_RECEIVABLE);
    }

    public function scopePayable(Builder $query): Builder
    {
        return $query->where('side', self::SIDE_PAYABLE);
    }

    public function isDraft(): bool
    {
        return $this->status === self::STATUS_DRAFT;
    }

    public function isRemeasured(): bool
    {
        return in_array($this->measurement_basis, [self::BASIS_REMEASURED, self::BASIS_MIXED], true);
    }

    /**
     * The sum of the item schedule.
     *
     * Not the same figure as `contract_sum`, and the difference is informative rather than an error: the
     * contract sum is what the parties signed, the schedule is what was priced against it, and on a lump-sum
     * contract they are agreed to differ by whatever was not broken down. A screen that showed only one of
     * them would hide the gap.
     */
    public function scheduleTotal(): float
    {
        return (float) $this->items()->where('is_active', true)->sum('scheduled_value');
    }

    /**
     * When the defects period expires — **computed, never stored** (§8.1).
     *
     * Null while either half of the answer is missing, because a date derived from a null completion date is
     * a guess, and the second retention release hangs off this.
     */
    public function defectsPeriodExpiry(): ?Carbon
    {
        if ($this->practical_completion_date === null || $this->defects_period_days === null) {
            return null;
        }

        return $this->practical_completion_date->copy()->addDays($this->defects_period_days);
    }

    /** Whichever completion date is currently in force — an approved extension of time wins. */
    public function completionDate(): ?Carbon
    {
        return $this->extended_completion_date ?? $this->contract_completion_date;
    }

    /**
     * The retention cap in money, from whichever of the two limit columns is set.
     *
     * Both exist because contracts express it both ways — "five per cent of the contract sum" and "capped at
     * 25,000,000" — and converting one into the other at data-entry time would silently stop tracking a
     * percentage cap when the contract sum moves on a remeasured job.
     */
    public function retentionCap(): ?float
    {
        if ($this->retention_limit_amount !== null) {
            return (float) $this->retention_limit_amount;
        }

        if ($this->retention_limit_percent === null) {
            return null;
        }

        return round(((float) $this->contract_sum) * ((float) $this->retention_limit_percent) / 100, 2);
    }

    public function displayName(): string
    {
        return "{$this->contract_number} — {$this->title}";
    }
}
