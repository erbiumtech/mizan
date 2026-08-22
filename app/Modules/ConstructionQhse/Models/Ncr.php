<?php

namespace App\Modules\ConstructionQhse\Models;

use App\Models\TenantModel as Model;
use App\Modules\Construction\Models\Job;
use App\Modules\Construction\Models\Location;
use App\Modules\Construction\Models\WbsNode;
use App\Modules\Invoicing\Models\Contact;
use App\Traits\Auditable;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One non-conformance report — `docs/construction-management-plan.md` §17.2.
 *
 * **This model proposes deductions and never applies one.** §17.2: "an NCR never deducts automatically. It *proposes*;
 * the certification service **offers** the deduction as a row on the certificate that a human confirms and signs for."
 * FIDIC 14.6 permits the Engineer to withhold; it does not require it — and "a deduction appearing on a certificate that
 * nobody decided on is the fastest available route to a dispute, and it will be the contractor's dispute, because the
 * client's copy has already left the building."
 *
 * So `deduct_from_payment` is a flag meaning *somebody thinks this should be withheld*, and `deduction_certificate_id`
 * is a nullable column recording which certificate a human eventually put it on — which nothing in this module writes.
 * The precedent §17.2 names is `final_settlements.payslip_id`: a proposal, not a posting, visible in the import graph.
 *
 * **`disposition` is the field that decides whether money changes hands**, and it is nullable on purpose: a freshly
 * raised NCR has not been dispositioned, and a default of `rework` would quietly settle the commercially significant
 * question on every new row.
 *
 * **CAPA is two pairs of fields.** Corrective action is about this pour; preventive action is about the next forty.
 * Merging them produces NCRs whose preventive action restates the fix, which is what §17.2 refuses.
 */
class Ncr extends Model
{
    use Auditable;

    public const SEVERITY_MINOR = 'minor';

    public const SEVERITY_MAJOR = 'major';

    public const SEVERITY_CRITICAL = 'critical';

    /** @var array<string, string> */
    public const SEVERITIES = [
        self::SEVERITY_MINOR => 'Minor',
        self::SEVERITY_MAJOR => 'Major',
        self::SEVERITY_CRITICAL => 'Critical',
    ];

    public const DISPOSITION_REWORK = 'rework';

    public const DISPOSITION_REPAIR = 'repair';

    public const DISPOSITION_USE_AS_IS = 'use_as_is';

    public const DISPOSITION_REJECT = 'reject_and_replace';

    public const DISPOSITION_CONCESSION = 'concession_requested';

    /**
     * ISO 9001's control of nonconforming output.
     *
     * @var array<string, string>
     */
    public const DISPOSITIONS = [
        self::DISPOSITION_REWORK => 'Rework to specification',
        self::DISPOSITION_REPAIR => 'Repair',
        self::DISPOSITION_USE_AS_IS => 'Use as is',
        self::DISPOSITION_REJECT => 'Reject and replace',
        self::DISPOSITION_CONCESSION => 'Concession requested',
    ];

    /**
     * The two dispositions that end in a conversation about price.
     *
     * Accepting nonconforming work — as is, or under a concession — is the client giving something up, and it is
     * normally paid for with a reduction. Naming them is what lets the register ask "which of these has a figure against
     * it", which is a question nobody asks of a rework.
     */
    public const COMMERCIAL_DISPOSITIONS = [self::DISPOSITION_USE_AS_IS, self::DISPOSITION_CONCESSION];

    public const STATUS_OPEN = 'open';

    public const STATUS_DISPOSITIONED = 'dispositioned';

    public const STATUS_ACTION_TAKEN = 'action_taken';

    public const STATUS_VERIFIED = 'verified';

    public const STATUS_CLOSED = 'closed';

    public const STATUS_VOID = 'void';

    /** @var array<string, string> */
    public const STATUSES = [
        self::STATUS_OPEN => 'Open',
        self::STATUS_DISPOSITIONED => 'Dispositioned',
        self::STATUS_ACTION_TAKEN => 'Action taken',
        self::STATUS_VERIFIED => 'Verified',
        self::STATUS_CLOSED => 'Closed',
        self::STATUS_VOID => 'Void',
    ];

    /** Still somebody's to deal with. */
    public const LIVE = [
        self::STATUS_OPEN, self::STATUS_DISPOSITIONED, self::STATUS_ACTION_TAKEN, self::STATUS_VERIFIED,
    ];

    protected $table = 'construction_ncrs';

    protected $fillable = [
        'job_id', 'contract_id', 'ncr_number', 'raised_by', 'raised_on', 'severity', 'category', 'source',
        'inspection_id', 'itp_activity_id', 'location_id', 'wbs_node_id', 'contract_item_id', 'activity_id',
        'responsible_contact_id', 'responsible_label', 'description', 'requirement_breached',
        'disposition', 'concession_reference', 'dispositioned_on', 'dispositioned_by',
        'root_cause', 'root_cause_method',
        'corrective_action', 'corrective_owner_id', 'corrective_owner_label', 'corrective_due_on', 'corrective_done_on',
        'preventive_action', 'preventive_owner_id', 'preventive_owner_label', 'preventive_due_on', 'preventive_done_on',
        'verified_by', 'verified_on', 'verification_inspection_id',
        'status', 'closed_on', 'void_reason',
        'cost_impact', 'deduct_from_payment', 'deduction_amount', 'deduction_certificate_id', 'back_charge_id',
        'notes',
    ];

    protected $casts = [
        'raised_on' => 'date',
        'dispositioned_on' => 'date',
        'corrective_due_on' => 'date',
        'corrective_done_on' => 'date',
        'preventive_due_on' => 'date',
        'preventive_done_on' => 'date',
        'verified_on' => 'date',
        'closed_on' => 'date',
        'deduct_from_payment' => 'boolean',
    ];

    protected $attributes = [
        'status' => self::STATUS_OPEN,
        'severity' => self::SEVERITY_MINOR,
        'source' => 'inspection',
        'deduct_from_payment' => false,
    ];

    protected static function booted(): void
    {
        static::creating(function (self $ncr): void {
            $ncr->raised_by ??= auth()->id();
        });
    }

    public function job(): BelongsTo
    {
        return $this->belongsTo(Job::class, 'job_id');
    }

    /** The inspection that found it. */
    public function inspection(): BelongsTo
    {
        return $this->belongsTo(Inspection::class, 'inspection_id');
    }

    /** **The traceability back to the ITP row the standard asks for.** */
    public function itpActivity(): BelongsTo
    {
        return $this->belongsTo(ItpActivity::class, 'itp_activity_id');
    }

    /** The re-inspection that closed it — what makes a closure evidence rather than an assertion. */
    public function verificationInspection(): BelongsTo
    {
        return $this->belongsTo(Inspection::class, 'verification_inspection_id');
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class, 'location_id');
    }

    public function wbsNode(): BelongsTo
    {
        return $this->belongsTo(WbsNode::class, 'wbs_node_id');
    }

    /** Who has to put it right. Guarded: Invoicing owns contacts and this module does not require it. */
    public function responsible(): BelongsTo
    {
        return $this->belongsTo(Contact::class, 'responsible_contact_id');
    }

    public function scopeForJobTree(Builder $query, Job $root): Builder
    {
        return $query->whereIn('job_id', Job::query()->inSubtree($root)->select('id'));
    }

    public function scopeLive(Builder $query): Builder
    {
        return $query->whereIn('status', self::LIVE);
    }

    public function scopeCritical(Builder $query): Builder
    {
        return $query->where('severity', self::SEVERITY_CRITICAL);
    }

    /** Raised and not yet dispositioned — the commercially significant question nobody has answered. */
    public function scopeAwaitingDisposition(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_OPEN)->whereNull('disposition');
    }

    /**
     * **Proposed deductions no certificate has taken up** — the queue §17.2's offer reads.
     *
     * A live NCR proposing a withholding that nobody has put on a certificate. Deliberately excludes closed and void
     * ones: an NCR that has been put right has nothing left to withhold against.
     */
    public function scopeProposingDeduction(Builder $query): Builder
    {
        return $query->live()
            ->where('deduct_from_payment', true)
            ->whereNull('deduction_certificate_id')
            ->whereNotNull('deduction_amount');
    }

    public function isLive(): bool
    {
        return in_array($this->status, self::LIVE, true);
    }

    public function isClosed(): bool
    {
        return in_array($this->status, [self::STATUS_CLOSED, self::STATUS_VOID], true);
    }

    public function isDispositioned(): bool
    {
        return $this->disposition !== null;
    }

    public function isVerified(): bool
    {
        return $this->verified_on !== null;
    }

    /**
     * A disposition that accepts nonconforming work, which is the client giving something up.
     *
     * Normally paid for with a reduction — hence the question the register asks next.
     */
    public function acceptsNonconformingWork(): bool
    {
        return in_array($this->disposition, self::COMMERCIAL_DISPOSITIONS, true);
    }

    /**
     * **A proposed deduction, not an applied one.**
     *
     * True while somebody has said this should be withheld and no certificate has taken it up. Nothing about this
     * property withholds anything: the certification service offers it and a human signs.
     */
    public function proposesDeduction(): bool
    {
        return $this->isLive()
            && $this->deduct_from_payment
            && $this->deduction_certificate_id === null
            && $this->deduction_amount !== null;
    }

    /** Whether a human has actually put the proposal on a certificate. */
    public function deductionWasTaken(): bool
    {
        return $this->deduction_certificate_id !== null;
    }

    /**
     * **Accepted nonconforming work with no reduction proposed** — the exposure this register carries.
     *
     * *Use as is* and *concession requested* are the client accepting something less than the specification. Where
     * nobody has proposed a figure against that, the contractor has been given a concession and the client has been
     * given nothing — money left on the table with nothing wrong anywhere.
     */
    public function acceptedWithoutReduction(): bool
    {
        return $this->acceptsNonconformingWork()
            && ! $this->deduct_from_payment
            && $this->back_charge_id === null;
    }

    public function correctiveOverdue(?string $asAt = null): bool
    {
        return $this->corrective_due_on !== null
            && $this->corrective_done_on === null
            && $this->corrective_due_on->lt(Carbon::parse($asAt ?? now())->startOfDay());
    }

    public function preventiveOverdue(?string $asAt = null): bool
    {
        return $this->preventive_due_on !== null
            && $this->preventive_done_on === null
            && $this->preventive_due_on->lt(Carbon::parse($asAt ?? now())->startOfDay());
    }

    /**
     * **Preventive action still outstanding after the corrective action is done** — the commonest CAPA failure.
     *
     * The pour gets fixed and the reason it happened does not, which is exactly why §17.2 keeps the two pairs of fields
     * apart rather than letting one field cover both.
     */
    public function fixedButNotPrevented(): bool
    {
        return $this->corrective_done_on !== null
            && filled($this->preventive_action)
            && $this->preventive_done_on === null;
    }

    /** Days open, computed — to the closure where there is one, to today where there is not. */
    public function daysOpen(?string $asAt = null): int
    {
        $end = $this->closed_on ?? Carbon::parse($asAt ?? now())->startOfDay();

        return (int) $this->raised_on->diffInDays($end, absolute: false);
    }

    public function responsibleName(): string
    {
        return $this->responsible_label
            ?? (modules()->enabled('invoicing') ? $this->responsible?->name : null)
            ?? 'Not assigned';
    }

    public function dispositionLabel(): ?string
    {
        return $this->disposition === null ? null : (self::DISPOSITIONS[$this->disposition] ?? $this->disposition);
    }

    public function displayName(): string
    {
        return "{$this->ncr_number} — ".str($this->description)->limit(50);
    }
}
