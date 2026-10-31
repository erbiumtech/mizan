<?php

namespace App\Modules\ConstructionQhse\Models;

use App\Models\TenantModel as Model;
use App\Modules\Construction\Models\Job;
use App\Modules\Construction\Models\WbsNode;
use App\Support\Replication\Replicable;
use App\Support\Replication\ReplicatesRecords;
use App\Traits\Auditable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model as EloquentModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * An inspection and test plan — `docs/construction-management-plan.md` §17.1.
 *
 * **An ITP is a controlled document, not a checklist.** A certification body asks which revision was in force when a
 * given inspection was carried out, and "the current one" is not an answer — which is why revision, issue and approval
 * are real columns and why superseding one is a status rather than an edit.
 *
 * The value is entirely in the rows: §17.1's `point_type` distinction between hold, witness and review is "the entire
 * reason an ITP exists", and this table is the folder those rows live in.
 */
class Itp extends Model implements Replicable
{
    use Auditable, ReplicatesRecords;

    public const STATUS_DRAFT = 'draft';

    public const STATUS_ISSUED = 'issued';

    public const STATUS_APPROVED = 'approved';

    public const STATUS_SUPERSEDED = 'superseded';

    /** @var array<string, string> */
    public const STATUSES = [
        self::STATUS_DRAFT => 'Draft',
        self::STATUS_ISSUED => 'Issued',
        self::STATUS_APPROVED => 'Approved',
        self::STATUS_SUPERSEDED => 'Superseded',
    ];

    /** The statuses under which an inspection may be requested against this plan's rows. */
    public const IN_FORCE = [self::STATUS_ISSUED, self::STATUS_APPROVED];

    protected $table = 'construction_itps';

    protected $fillable = [
        'job_id', 'contract_id', 'reference', 'title', 'scope', 'discipline', 'wbs_node_id',
        'revision', 'status', 'issued_on', 'approved_by', 'approved_on', 'notes', 'created_by',
    ];

    protected $casts = [
        'issued_on' => 'date',
        'approved_on' => 'date',
    ];

    protected $attributes = [
        'status' => self::STATUS_DRAFT,
    ];

    protected static function booted(): void
    {
        static::creating(function (self $itp): void {
            $itp->created_by ??= auth()->id();
        });
    }

    public function job(): BelongsTo
    {
        return $this->belongsTo(Job::class, 'job_id');
    }

    public function wbsNode(): BelongsTo
    {
        return $this->belongsTo(WbsNode::class, 'wbs_node_id');
    }

    public function activities(): HasMany
    {
        return $this->hasMany(ItpActivity::class, 'itp_id')->orderBy('sequence');
    }

    public function scopeForJobTree(Builder $query, Job $root): Builder
    {
        return $query->whereIn('job_id', Job::query()->inSubtree($root)->select('id'));
    }

    public function scopeInForce(Builder $query): Builder
    {
        return $query->whereIn('status', self::IN_FORCE);
    }

    public function isInForce(): bool
    {
        return in_array($this->status, self::IN_FORCE, true);
    }

    public function isApproved(): bool
    {
        return $this->status === self::STATUS_APPROVED;
    }

    public function isSuperseded(): bool
    {
        return $this->status === self::STATUS_SUPERSEDED;
    }

    /** Editable while it is a draft: an issued plan is a document somebody is working to. */
    public function isEditable(): bool
    {
        return $this->status === self::STATUS_DRAFT;
    }

    /**
     * How many of this plan's points stop work — the number that decides how much attendance it will need.
     *
     * Reads the loaded relation, because every screen asking is showing the rows.
     */
    public function holdPoints(): int
    {
        return $this->activities
            ->filter(fn (ItpActivity $activity): bool => $activity->isHoldPoint())
            ->count();
    }

    public function displayName(): string
    {
        return "{$this->reference} — {$this->title}";
    }

    // ─── Replicate — docs/replicate-records.md ───

    /**
     * A new draft plan like this one on the same job: same scope, discipline and activities, each with
     * the parties who must attend. It needs its own reference within the job, and has no revision, issue
     * or approval. Inspections are recorded against the original's activities and stay there, so the
     * copy starts with none.
     */
    public function replicationUniqueFields(): array
    {
        return ['reference' => self::suggestCopyOf('reference')];
    }

    public function replicationUniqueScopes(): array
    {
        return ['reference' => ['job_id']];
    }

    public function replicationResets(): array
    {
        return [
            'status' => self::STATUS_DRAFT,
            'revision' => null,
            'issued_on' => null,
            'approved_by' => null,
            'approved_on' => null,
            'created_by' => fn (): ?int => auth()->id(),
        ];
    }

    /** Two levels, so copied here rather than declared: each activity, then the parties under it. */
    public function afterReplication(EloquentModel $original): void
    {
        /** @var self $original */
        $original->loadMissing('activities.parties');

        foreach ($original->activities as $activity) {
            $copy = $this->activities()->save($activity->replicate(['created_at', 'updated_at']));

            foreach ($activity->parties as $party) {
                $copy->parties()->save($party->replicate(['created_at', 'updated_at']));
            }
        }
    }
}
