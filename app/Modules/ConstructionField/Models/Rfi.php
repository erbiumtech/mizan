<?php

namespace App\Modules\ConstructionField\Models;

use App\Models\TenantModel as Model;
use App\Modules\Construction\Models\Document;
use App\Modules\Construction\Models\Job;
use App\Modules\Construction\Models\Location;
use App\Modules\Invoicing\Models\Contact;
use App\Traits\Auditable;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One request for information — `docs/construction-management-plan.md` §16.2.
 *
 * **Every time figure on this register is computed, none of them stored**, which is §16.2's closing line: "days open,
 * overdue and response time are all computed". A stored days-open is wrong by one every midnight, and a stored overdue
 * flag is wrong from the moment somebody moves the required-by date.
 *
 * Three properties carry the register:
 *
 *  - **`ball_in_court` is a role and a person, both.** The role is what "seventeen RFIs sitting with the Architect"
 *    counts; the contact is who the chasing email goes to. Over two years the individual changes and the role does not,
 *    so deriving either from the other loses a question somebody asks.
 *  - **Impact is a flag with an estimate beside it.** `possible` means somebody looked and cannot yet say — a different
 *    fact from `none`, and the distinction §16.2 refuses to collapse into a column of zeros.
 *  - **A time impact with no delay event behind it is money already lost.** Late information is a cause category on
 *    §13's event and an RFI is where such a delay is first written down; the notice period is running from the day the
 *    answer was needed, and nothing else in the application is watching this one.
 */
class Rfi extends Model
{
    use Auditable;

    public const STATUS_DRAFT = 'draft';

    public const STATUS_OPEN = 'open';

    public const STATUS_ANSWERED = 'answered';

    public const STATUS_CLOSED = 'closed';

    public const STATUS_CANCELLED = 'cancelled';

    /** @var array<string, string> */
    public const STATUSES = [
        self::STATUS_DRAFT => 'Draft',
        self::STATUS_OPEN => 'Open',
        self::STATUS_ANSWERED => 'Answered',
        self::STATUS_CLOSED => 'Closed',
        self::STATUS_CANCELLED => 'Cancelled',
    ];

    /** The statuses at which somebody on the other side owes an answer — what a chase list is made of. */
    public const AWAITING_ANSWER = [self::STATUS_DRAFT, self::STATUS_OPEN];

    /**
     * Whose court the ball is in, as a **role**.
     *
     * @var array<string, string>
     */
    public const COURTS = [
        'architect' => 'Architect',
        'engineer' => 'Engineer',
        'employer' => 'Employer',
        'consultant' => 'Consultant',
        'contractor' => 'Contractor',
        'subcontractor' => 'Subcontractor',
        'supplier' => 'Supplier',
        'other' => 'Other',
    ];

    public const IMPACT_NONE = 'none';

    public const IMPACT_POSSIBLE = 'possible';

    public const IMPACT_YES = 'yes';

    /**
     * @var array<string, string>
     *
     * `possible` reads as "looked at, cannot yet say" on purpose. §16.2 is written against the alternative: forcing a
     * number at raise time makes a column of zeros that later reads as "no impact" when it meant "not yet assessed".
     */
    public const IMPACTS = [
        self::IMPACT_NONE => 'None',
        self::IMPACT_POSSIBLE => 'Possible',
        self::IMPACT_YES => 'Yes',
    ];

    protected $table = 'construction_rfis';

    protected $fillable = [
        'job_id', 'contract_id', 'rfi_number', 'subject', 'question', 'proposed_solution', 'discipline',
        'location_id', 'drawing_document_id', 'specification_reference', 'activity_id',
        'raised_by', 'raised_on', 'required_by',
        'ball_in_court', 'ball_in_court_contact_id', 'status',
        'cost_impact_flag', 'cost_impact_estimate', 'time_impact_flag', 'time_impact_days',
        'answer', 'answered_on', 'answered_by_contact_id', 'answer_recorded_by', 'answer_document_id',
        'closed_on', 'closed_by', 'cancel_reason', 'variation_id', 'delay_event_id',
    ];

    protected $casts = [
        'raised_on' => 'date',
        'required_by' => 'date',
        'answered_on' => 'date',
        'closed_on' => 'date',
        'time_impact_days' => 'integer',
    ];

    protected $attributes = [
        'status' => self::STATUS_OPEN,
        'ball_in_court' => 'architect',
        'cost_impact_flag' => self::IMPACT_NONE,
        'time_impact_flag' => self::IMPACT_NONE,
    ];

    protected static function booted(): void
    {
        static::creating(function (self $rfi): void {
            $rfi->raised_by ??= auth()->id();
        });

        /*
         * **The register has no gaps** (§16.2), which is a rule about deletion.
         *
         * An RFI register is read sequentially and quoted in correspondence. "Where is RFI 14?" must never be
         * answerable with "somebody deleted it", so cancellation is the only way out and the number stays used.
         */
        static::deleting(function (self $rfi): void {
            throw new \InvalidArgumentException(
                "{$rfi->rfi_number} cannot be deleted. The register is numbered without gaps because it is read and "
                .'quoted sequentially — cancel it with a reason instead, and the number stays used.'
            );
        });
    }

    public function job(): BelongsTo
    {
        return $this->belongsTo(Job::class, 'job_id');
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class, 'location_id');
    }

    /** The drawing the question is about — §15's register, in the spine. */
    public function drawing(): BelongsTo
    {
        return $this->belongsTo(Document::class, 'drawing_document_id');
    }

    public function answerDocument(): BelongsTo
    {
        return $this->belongsTo(Document::class, 'answer_document_id');
    }

    /**
     * The named individual the ball sits with.
     *
     * A guarded coupling: Contacts belong to Invoicing and this module requires only `construction`, so without it the
     * column stays null and `ball_in_court` — the role — carries the register on its own. The report still works; only
     * the chasing email loses its address.
     */
    public function ballInCourtContact(): BelongsTo
    {
        return $this->belongsTo(Contact::class, 'ball_in_court_contact_id');
    }

    public function answeredByContact(): BelongsTo
    {
        return $this->belongsTo(Contact::class, 'answered_by_contact_id');
    }

    /**
     * The programme activity this question blocks — §16.2's `activity_id`, wired in Phase 9g.
     *
     * An RFI against a dated activity is assessable; one against nothing is a complaint.
     */
    public function activity(): BelongsTo
    {
        return $this->belongsTo(ProgrammeActivity::class, 'activity_id');
    }

    /** The notice raised for this RFI's time impact, where somebody raised one. */
    public function delayEvent(): BelongsTo
    {
        return $this->belongsTo(DelayEvent::class, 'delay_event_id');
    }

    public function scopeForJobTree(Builder $query, Job $root): Builder
    {
        return $query->whereIn('job_id', Job::query()->inSubtree($root)->select('id'));
    }

    /** Somebody owes an answer. */
    public function scopeAwaitingAnswer(Builder $query): Builder
    {
        return $query->whereIn('status', self::AWAITING_ANSWER);
    }

    public function scopeLive(Builder $query): Builder
    {
        return $query->whereNotIn('status', [self::STATUS_CLOSED, self::STATUS_CANCELLED]);
    }

    /**
     * Past its required-by date with no answer.
     *
     * `whereDate` because `required_by` is `date`-cast and therefore stored with a time — the third place in this suite
     * that has mattered, after `CostPeriod::scopeStarting()` and the labour rate ladder.
     */
    public function scopeOverdue(Builder $query, ?string $asAt = null): Builder
    {
        return $query->awaitingAnswer()
            ->whereNotNull('required_by')
            ->whereDate('required_by', '<', Carbon::parse($asAt ?? now())->toDateString());
    }

    /**
     * A stated time impact with no delay event behind it.
     *
     * The exposure. `yes` only, not `possible`: a possible impact is a question somebody is still assessing, and
     * raising a notice for every one of those is how a notice register becomes noise nobody reads.
     */
    public function scopeUnnotifiedTimeImpact(Builder $query): Builder
    {
        return $query->where('time_impact_flag', self::IMPACT_YES)
            ->whereNull('delay_event_id')
            ->whereNotIn('status', [self::STATUS_CANCELLED]);
    }

    public function isAnswered(): bool
    {
        return $this->answered_on !== null;
    }

    public function isClosed(): bool
    {
        return in_array($this->status, [self::STATUS_CLOSED, self::STATUS_CANCELLED], true);
    }

    public function isAwaitingAnswer(): bool
    {
        return in_array($this->status, self::AWAITING_ANSWER, true);
    }

    /**
     * How long it has been open — to the answer where there is one, to today where there is not.
     *
     * Computed, never stored: a stored figure is wrong by one every midnight.
     */
    public function daysOpen(?string $asAt = null): int
    {
        $end = $this->answered_on ?? Carbon::parse($asAt ?? now());

        return (int) $this->raised_on->diffInDays($end, absolute: false);
    }

    /**
     * What the other side took to answer — null while they have not.
     *
     * Null rather than the days so far, deliberately: an unanswered RFI has no response time, and reporting one would
     * put a flattering average in front of somebody negotiating about lateness.
     */
    public function responseDays(): ?int
    {
        return $this->isAnswered()
            ? (int) $this->raised_on->diffInDays($this->answered_on, absolute: false)
            : null;
    }

    public function isOverdue(?string $asAt = null): bool
    {
        if (! $this->isAwaitingAnswer() || $this->required_by === null) {
            return false;
        }

        return $this->required_by->lt(Carbon::parse($asAt ?? now())->startOfDay());
    }

    /** Negative while there is still time, which is what a chase list sorts on. */
    public function daysUntilRequired(?string $asAt = null): ?int
    {
        if ($this->required_by === null) {
            return null;
        }

        return (int) Carbon::parse($asAt ?? now())->startOfDay()->diffInDays($this->required_by, absolute: false);
    }

    /** Answered after the day it was needed — a fact about the other side, kept whether or not anybody claims it. */
    public function answeredLate(): bool
    {
        return $this->isAnswered()
            && $this->required_by !== null
            && $this->answered_on->gt($this->required_by);
    }

    /**
     * A stated impact with no figure against it.
     *
     * §16.2 wants flags at raise time and does not want them to stay flags for ever. `yes` with no estimate four months
     * on is the register's own loose end.
     */
    public function impactUnquantified(): bool
    {
        return ($this->cost_impact_flag === self::IMPACT_YES && $this->cost_impact_estimate === null)
            || ($this->time_impact_flag === self::IMPACT_YES && $this->time_impact_days === null);
    }

    /** A stated delay with nobody notified — the silence §13 exists to prevent, reached from the register. */
    public function timeImpactUnnotified(): bool
    {
        return $this->time_impact_flag === self::IMPACT_YES
            && $this->delay_event_id === null
            && $this->status !== self::STATUS_CANCELLED;
    }

    /**
     * The day a delay caused by this RFI began: the day the answer was needed and did not come.
     *
     * Not the day it was raised — the work was not yet blocked then — and not today, because the notice period runs
     * from the event and dating it now is how a claim is time-barred by its own paperwork.
     */
    public function delayStartedOn(): string
    {
        return ($this->required_by ?? $this->raised_on)->toDateString();
    }

    public function courtLabel(): string
    {
        return self::COURTS[$this->ball_in_court] ?? $this->ball_in_court;
    }

    public function displayName(): string
    {
        return "{$this->rfi_number} — {$this->subject}";
    }
}
