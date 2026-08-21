<?php

namespace App\Modules\ConstructionField\Models;

use App\Models\TenantModel as Model;
use App\Modules\Construction\Models\Document;
use App\Modules\Construction\Models\Job;
use App\Modules\Invoicing\Models\Contact;
use App\Traits\Auditable;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One submittal — `docs/construction-management-plan.md` §16.3.
 *
 * **The submit-by date is computed, and that is the whole register.** §16.3: "the submit-by date is computed by working
 * backwards from the required-on-site date. Typed, it goes stale the day the programme moves, and a stale submit-by date
 * is worse than none." So `submitBy()` subtracts the four durations from the date the thing has to be on site, every
 * time it is asked.
 *
 * The consequence is the finding a real job needs before anything else: **a submittal can be late before anybody has
 * done anything wrong.** Nobody did the subtraction when the programme was agreed, and by the time it is done the date
 * has passed. `daysLate()` is that number, and it is the delay the item will cause unless a lead time is beaten.
 *
 * **Whose lateness it is decides what it is.** The contractor submits, so a submittal late to *submit* is a risk it
 * owns. A reviewer who kept it longer than the contract's review period has taken somebody else's programme — and that
 * is on the round, not here, because it belongs to one round and not to the item.
 */
class Submittal extends Model
{
    use Auditable;

    public const STATUS_PENDING = 'pending';

    public const STATUS_SUBMITTED = 'submitted';

    public const STATUS_UNDER_REVIEW = 'under_review';

    public const STATUS_APPROVED = 'approved';

    public const STATUS_APPROVED_AS_NOTED = 'approved_as_noted';

    public const STATUS_REVISE_AND_RESUBMIT = 'revise_and_resubmit';

    public const STATUS_REJECTED = 'rejected';

    public const STATUS_CLOSED = 'closed';

    /** @var array<string, string> */
    public const STATUSES = [
        self::STATUS_PENDING => 'Not yet submitted',
        self::STATUS_SUBMITTED => 'Submitted',
        self::STATUS_UNDER_REVIEW => 'Under review',
        self::STATUS_APPROVED => 'Approved',
        self::STATUS_APPROVED_AS_NOTED => 'Approved as noted',
        self::STATUS_REVISE_AND_RESUBMIT => 'Revise and resubmit',
        self::STATUS_REJECTED => 'Rejected',
        self::STATUS_CLOSED => 'Closed',
    ];

    /**
     * The statuses at which the item is cleared to be built or bought.
     *
     * **Approved-as-noted counts, and that is a real decision.** It means "build it, with these corrections" — the
     * fabricator starts, so the schedule is released. Treating it as unapproved would show a job blocked on four
     * hundred items that are all actually proceeding.
     */
    public const CLEARED = [self::STATUS_APPROVED, self::STATUS_APPROVED_AS_NOTED, self::STATUS_CLOSED];

    /** @var array<string, string> */
    public const TYPES = [
        'shop_drawing' => 'Shop drawing',
        'product_data' => 'Product data',
        'sample' => 'Sample',
        'mock_up' => 'Mock-up',
        'calculation' => 'Calculation',
        'certificate' => 'Certificate',
        'test_report' => 'Test report',
        'om_manual' => 'O&M manual',
        'warranty' => 'Warranty',
        'as_built' => 'As-built',
        'method_statement' => 'Method statement',
        'material_approval' => 'Material approval',
        'qualification' => 'Qualification',
    ];

    protected $table = 'construction_submittals';

    protected $fillable = [
        'job_id', 'contract_id', 'spec_section', 'title', 'description', 'type',
        'responsible_contact_id', 'responsible_label',
        'required_on_site_date', 'fabrication_lead_days', 'procurement_lead_days',
        'review_period_days', 'buffer_days',
        'submitted_on', 'approved_on', 'status', 'revision', 'document_id',
        'is_long_lead', 'notes', 'created_by',
    ];

    protected $casts = [
        'required_on_site_date' => 'date',
        'submitted_on' => 'date',
        'approved_on' => 'date',
        'fabrication_lead_days' => 'integer',
        'procurement_lead_days' => 'integer',
        'review_period_days' => 'integer',
        'buffer_days' => 'integer',
        'is_long_lead' => 'boolean',
    ];

    protected $attributes = [
        'status' => self::STATUS_PENDING,
        'type' => 'shop_drawing',
        'fabrication_lead_days' => 0,
        'procurement_lead_days' => 0,
        'review_period_days' => 14,
        'buffer_days' => 0,
        'is_long_lead' => false,
    ];

    protected static function booted(): void
    {
        static::creating(function (self $submittal): void {
            $submittal->created_by ??= auth()->id();
        });
    }

    public function job(): BelongsTo
    {
        return $this->belongsTo(Job::class, 'job_id');
    }

    /**
     * Who owes it — a subcontractor or a supplier.
     *
     * Guarded: Contacts belong to Invoicing and this module requires only `construction`, so the column stays null
     * without it and `responsible_label` carries the name. A register that could not be filled in without the books
     * would be no register at all.
     */
    public function responsible(): BelongsTo
    {
        return $this->belongsTo(Contact::class, 'responsible_contact_id');
    }

    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class, 'document_id');
    }

    /** Every round, newest first — §16.3's row per round. */
    public function reviews(): HasMany
    {
        return $this->hasMany(SubmittalReview::class, 'submittal_id')->orderByDesc('round');
    }

    public function scopeForJobTree(Builder $query, Job $root): Builder
    {
        return $query->whereIn('job_id', Job::query()->inSubtree($root)->select('id'));
    }

    /** Not yet cleared to build or buy. */
    public function scopeOutstanding(Builder $query): Builder
    {
        return $query->whereNotIn('status', self::CLEARED);
    }

    public function scopeLongLead(Builder $query): Builder
    {
        return $query->where('is_long_lead', true);
    }

    public function scopeAwaitingSubmission(Builder $query): Builder
    {
        return $query->whereIn('status', [self::STATUS_PENDING, self::STATUS_REVISE_AND_RESUBMIT]);
    }

    /**
     * **The date this has to be submitted by** — computed, never stored (§16.3).
     *
     * Required on site, less the time it takes to make it, buy it, have it reviewed, and the planner's cushion. Null
     * only when the programme has not said when it is needed, which is an honest "cannot tell" rather than today's date
     * dressed up as an answer.
     */
    public function submitBy(): ?Carbon
    {
        if ($this->required_on_site_date === null) {
            return null;
        }

        return $this->required_on_site_date->copy()->subDays($this->leadDays());
    }

    /** The four durations, which are the only thing about this date that is stored. */
    public function leadDays(): int
    {
        return (int) $this->fabrication_lead_days
            + (int) $this->procurement_lead_days
            + (int) $this->review_period_days
            + (int) $this->buffer_days;
    }

    /**
     * Days until it has to be submitted — negative once that date has passed.
     *
     * Negative is the useful state and the reason this register exists: on a job where nobody did the subtraction when
     * the programme was agreed, a great many of these are negative on the day somebody first looks.
     */
    public function daysUntilSubmitBy(?string $asAt = null): ?int
    {
        $by = $this->submitBy();

        if ($by === null) {
            return null;
        }

        return (int) Carbon::parse($asAt ?? now())->startOfDay()->diffInDays($by, absolute: false);
    }

    /**
     * Late to submit, and by how much.
     *
     * Measured to the submission where there is one and to today where there is not, so the figure stops moving once
     * the item is out of the contractor's hands. Zero rather than a negative number when it was in time — "three days
     * early" is not lateness, and a report summing this column wants only the loss.
     */
    public function daysLate(?string $asAt = null): int
    {
        $by = $this->submitBy();

        if ($by === null) {
            return 0;
        }

        $reference = $this->submitted_on ?? Carbon::parse($asAt ?? now())->startOfDay();

        return max(0, (int) $by->diffInDays($reference, absolute: false));
    }

    public function isLateToSubmit(?string $asAt = null): bool
    {
        return $this->submitted_on === null
            && $this->isAwaitingSubmission()
            && $this->daysLate($asAt) > 0;
    }

    public function isAwaitingSubmission(): bool
    {
        return in_array($this->status, [self::STATUS_PENDING, self::STATUS_REVISE_AND_RESUBMIT], true);
    }

    public function isCleared(): bool
    {
        return in_array($this->status, self::CLEARED, true);
    }

    /**
     * How many rounds it has been through — §16.3's schedule risk, as a number.
     *
     * Reads the loaded relation, because every screen that asks this is already showing the rounds.
     */
    public function roundsUsed(): int
    {
        return $this->reviews->count();
    }

    /**
     * More than one round means float nobody planned has been spent.
     *
     * The review period was budgeted once. §16.3: "a submittal that has been round three times is a schedule risk, and
     * a single status column loses that fact completely."
     */
    public function hasResubmitted(): bool
    {
        return $this->roundsUsed() > 1;
    }

    /** The days the reviewer has taken beyond the period, across every round — the claimable part. */
    public function reviewOverrunDays(): int
    {
        return (int) $this->reviews->sum(fn (SubmittalReview $review): int => $review->overrunDays());
    }

    public function latestReview(): ?SubmittalReview
    {
        return $this->reviews->first();
    }

    public function typeLabel(): string
    {
        return self::TYPES[$this->type] ?? $this->type;
    }

    public function responsibleName(): string
    {
        return $this->responsible_label
            ?? (modules()->enabled('invoicing') ? $this->responsible?->name : null)
            ?? 'Not assigned';
    }

    public function displayName(): string
    {
        return "{$this->spec_section} — {$this->title}";
    }
}
