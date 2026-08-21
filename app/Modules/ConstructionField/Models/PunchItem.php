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
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One thing that is built and not right — `docs/construction-management-plan.md` §16.4.
 *
 * **`affects_practical_completion` is the flag §11's AIA holdback reads**, and it is the reason this table exists at all
 * rather than being a note field on a list. Under an AIA-style release the balance of retention falls due at Substantial
 * Completion *less a punch-list holdback*, and until these rows existed `RetentionService` could only take that holdback
 * as zero and say in words that it did not know. Now the holdback is the sum of `cost_to_rectify` over the open items
 * carrying the flag.
 *
 * It defaults to false on purpose. Most snags are paint and sealant and stop nobody taking a building over; a default of
 * true would hold retention against every one of them and make the figure meaningless inside a week. The judgement is
 * made item by item, which is what gives the holdback its standing.
 *
 * **The attempt count is the other thing this table is for.** §16.4: "closed after three failed re-inspections is a
 * different fact from closed first time, and a single closed-at column loses it." A trade that never fixes anything at
 * the first visit is three site visits nobody planned and a back-charge argument with evidence behind it — and it is
 * counted here rather than inferred from status history, the same discipline `tickets.reopened_count` already keeps.
 */
class PunchItem extends Model
{
    use Auditable;

    public const STATUS_OPEN = 'open';

    public const STATUS_IN_PROGRESS = 'in_progress';

    public const STATUS_READY = 'ready_for_inspection';

    public const STATUS_CLOSED = 'closed';

    public const STATUS_REJECTED = 'rejected';

    /** @var array<string, string> */
    public const STATUSES = [
        self::STATUS_OPEN => 'Open',
        self::STATUS_IN_PROGRESS => 'Being put right',
        self::STATUS_READY => 'Ready for inspection',
        self::STATUS_CLOSED => 'Closed',
        self::STATUS_REJECTED => 'Not a defect',
    ];

    /**
     * Still somebody's to deal with.
     *
     * `rejected` is out — an item agreed not to be a defect is settled, not outstanding — and it is a status rather than
     * a deletion because "we said this was not a defect on the 14th" is the answer to a question somebody asks again in
     * month nine.
     */
    public const LIVE = [self::STATUS_OPEN, self::STATUS_IN_PROGRESS, self::STATUS_READY];

    /** @var array<string, string> */
    public const PRIORITIES = [
        'low' => 'Low',
        'medium' => 'Medium',
        'high' => 'High',
        'critical' => 'Critical',
    ];

    protected $table = 'construction_punch_items';

    protected $fillable = [
        'punch_list_id', 'job_id', 'reference', 'description',
        'trade_id', 'trade_label', 'location_id', 'grid_reference', 'drawing_document_id',
        'priority', 'responsible_contact_id', 'responsible_label',
        'raised_on', 'raised_by', 'due_on', 'closed_on', 'closed_by', 'status',
        'affects_practical_completion', 'cost_to_rectify', 'back_charge_id',
        'before_photo_path', 'after_photo_path', 'notes',
    ];

    protected $casts = [
        'raised_on' => 'date',
        'due_on' => 'date',
        'closed_on' => 'date',
        'affects_practical_completion' => 'boolean',
    ];

    protected $attributes = [
        'status' => self::STATUS_OPEN,
        'priority' => 'medium',
        'affects_practical_completion' => false,
    ];

    protected static function booted(): void
    {
        static::creating(function (self $item): void {
            $item->raised_by ??= auth()->id();
        });
    }

    public function punchList(): BelongsTo
    {
        return $this->belongsTo(PunchList::class, 'punch_list_id');
    }

    public function job(): BelongsTo
    {
        return $this->belongsTo(Job::class, 'job_id');
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class, 'location_id');
    }

    public function drawing(): BelongsTo
    {
        return $this->belongsTo(Document::class, 'drawing_document_id');
    }

    /**
     * Who is to put it right.
     *
     * Guarded: Contacts belong to Invoicing and this module requires only `construction`, so without it the column stays
     * null and `responsible_label` carries the name. A snag list must be fillable on a job whose books are elsewhere.
     */
    public function responsible(): BelongsTo
    {
        return $this->belongsTo(Contact::class, 'responsible_contact_id');
    }

    /** Every re-inspection attempt, in order — §16.4's counted rather than inferred. */
    public function inspections(): HasMany
    {
        return $this->hasMany(PunchInspection::class, 'punch_item_id')->orderBy('attempt');
    }

    public function scopeForJobTree(Builder $query, Job $root): Builder
    {
        return $query->whereIn('job_id', Job::query()->inSubtree($root)->select('id'));
    }

    public function scopeLive(Builder $query): Builder
    {
        return $query->whereIn('status', self::LIVE);
    }

    /**
     * **Open items that stop the employer taking the building over** — the holdback query.
     *
     * The one §11's AIA release reads, and the reason the composite index exists.
     */
    public function scopeBlockingCompletion(Builder $query): Builder
    {
        return $query->live()->where('affects_practical_completion', true);
    }

    public function scopeOverdue(Builder $query, ?string $asAt = null): Builder
    {
        return $query->live()
            ->whereNotNull('due_on')
            // `whereDate`, because `due_on` is `date`-cast and therefore stored with a time.
            ->whereDate('due_on', '<', Carbon::parse($asAt ?? now())->toDateString());
    }

    public function isClosed(): bool
    {
        return in_array($this->status, [self::STATUS_CLOSED, self::STATUS_REJECTED], true);
    }

    public function isLive(): bool
    {
        return in_array($this->status, self::LIVE, true);
    }

    public function blocksCompletion(): bool
    {
        return $this->isLive() && $this->affects_practical_completion;
    }

    public function isOverdue(?string $asAt = null): bool
    {
        return $this->isLive()
            && $this->due_on !== null
            && $this->due_on->lt(Carbon::parse($asAt ?? now())->startOfDay());
    }

    /**
     * How many times somebody has been back to look at it.
     *
     * Reads the loaded relation, because every screen that asks is already showing the attempts.
     */
    public function attemptsMade(): int
    {
        return $this->inspections->count();
    }

    public function failedAttempts(): int
    {
        return $this->inspections
            ->filter(fn (PunchInspection $inspection): bool => ! $inspection->passed())
            ->count();
    }

    /**
     * **Closed after more than one visit** — §16.4's fact, as a question.
     *
     * The evidence behind a back charge for wasted attendance, and the reason the attempts are rows rather than a
     * counter somebody increments.
     */
    public function neededMoreThanOneVisit(): bool
    {
        return $this->attemptsMade() > 1;
    }

    /** The latest attempt, which is what the register's status followed from. */
    public function latestInspection(): ?PunchInspection
    {
        return $this->inspections->last();
    }

    /**
     * Cost with no charge behind it, on an item somebody else is responsible for.
     *
     * The exposure this register carries: a defect the main contractor put right at its own cost, with an estimate
     * against it, and nobody recharged. Only meaningful where a responsible party is named — a defect that is the
     * contractor's own is a cost it owns.
     */
    public function rechargeableAndUnbilled(): bool
    {
        return $this->back_charge_id === null
            && $this->cost_to_rectify !== null
            && (float) $this->cost_to_rectify > 0.0
            && ($this->responsible_contact_id !== null || filled($this->responsible_label));
    }

    public function responsibleName(): string
    {
        return $this->responsible_label
            ?? (modules()->enabled('invoicing') ? $this->responsible?->name : null)
            ?? 'Not assigned';
    }

    /** `Tower B › Level 4 › Room 412 @ C/4` — where a snag actually is. */
    public function where(): ?string
    {
        $parts = array_filter([
            $this->location?->fullName(),
            $this->grid_reference,
        ]);

        return $parts === [] ? null : implode(' @ ', $parts);
    }

    public function displayName(): string
    {
        return "{$this->reference} — ".str($this->description)->limit(60);
    }
}
