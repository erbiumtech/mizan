<?php

namespace App\Modules\ConstructionField\Models;

use App\Models\TenantModel as Model;
use App\Modules\Invoicing\Models\Contact;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One round of review — `docs/construction-management-plan.md` §16.3.
 *
 * **A row per round, because a status column cannot hold this.** §16.3: "a submittal that has been round three times is
 * a schedule risk, and a single status column loses that fact completely." Round one is planned for. Rounds two and
 * three spend the float between the reviewer's return and the fabricator's start, and every individual step still looks
 * reasonable while the fabrication date is missed.
 *
 * **The overrun on this row is the only claimable part of a submittal being late.** The contractor submits, so its own
 * lateness is a risk it owns — but a reviewer who kept a drawing thirty days against a fourteen-day period has taken
 * sixteen days of somebody else's programme, and that is what `raiseDelay()` on the service notifies.
 *
 * `review_period_days` is **snapshotted onto the row** rather than read from the submittal, for the same reason §8
 * freezes a certificate's retention terms: the contract's review period can be renegotiated, and a round whose overrun
 * was computed against fourteen days must keep saying fourteen. Recomputing against a later figure would silently
 * retire an entitlement somebody has already relied on.
 */
class SubmittalReview extends Model
{
    public const RESULT_APPROVED = 'approved';

    public const RESULT_APPROVED_AS_NOTED = 'approved_as_noted';

    public const RESULT_REVISE_AND_RESUBMIT = 'revise_and_resubmit';

    public const RESULT_REJECTED = 'rejected';

    public const RESULT_FOR_RECORD = 'for_record';

    /** @var array<string, string> */
    public const RESULTS = [
        self::RESULT_APPROVED => 'Approved',
        self::RESULT_APPROVED_AS_NOTED => 'Approved as noted',
        self::RESULT_REVISE_AND_RESUBMIT => 'Revise and resubmit',
        self::RESULT_REJECTED => 'Rejected',
        self::RESULT_FOR_RECORD => 'For record only',
    ];

    /**
     * The results that send it back round — the ones that cost a round nobody budgeted for.
     *
     * `rejected` is here as well as `revise_and_resubmit` because in practice both mean "submit again"; the difference
     * is tone, and a register that treated a rejection as final would show a job permanently blocked on an item the
     * subcontractor is already redrawing.
     */
    public const SENDS_IT_BACK = [self::RESULT_REVISE_AND_RESUBMIT, self::RESULT_REJECTED];

    protected $table = 'construction_submittal_reviews';

    protected $fillable = [
        'submittal_id', 'round', 'revision', 'reviewer_contact_id', 'reviewer_label',
        'sent_on', 'returned_on', 'review_period_days', 'result', 'comments', 'delay_event_id',
    ];

    protected $casts = [
        'sent_on' => 'date',
        'returned_on' => 'date',
        'round' => 'integer',
        'review_period_days' => 'integer',
    ];

    public function submittal(): BelongsTo
    {
        return $this->belongsTo(Submittal::class, 'submittal_id');
    }

    /** Who reviewed it. Guarded like every other contact in this module. */
    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(Contact::class, 'reviewer_contact_id');
    }

    public function delayEvent(): BelongsTo
    {
        return $this->belongsTo(DelayEvent::class, 'delay_event_id');
    }

    /**
     * Rounds that are out, or have come back.
     *
     * The overrun itself is **not** a scope, deliberately: the arithmetic is a date difference against a per-row period,
     * and expressing that in SQL means a dialect-specific date function — `julianday` on SQLite, `DATEDIFF` on MySQL.
     * This suite tests on one and ships on the other, so a query that worked would have been a query that worked in
     * tests. The register's overrun list filters in PHP over `sent`/`returned` rounds instead, which is a page of rows
     * rather than a table scan because both scopes are indexed.
     */
    public function scopeSent(Builder $query): Builder
    {
        return $query->whereNotNull('sent_on');
    }

    public function scopeReturned(Builder $query): Builder
    {
        return $query->whereNotNull('returned_on');
    }

    public function isReturned(): bool
    {
        return $this->returned_on !== null;
    }

    /**
     * Days the reviewer took — to the return where there is one, to today where there is not.
     *
     * Counted to today while it is still out, because a review sitting on somebody's desk for forty days is the fact
     * worth reporting and a null would hide it until it came back.
     */
    public function turnaroundDays(?string $asAt = null): int
    {
        $end = $this->returned_on ?? Carbon::parse($asAt ?? now())->startOfDay();

        return max(0, (int) $this->sent_on->diffInDays($end, absolute: false));
    }

    /**
     * Days beyond the period this round was measured against — the claimable figure.
     *
     * Zero rather than negative when it came back early: a report summing this column wants the loss, and "returned
     * four days early" is not an entitlement.
     */
    public function overrunDays(?string $asAt = null): int
    {
        return max(0, $this->turnaroundDays($asAt) - (int) $this->review_period_days);
    }

    public function hasOverrun(?string $asAt = null): bool
    {
        return $this->overrunDays($asAt) > 0;
    }

    /** An overrun with no notice behind it — the same silence, in the review chain. */
    public function overrunUnnotified(?string $asAt = null): bool
    {
        return $this->hasOverrun($asAt) && $this->delay_event_id === null;
    }

    /**
     * The day the review period expired, which is the day a delay caused by this round began.
     *
     * Not the day it came back, and not today: the notice period runs from the event, and the event is the reviewer
     * passing their own deadline.
     */
    public function overrunStartedOn(): string
    {
        return $this->sent_on->copy()->addDays((int) $this->review_period_days)->toDateString();
    }

    public function sendsItBack(): bool
    {
        return in_array($this->result, self::SENDS_IT_BACK, true);
    }

    public function reviewerName(): string
    {
        return $this->reviewer_label
            ?? (modules()->enabled('invoicing') ? $this->reviewer?->name : null)
            ?? 'Reviewer not named';
    }

    public function resultLabel(): ?string
    {
        return $this->result === null ? null : (self::RESULTS[$this->result] ?? $this->result);
    }
}
