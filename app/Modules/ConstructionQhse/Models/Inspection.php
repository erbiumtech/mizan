<?php

namespace App\Modules\ConstructionQhse\Models;

use App\Models\TenantModel as Model;
use App\Modules\Construction\Models\Job;
use App\Modules\Construction\Models\Location;
use App\Modules\Invoicing\Models\Contact;
use App\Traits\Auditable;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One inspection — `docs/construction-management-plan.md` §17.1.
 *
 * **The point type is snapshotted here, not read from the plan.** An ITP gets revised and a hold point becomes a witness
 * point; an inspection carried out under the old plan was carried out under the old rules. Reading the current plan
 * would retroactively change what an inspection *meant*, which is the failure §8's certificate snapshots and §13's
 * notice-day snapshot both exist to prevent. `notice_hours` travels with it for the same reason.
 *
 * **Two properties on this model carry the commercial weight.**
 *
 * *A passed hold point that has not been released is work standing still.* §17.1: "the whole function of a hold point is
 * that work may not proceed past it; a hold point that releases nothing and blocks nothing is a checkbox with extra
 * steps." So release is its own act with its own permission, and `awaitingRelease()` is the query a site manager needs
 * every morning.
 *
 * *A witness point where the party was invited and did not attend is the contractor's protection.* §17.1: work may
 * proceed. That is only true if the register can show they were told and did not come — which is why
 * `witness_attended` is a column rather than inferred from a name being filled in, and why `notified_on` is separate
 * from `requested_on`: a notice period runs from when the other party was told.
 */
class Inspection extends Model
{
    use Auditable;

    public const STATUS_REQUESTED = 'requested';

    public const STATUS_SCHEDULED = 'scheduled';

    public const STATUS_PASSED = 'passed';

    public const STATUS_PASSED_WITH_COMMENTS = 'passed_with_comments';

    public const STATUS_FAILED = 'failed';

    public const STATUS_CANCELLED = 'cancelled';

    /** @var array<string, string> */
    public const STATUSES = [
        self::STATUS_REQUESTED => 'Requested',
        self::STATUS_SCHEDULED => 'Scheduled',
        self::STATUS_PASSED => 'Passed',
        self::STATUS_PASSED_WITH_COMMENTS => 'Passed with comments',
        self::STATUS_FAILED => 'Failed',
        self::STATUS_CANCELLED => 'Cancelled',
    ];

    /**
     * The outcomes that let work proceed.
     *
     * **Passed-with-comments counts**, and that is a real decision: it means "acceptable, with these observations", the
     * next operation starts, and treating it as a failure would show a job blocked on inspections everybody involved
     * considers closed. The comments become §17.4's actions, not a stoppage.
     */
    public const ACCEPTED = [self::STATUS_PASSED, self::STATUS_PASSED_WITH_COMMENTS];

    /** Not yet carried out. */
    public const OPEN = [self::STATUS_REQUESTED, self::STATUS_SCHEDULED];

    protected $table = 'construction_inspections';

    protected $fillable = [
        'job_id', 'itp_activity_id', 'reference', 'location_id', 'activity_description',
        'contract_item_id', 'activity_id', 'point_type', 'notice_hours',
        'requested_on', 'requested_by', 'notified_on', 'scheduled_for', 'inspected_on', 'status',
        'inspected_by', 'witness_contact_id', 'witness_label', 'witness_attended',
        'result_notes', 'ncr_id', 'released_hold_point', 'released_by', 'released_at', 'release_notes',
    ];

    protected $casts = [
        'requested_on' => 'date',
        'notified_on' => 'date',
        'scheduled_for' => 'date',
        'inspected_on' => 'date',
        'released_at' => 'datetime',
        'witness_attended' => 'boolean',
        'released_hold_point' => 'boolean',
        'notice_hours' => 'integer',
    ];

    protected $attributes = [
        'status' => self::STATUS_REQUESTED,
        'point_type' => ItpActivity::POINT_REVIEW,
        'witness_attended' => false,
        'released_hold_point' => false,
    ];

    protected static function booted(): void
    {
        static::creating(function (self $inspection): void {
            $inspection->requested_by ??= auth()->id();
        });
    }

    public function job(): BelongsTo
    {
        return $this->belongsTo(Job::class, 'job_id');
    }

    /** The plan row this came from — null for an ad-hoc inspection, which §17.1 requires to be possible. */
    public function itpActivity(): BelongsTo
    {
        return $this->belongsTo(ItpActivity::class, 'itp_activity_id');
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class, 'location_id');
    }

    /** Who witnessed it. Guarded: Invoicing owns contacts, and this module does not require it. */
    public function witness(): BelongsTo
    {
        return $this->belongsTo(Contact::class, 'witness_contact_id');
    }

    public function checks(): HasMany
    {
        return $this->hasMany(InspectionCheck::class, 'inspection_id')->orderBy('sequence');
    }

    public function scopeForJobTree(Builder $query, Job $root): Builder
    {
        return $query->whereIn('job_id', Job::query()->inSubtree($root)->select('id'));
    }

    public function scopeOpen(Builder $query): Builder
    {
        return $query->whereIn('status', self::OPEN);
    }

    public function scopeHoldPoints(Builder $query): Builder
    {
        return $query->where('point_type', ItpActivity::POINT_HOLD);
    }

    /**
     * **Hold points that passed and have not been released** — work standing still with nothing wrong anywhere.
     *
     * The register's load-bearing query, and it is a plain indexed one: accepted, a hold point, not released.
     */
    public function scopeAwaitingRelease(Builder $query): Builder
    {
        return $query->holdPoints()
            ->whereIn('status', self::ACCEPTED)
            ->where('released_hold_point', false);
    }

    public function isHoldPoint(): bool
    {
        return $this->point_type === ItpActivity::POINT_HOLD;
    }

    public function isWitnessPoint(): bool
    {
        return $this->point_type === ItpActivity::POINT_WITNESS;
    }

    public function isOpen(): bool
    {
        return in_array($this->status, self::OPEN, true);
    }

    public function wasAccepted(): bool
    {
        return in_array($this->status, self::ACCEPTED, true);
    }

    public function hasFailed(): bool
    {
        return $this->status === self::STATUS_FAILED;
    }

    public function isAdHoc(): bool
    {
        return $this->itp_activity_id === null;
    }

    /** **Work may not proceed**: a hold point that has not been released, whatever its result. */
    public function blocksWork(): bool
    {
        return $this->isHoldPoint()
            && ! $this->released_hold_point
            && $this->status !== self::STATUS_CANCELLED;
    }

    /** Passed, is a hold point, and nobody has authorised the next operation. */
    public function awaitingRelease(): bool
    {
        return $this->isHoldPoint() && $this->wasAccepted() && ! $this->released_hold_point;
    }

    /**
     * **Invited and did not attend** — the fact a witness point is defined by.
     *
     * §17.1: at a witness point "work may proceed if they do not attend". True only if the register can show they were
     * told, which is what `notified_on` is for.
     */
    public function witnessFailedToAttend(): bool
    {
        return $this->isWitnessPoint()
            && $this->notified_on !== null
            && $this->inspected_on !== null
            && ! $this->witness_attended;
    }

    /**
     * Whether the notice period this point requires was actually given.
     *
     * Null where either the period or the notice is missing — an honest "cannot tell" rather than a false accusation.
     * Notice is measured from when the party was *told* to when the inspection happened, in the hours the ITP row set.
     */
    public function noticeGivenInFull(): ?bool
    {
        if ($this->notice_hours === null || $this->notified_on === null || $this->inspected_on === null) {
            return null;
        }

        return $this->notified_on->diffInHours($this->inspected_on, absolute: false) >= $this->notice_hours;
    }

    /** How long the release has been outstanding, which is how long work has been standing still. */
    public function daysAwaitingRelease(?string $asAt = null): ?int
    {
        if (! $this->awaitingRelease() || $this->inspected_on === null) {
            return null;
        }

        return (int) $this->inspected_on->diffInDays(Carbon::parse($asAt ?? now())->startOfDay(), absolute: false);
    }

    /** Every check that failed — what an NCR is raised from. */
    public function failedChecks(): \Illuminate\Support\Collection
    {
        return $this->checks->filter(fn (InspectionCheck $check): bool => $check->passed === false)->values();
    }

    public function witnessName(): string
    {
        return $this->witness_label
            ?? (modules()->enabled('invoicing') ? $this->witness?->name : null)
            ?? 'No witness named';
    }

    public function pointLabel(): string
    {
        return ItpActivity::POINT_TYPES[$this->point_type] ?? $this->point_type;
    }

    public function displayName(): string
    {
        return "{$this->reference} — ".str($this->activity_description)->limit(50);
    }
}
