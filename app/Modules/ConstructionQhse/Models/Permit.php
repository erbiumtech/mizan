<?php

namespace App\Modules\ConstructionQhse\Models;

use App\Models\TenantModel as Model;
use App\Modules\Construction\Models\Job;
use App\Modules\Construction\Models\Location;
use App\Traits\Auditable;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;

/**
 * A permit to work — `docs/construction-management-plan.md` §17.5.
 *
 * **"A permit is time-boxed, and an expired-but-open permit is the failure mode that kills people."** Everything about
 * this model follows from that sentence:
 *
 *  - `valid_from` and `valid_to` are **datetimes**. A permit valid "on the 20th" authorises hot work at four in the
 *    morning; one valid until 17:00 does not.
 *  - `isExpiredAndOpen()` is the query the register is built around, and the one thing here that is worth a badge.
 *  - **An extension is a new row**, never a mutated `valid_to` — because a regulator asks what was authorised *at the
 *    moment something happened*, and an overwritten end time cannot answer.
 *  - **`area_made_safe` is its own flag.** A hot-work permit closed without the area being checked is the sequence that
 *    burns a building down an hour after everybody goes home. "Closed" is a state; "we walked it and it is safe" is an
 *    assertion somebody makes.
 */
class Permit extends Model
{
    use Auditable;

    /** @var array<string, string> */
    public const TYPES = [
        'hot_work' => 'Hot work',
        'confined_space' => 'Confined space',
        'working_at_height' => 'Working at height',
        'excavation' => 'Excavation',
        'electrical_isolation' => 'Electrical isolation',
        'lifting_operation' => 'Lifting operation',
        'road_closure' => 'Road closure',
        'live_services' => 'Live services',
        'demolition' => 'Demolition',
        'radiography' => 'Radiography',
        'night_work' => 'Night work',
        'diving' => 'Diving',
        'pressure_testing' => 'Pressure testing',
    ];

    public const STATUS_DRAFT = 'draft';

    public const STATUS_ISSUED = 'issued';

    public const STATUS_SUSPENDED = 'suspended';

    public const STATUS_CLOSED = 'closed';

    public const STATUS_CANCELLED = 'cancelled';

    /** @var array<string, string> */
    public const STATUSES = [
        self::STATUS_DRAFT => 'Draft',
        self::STATUS_ISSUED => 'Issued',
        self::STATUS_SUSPENDED => 'Suspended',
        self::STATUS_CLOSED => 'Closed',
        self::STATUS_CANCELLED => 'Cancelled',
    ];

    /**
     * The statuses under which work may be going on.
     *
     * A suspended permit is *not* one of them — that is the point of suspending — but it is still open, which is why
     * `OPEN` and `AUTHORISING` are two different sets.
     */
    public const AUTHORISING = [self::STATUS_ISSUED];

    /** Not yet closed or cancelled: still somebody's responsibility. */
    public const OPEN = [self::STATUS_DRAFT, self::STATUS_ISSUED, self::STATUS_SUSPENDED];

    protected $table = 'construction_permits';

    protected $fillable = [
        'job_id', 'contract_id', 'permit_number', 'type', 'description',
        'location_id', 'location_detail', 'activity',
        'valid_from', 'valid_to', 'requested_by', 'requester_label', 'persons_count', 'details',
        'issued_by', 'issued_at', 'accepted_by_label', 'accepted_at', 'status',
        'suspended_at', 'suspended_by', 'suspension_reason', 'resumed_at',
        'closed_at', 'closed_by', 'area_made_safe', 'close_out_notes', 'cancel_reason',
        'extends_permit_id',
    ];

    protected $casts = [
        'valid_from' => 'datetime',
        'valid_to' => 'datetime',
        'issued_at' => 'datetime',
        'accepted_at' => 'datetime',
        'suspended_at' => 'datetime',
        'resumed_at' => 'datetime',
        'closed_at' => 'datetime',
        'area_made_safe' => 'boolean',
        'persons_count' => 'integer',
        'details' => 'array',
    ];

    protected $attributes = [
        'status' => self::STATUS_DRAFT,
        'area_made_safe' => false,
    ];

    protected static function booted(): void
    {
        static::creating(function (self $permit): void {
            $permit->requested_by ??= auth()->id();
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

    /** The permit this one extends — §17.5's "an extension is a new row". */
    public function extends(): BelongsTo
    {
        return $this->belongsTo(self::class, 'extends_permit_id');
    }

    public function extensions(): HasMany
    {
        return $this->hasMany(self::class, 'extends_permit_id');
    }

    public function actions(): MorphMany
    {
        return $this->morphMany(QhseAction::class, 'subject');
    }

    public function scopeForJobTree(Builder $query, Job $root): Builder
    {
        return $query->whereIn('job_id', Job::query()->inSubtree($root)->select('id'));
    }

    public function scopeOpen(Builder $query): Builder
    {
        return $query->whereIn('status', self::OPEN);
    }

    public function scopeAuthorising(Builder $query): Builder
    {
        return $query->whereIn('status', self::AUTHORISING);
    }

    /**
     * **Past its window and still open — the failure mode §17.5 names.**
     *
     * An indexed query against `(status, valid_to)`, which is why the index exists.
     */
    public function scopeExpiredAndOpen(Builder $query, ?string $asAt = null): Builder
    {
        return $query->open()->where('valid_to', '<', Carbon::parse($asAt ?? now()));
    }

    /** In force right now: issued, inside its window, not suspended. */
    public function scopeInForceAt(Builder $query, ?string $asAt = null): Builder
    {
        $at = Carbon::parse($asAt ?? now());

        return $query->authorising()->where('valid_from', '<=', $at)->where('valid_to', '>=', $at);
    }

    public function isDraft(): bool
    {
        return $this->status === self::STATUS_DRAFT;
    }

    public function isOpen(): bool
    {
        return in_array($this->status, self::OPEN, true);
    }

    public function isClosed(): bool
    {
        return in_array($this->status, [self::STATUS_CLOSED, self::STATUS_CANCELLED], true);
    }

    public function isSuspended(): bool
    {
        return $this->status === self::STATUS_SUSPENDED;
    }

    /** **Expired and nobody has closed it.** The register's first duty. */
    public function isExpiredAndOpen(?string $asAt = null): bool
    {
        return $this->isOpen() && $this->valid_to->lt(Carbon::parse($asAt ?? now()));
    }

    /**
     * Whether this permit authorises work at a given moment.
     *
     * Issued, inside the window, and not suspended. Computed rather than stored, because a stored "in force" flag is
     * wrong from the second the window closes and nothing runs to correct it — which is the failure this whole model is
     * shaped around.
     */
    public function isInForceAt(?string $asAt = null): bool
    {
        $at = Carbon::parse($asAt ?? now());

        return $this->status === self::STATUS_ISSUED
            && $this->valid_from->lte($at)
            && $this->valid_to->gte($at);
    }

    public function isExtension(): bool
    {
        return $this->extends_permit_id !== null;
    }

    /** Hours the window covers — what somebody signing it is authorising. */
    public function windowHours(): int
    {
        return max(0, (int) $this->valid_from->diffInHours($this->valid_to, absolute: false));
    }

    /** Negative once it has expired, which is what a live board sorts on. */
    public function hoursRemaining(?string $asAt = null): int
    {
        return (int) Carbon::parse($asAt ?? now())->diffInHours($this->valid_to, absolute: false);
    }

    /**
     * A permit issued and never accepted by whoever is doing the work.
     *
     * A permit nobody accepted is a piece of paper rather than an authorisation, and the gap is invisible unless both
     * stamps are kept.
     */
    public function issuedButNotAccepted(): bool
    {
        return $this->issued_at !== null && $this->accepted_at === null;
    }

    /** A type-specific field out of the JSON bag. */
    public function detail(string $key): mixed
    {
        return data_get($this->details, $key);
    }

    public function typeLabel(): string
    {
        return self::TYPES[$this->type] ?? $this->type;
    }

    public function displayName(): string
    {
        return "{$this->permit_number} — ".$this->typeLabel();
    }
}
