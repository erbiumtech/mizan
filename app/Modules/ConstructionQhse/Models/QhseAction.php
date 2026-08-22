<?php

namespace App\Modules\ConstructionQhse\Models;

use App\Models\TenantModel as Model;
use App\Modules\Construction\Models\Job;
use App\Modules\Invoicing\Models\Contact;
use App\Traits\Auditable;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * One action, from any QHSE object — `docs/construction-management-plan.md` §17.4.
 *
 * **"Every QHSE object generates the same record — somebody must do something by a date and somebody else must verify
 * it."** One table rather than four, because "four separate action tables produce four *overdue actions* reports that
 * never agree, and the safety manager's one genuinely useful screen — everything overdue, from every source, in one
 * list — becomes a four-way union nobody maintains."
 *
 * Two properties carry it.
 *
 * **The assignee works without a user or a contact record.** Three columns, and the plain label is the one that matters
 * most: the person who has to fix the handrail is usually a subcontractor's foreman who is in neither table, and an
 * action nobody can be assigned to is an action nobody does.
 *
 * **Verified is not done.** "Done" is the assignee's claim; "verified" is somebody else's confirmation. Conflating them
 * would let whoever caused a finding close it, which is the same argument §16.4's punch item makes about a passed
 * re-inspection.
 *
 * The class is `QhseAction` rather than `Action` because Filament has an `Action` and a model sharing that name in a
 * resource file is a bug waiting for somebody's import statement.
 */
class QhseAction extends Model
{
    use Auditable;

    public const STATUS_OPEN = 'open';

    public const STATUS_IN_PROGRESS = 'in_progress';

    public const STATUS_DONE = 'done';

    public const STATUS_VERIFIED = 'verified';

    public const STATUS_CANCELLED = 'cancelled';

    /** @var array<string, string> */
    public const STATUSES = [
        self::STATUS_OPEN => 'Open',
        self::STATUS_IN_PROGRESS => 'In progress',
        self::STATUS_DONE => 'Done, awaiting verification',
        self::STATUS_VERIFIED => 'Verified',
        self::STATUS_CANCELLED => 'Cancelled',
    ];

    /** Still somebody's to do. `done` is here because an unverified claim is not a closed action. */
    public const LIVE = [self::STATUS_OPEN, self::STATUS_IN_PROGRESS, self::STATUS_DONE];

    /** @var array<string, string> */
    public const TYPES = [
        'corrective' => 'Corrective — fix this',
        'preventive' => 'Preventive — stop it recurring',
        'containment' => 'Containment — stop it spreading now',
        'improvement' => 'Improvement',
        'follow_up' => 'Follow-up',
    ];

    /** @var array<string, string> */
    public const PRIORITIES = [
        'low' => 'Low',
        'medium' => 'Medium',
        'high' => 'High',
        'critical' => 'Critical',
    ];

    protected $table = 'construction_actions';

    protected $fillable = [
        'subject_type', 'subject_id', 'job_id', 'description', 'action_type',
        'assigned_user_id', 'assigned_contact_id', 'assignee_label',
        'due_on', 'priority', 'status',
        'completed_on', 'completed_by', 'completion_notes',
        'verified_on', 'verified_by', 'verification_notes',
        'cancel_reason', 'created_by',
    ];

    protected $casts = [
        'due_on' => 'date',
        'completed_on' => 'date',
        'verified_on' => 'date',
    ];

    protected $attributes = [
        'status' => self::STATUS_OPEN,
        'action_type' => 'corrective',
        'priority' => 'medium',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $action): void {
            $action->created_by ??= auth()->id();
        });
    }

    /** Whatever raised it — an NCR, an incident, an inspection, a toolbox talk, an audit finding. */
    public function subject(): MorphTo
    {
        return $this->morphTo();
    }

    public function job(): BelongsTo
    {
        return $this->belongsTo(Job::class, 'job_id');
    }

    /** The assignee where they have a contact record. Guarded: Invoicing owns contacts. */
    public function assignedContact(): BelongsTo
    {
        return $this->belongsTo(Contact::class, 'assigned_contact_id');
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
     * Past its date and not verified.
     *
     * `whereDate` because `due_on` is `date`-cast and therefore stored with a time — the recurring gotcha this suite has
     * now hit four times.
     */
    public function scopeOverdue(Builder $query, ?string $asAt = null): Builder
    {
        return $query->live()
            ->whereNotNull('due_on')
            ->whereDate('due_on', '<', Carbon::parse($asAt ?? now())->toDateString());
    }

    /** Claimed done and nobody has confirmed it. */
    public function scopeAwaitingVerification(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_DONE);
    }

    public function isLive(): bool
    {
        return in_array($this->status, self::LIVE, true);
    }

    public function isVerified(): bool
    {
        return $this->verified_on !== null;
    }

    public function isDone(): bool
    {
        return $this->completed_on !== null;
    }

    public function isOverdue(?string $asAt = null): bool
    {
        return $this->isLive()
            && $this->due_on !== null
            && $this->due_on->lt(Carbon::parse($asAt ?? now())->startOfDay());
    }

    /** Days late, or negative while there is still time — what a chase list sorts on. */
    public function daysUntilDue(?string $asAt = null): ?int
    {
        if ($this->due_on === null) {
            return null;
        }

        return (int) Carbon::parse($asAt ?? now())->startOfDay()->diffInDays($this->due_on, absolute: false);
    }

    /**
     * **Somebody claimed it was done and nobody has checked.**
     *
     * The state §17.4's second clause exists for, and the one a register that only had "closed" would lose.
     */
    public function awaitingVerification(): bool
    {
        return $this->status === self::STATUS_DONE;
    }

    /**
     * Who has to do it, whatever records exist.
     *
     * The label first, because it is the one that is always fillable — and an unassigned action is named rather than
     * left blank, since an action nobody owns is an action nobody does.
     */
    public function assigneeName(): string
    {
        if (filled($this->assignee_label)) {
            return $this->assignee_label;
        }

        if ($this->assigned_contact_id !== null && modules()->enabled('invoicing')) {
            return $this->assignedContact?->name ?? 'Nobody assigned';
        }

        if ($this->assigned_user_id !== null) {
            return \App\Modules\Core\Models\User::query()->find($this->assigned_user_id)?->name ?? 'Nobody assigned';
        }

        return 'Nobody assigned';
    }

    public function isUnassigned(): bool
    {
        return blank($this->assignee_label)
            && $this->assigned_contact_id === null
            && $this->assigned_user_id === null;
    }

    /** `NCR-3` or `INS-7` — what raised it, for the one list that shows every source together. */
    public function sourceLabel(): string
    {
        $basename = class_basename($this->subject_type);

        return match ($basename) {
            'Ncr' => 'NCR',
            'Inspection' => 'Inspection',
            'Incident' => 'Incident',
            'ToolboxTalk' => 'Toolbox talk',
            default => $basename,
        };
    }

    public function typeLabel(): string
    {
        return self::TYPES[$this->action_type] ?? $this->action_type;
    }

    public function displayName(): string
    {
        return str($this->description)->limit(60);
    }
}
