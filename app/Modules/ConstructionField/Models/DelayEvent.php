<?php

namespace App\Modules\ConstructionField\Models;

use App\Models\TenantModel as Model;
use App\Modules\Construction\Models\Job;
use App\Traits\Auditable;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Something that delayed the job, and the clock that decides whether it is still claimable — §13.
 *
 * **The clock is the point of this model.** §13: *"A due-date with a notification attached is worth more commercially
 * than the entire programme: a valid claim lost to a missed notice is the single most common way a contractor donates
 * money, and it fails in absolute silence."* Everything else here — the categories, the claimed and awarded days, the
 * determination — is administration around that one date.
 *
 * **`notice_required_by` is stored and `isTimeBarred()` is computed.** The date is a snapshot of a contractual period as
 * it stood when the event was raised, so editing the contract's notice period next month cannot silently move a deadline
 * somebody has already been warned about — the same reasoning §8 gives for freezing a certificate. Whether the bar has
 * fallen is derived from the dates against the date being asked about, which is §12's rule for compliance and holds
 * identically: a stored "time-barred" flag is a flag that stops agreeing with the dates underneath it.
 *
 * **Nothing here decides concurrency.** `concurrent_with_delay_event_id` records that two events overlapped, because
 * §13 says that is "the whole argument in most extension-of-time disputes" — and what overlapping *means* for
 * entitlement depends on the contract and the jurisdiction, so it stays a fact on a row rather than a rule in code.
 */
class DelayEvent extends Model
{
    use Auditable;

    public const STATUS_OPEN = 'open';

    public const STATUS_NOTIFIED = 'notified';

    public const STATUS_PARTICULARS_SUBMITTED = 'particulars_submitted';

    public const STATUS_UNDER_ASSESSMENT = 'under_assessment';

    public const STATUS_DETERMINED = 'determined';

    public const STATUS_REJECTED = 'rejected';

    public const STATUS_WITHDRAWN = 'withdrawn';

    /**
     * Who bears the risk, which is the first thing an assessment turns on.
     *
     * @var array<string, string>
     */
    public const CAUSES = [
        'employer_risk' => 'Employer risk',
        'contractor_risk' => 'Contractor risk',
        'neutral' => 'Neutral event',
        'force_majeure' => 'Force majeure',
        'weather' => 'Exceptional weather',
        'variation' => 'Variation or instruction',
        'late_information' => 'Late information or drawings',
        'access' => 'Access not given',
        'utility' => 'Utility or service delay',
        'statutory' => 'Statutory or authority delay',
        'strike' => 'Strike or labour dispute',
        'unforeseen_conditions' => 'Unforeseen physical conditions',
    ];

    /**
     * The days before the notice is due at which somebody is warned, and once per threshold.
     *
     * The same ladder §12's compliance register uses, and for the same reason: a single warning is a warning that lands
     * while the person who can act on it is on leave. The tightest is one day, which is why the run is daily.
     *
     * @var array<int, int>
     */
    public const WARNING_THRESHOLDS = [14, 7, 3, 1, 0];

    /** The statuses at which the notice clock still matters — after notice, the clock has stopped. */
    public const AWAITING_NOTICE = [self::STATUS_OPEN];

    protected $table = 'construction_delay_events';

    protected $fillable = [
        'job_id', 'contract_id', 'reference', 'title', 'description', 'cause_category',
        'occurred_on', 'notice_required_by', 'notice_days', 'notice_given_on', 'notice_document_id',
        'particulars_due_by', 'particulars_submitted_on',
        'claimed_days', 'awarded_days', 'cost_claimed', 'cost_awarded', 'status',
        'determined_on', 'determined_by', 'determination_reason', 'variation_id',
        'concurrent_with_delay_event_id', 'notes', 'notice_notified_at_days', 'created_by',
    ];

    protected $casts = [
        'occurred_on' => 'date',
        'notice_required_by' => 'date',
        'notice_given_on' => 'date',
        'particulars_due_by' => 'date',
        'particulars_submitted_on' => 'date',
        'determined_on' => 'date',
        'notice_days' => 'integer',
        'notice_notified_at_days' => 'integer',
    ];

    protected $attributes = [
        'status' => self::STATUS_OPEN,
    ];

    protected static function booted(): void
    {
        static::creating(function (self $event): void {
            $event->created_by ??= auth()->id();
        });
    }

    public function job(): BelongsTo
    {
        return $this->belongsTo(Job::class, 'job_id');
    }

    /** The event this one ran concurrently with, which §13 says is the whole argument in most disputes. */
    public function concurrentWith(): BelongsTo
    {
        return $this->belongsTo(self::class, 'concurrent_with_delay_event_id');
    }

    /** Events still waiting for a notice to be served — what the nightly run watches. */
    public function scopeAwaitingNotice(Builder $query): Builder
    {
        return $query->whereIn('status', self::AWAITING_NOTICE)->whereNull('notice_given_on');
    }

    public function scopeForJobTree(Builder $query, Job $root): Builder
    {
        return $query->whereIn('job_id', Job::query()->inSubtree($root)->select('id'));
    }

    public function noticeGiven(): bool
    {
        return $this->notice_given_on !== null;
    }

    /**
     * Days until the notice is due, **signed**.
     *
     * Signed rather than clamped, copying `EmployeeDocument::daysUntilExpiry()` by way of §12's register: "expired forty
     * days ago is a different problem from expires in forty days", and a clamped zero makes the two look identical at
     * exactly the moment the difference matters most.
     */
    public function daysUntilNoticeDue(?string $asAt = null): ?int
    {
        if ($this->notice_required_by === null) {
            return null;
        }

        $today = Carbon::parse($asAt ?? now())->startOfDay();

        // Cast explicitly: Carbon 3 returns a float, and both ends are start-of-day so nothing is lost.
        return (int) round($today->diffInDays($this->notice_required_by->copy()->startOfDay(), false));
    }

    /**
     * Whether the window has closed with no notice served — **computed, never stored** (§13).
     *
     * Judged as at a date, so "was this claimable when we looked at it in June" stays answerable. An event that *was*
     * notified is never barred however late the notice was: whether a late notice is good enough is a contractual
     * argument, and this method does not have an opinion on it — `noticeWasLate()` states the fact instead.
     */
    public function isTimeBarred(?string $asAt = null): bool
    {
        if ($this->noticeGiven() || $this->notice_required_by === null) {
            return false;
        }

        if (in_array($this->status, [self::STATUS_WITHDRAWN, self::STATUS_REJECTED], true)) {
            return false;
        }

        return Carbon::parse($asAt ?? now())->startOfDay()->gt($this->notice_required_by->copy()->startOfDay());
    }

    /**
     * Notice served, but after the window closed.
     *
     * A fact rather than a verdict: most contracts make the bar conditional on prejudice, waiver or the certifier's
     * discretion, so the row records that it was late and leaves the argument to the people having it.
     */
    public function noticeWasLate(): bool
    {
        return $this->noticeGiven()
            && $this->notice_required_by !== null
            && $this->notice_given_on->copy()->startOfDay()->gt($this->notice_required_by->copy()->startOfDay());
    }

    public function isDetermined(): bool
    {
        return $this->status === self::STATUS_DETERMINED;
    }

    public function isClosed(): bool
    {
        return in_array($this->status, [
            self::STATUS_DETERMINED, self::STATUS_REJECTED, self::STATUS_WITHDRAWN,
        ], true);
    }

    /** The tightest warning threshold this event has reached, or null when it is not near one yet. */
    public function warningThreshold(?string $asAt = null): ?int
    {
        $days = $this->daysUntilNoticeDue($asAt);

        if ($days === null) {
            return null;
        }

        /*
         * Ascending, so the **tightest** threshold reached wins.
         *
         * Read in the declared (descending) order, five days out would return 14 — a warning already sent — and the
         * seven-day one would never fire. Overdue days are negative, so they fall through to 0 and keep warning.
         */
        foreach (array_reverse(self::WARNING_THRESHOLDS) as $threshold) {
            if ($days <= $threshold) {
                return $threshold;
            }
        }

        return null;
    }

    public function causeLabel(): string
    {
        return self::CAUSES[$this->cause_category] ?? $this->cause_category;
    }

    public function displayName(): string
    {
        return "{$this->reference} — {$this->title}";
    }
}
