<?php

namespace App\Modules\Work\Models;

use App\Models\Concerns\HasCustomFields;
use App\Models\TenantModel as Model;
use App\Modules\Core\Models\Comment;
use App\Modules\Core\Models\User;
use App\Modules\Employees\Models\Employee;
use App\Modules\Projects\Models\Project;
use App\Modules\Work\Events\WorkItemMoved;
use App\Modules\Work\Events\WorkItemResolved;
use App\Traits\Auditable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Support\Collection;
use RuntimeException;

/**
 * A piece of work: a Bug, a Story, a Task, an Epic, a Subtask.
 *
 * **It is a "work item" and not a "task", and the name is load-bearing here.** Three things in this
 * codebase are already called tasks and none of them is this: Performance's recurring tasks are
 * standing obligations with a penalty for missing them, Lifecycle's checklists are the steps of
 * joining and leaving, Construction's punch lists are defects on a site. A fourth "task" would be
 * the one nobody could find in a search. See docs/project-management-plan.md §2.2.
 *
 * The **type** is what a person reads and the **status category** is what the application reasons
 * about. Those two sentences are most of the design.
 */
class WorkItem extends Model
{
    use Auditable, HasCustomFields;

    /** Hierarchy is one level each way: an Epic over stories, a Subtask under one — §3.4. */
    public const TYPE_EPIC = 'epic';

    public const TYPE_SUBTASK = 'subtask';

    protected $fillable = [
        'project_id', 'number', 'key', 'type', 'title', 'description',
        'work_status_id', 'priority', 'assignee_employee_id', 'assigned_at', 'reporter_user_id',
        'parent_id', 'sprint_id', 'carried_over', 'release_id', 'estimate_points', 'estimate_minutes', 'due_on', 'rank',
        'started_at', 'resolved_at', 'resolution', 'archived_at', 'imported_from', 'ai_summary', 'ai_summary_at',
    ];

    protected $casts = [
        'number' => 'integer',
        'carried_over' => 'integer',
        'estimate_points' => 'decimal:1',
        'estimate_minutes' => 'integer',
        'due_on' => 'date',
        'started_at' => 'datetime',
        'resolved_at' => 'datetime',
        'assigned_at' => 'datetime',
        'archived_at' => 'datetime',
        'ai_summary_at' => 'datetime',
    ];

    protected $attributes = ['type' => 'task', 'priority' => 'medium', 'rank' => ''];

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function status(): BelongsTo
    {
        return $this->belongsTo(WorkStatus::class, 'work_status_id');
    }

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'assignee_employee_id');
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id');
    }

    public function sprint(): BelongsTo
    {
        return $this->belongsTo(WorkSprint::class, 'sprint_id');
    }

    /** The version this ships in — its *fix version*, §6.4. */
    public function release(): BelongsTo
    {
        return $this->belongsTo(WorkRelease::class, 'release_id');
    }

    /** Open, unarchived, and in nobody's sprint — what a backlog is. */
    public function scopeBacklog(Builder $query): Builder
    {
        return $query->open()->whereNull('sprint_id');
    }

    /**
     * Items of a type, compared without case.
     *
     * The option list stores what the company typed — `Epic`, `Subtask` — and the two constants the
     * model reasons with are lowercase, so a plain `where('type', ...)` matched neither direction.
     * One scope for both callers, so the hierarchy meaning of a type cannot depend on its spelling.
     */
    public function scopeOfType(Builder $query, string $type): Builder
    {
        return $query->whereRaw('LOWER(type) = ?', [strtolower($type)]);
    }

    public function scopeNotOfType(Builder $query, string $type): Builder
    {
        return $query->whereRaw('LOWER(type) != ?', [strtolower($type)]);
    }

    public function scopeEpics(Builder $query): Builder
    {
        return $query->ofType(self::TYPE_EPIC);
    }

    public function isEpic(): bool
    {
        return $this->isOfType(self::TYPE_EPIC);
    }

    public function isOfType(string $type): bool
    {
        return strtolower((string) $this->type) === strtolower($type);
    }

    /**
     * How far an Epic has got: done children over all children, by count and by points — §3.4.
     *
     * Computed, never stored, from the children's status categories, so a company's own statuses
     * count without configuration and a child reopened after the fact takes the figure back down.
     * An Epic is not *done* when this reaches the whole — that is the company's call (§6.3) — but
     * this is what the call is made on.
     *
     * @return array{total: int, done: int, points: float, points_done: float, percent: int}
     */
    public function progress(): array
    {
        $children = $this->relationLoaded('children')
            ? $this->children
            : $this->children()->with('status')->whereNull('archived_at')->get();

        $done = $children->filter(fn (self $child): bool => $child->isDone());

        $points = (float) $children->sum(fn (self $child): float => (float) ($child->estimate_points ?? 0));
        $pointsDone = (float) $done->sum(fn (self $child): float => (float) ($child->estimate_points ?? 0));

        return [
            'total' => $children->count(),
            'done' => $done->count(),
            'points' => $points,
            'points_done' => $pointsDone,
            // By count rather than by points, because the children of an unestimated Epic still
            // get finished one at a time.
            'percent' => $children->isEmpty() ? 0 : (int) round($done->count() / $children->count() * 100),
        ];
    }

    public function labels(): BelongsToMany
    {
        return $this->belongsToMany(WorkLabel::class, 'work_item_labels');
    }

    public function attachments(): HasMany
    {
        return $this->hasMany(WorkAttachment::class);
    }

    /** Links this item is the subject of. The ones pointing at it are `linkedFrom()`. */
    public function links(): HasMany
    {
        return $this->hasMany(WorkItemLink::class, 'from_item_id');
    }

    public function linkedFrom(): HasMany
    {
        return $this->hasMany(WorkItemLink::class, 'to_item_id');
    }

    /** Branches, commits and pull requests that named this item — §8.4, read by the item panel. */
    public function branches(): HasMany
    {
        return $this->hasMany(WorkItemBranch::class);
    }

    public function commits(): HasMany
    {
        return $this->hasMany(WorkItemCommit::class);
    }

    public function pullRequests(): HasMany
    {
        return $this->hasMany(WorkItemPullRequest::class);
    }

    /** The conversation, on the polymorphic comments every other record uses. */
    public function comments(): MorphMany
    {
        return $this->morphMany(Comment::class, 'commentable');
    }

    /**
     * Who is watching, as landlord user ids.
     *
     * Not a relation to User: users live in the landlord database and a cross-database join is the
     * thing `LandlordUserColumn` exists to avoid.
     *
     * @return Collection<int, int>
     */
    public function watcherIds(): Collection
    {
        return collect($this->newQuery()->getConnection()
            ->table('work_item_watchers')
            ->where('work_item_id', $this->getKey())
            ->pluck('user_id'))
            ->map(fn ($id): int => (int) $id);
    }

    public function watch(int|User $user): void
    {
        $id = $user instanceof User ? (int) $user->getKey() : $user;

        $this->newQuery()->getConnection()->table('work_item_watchers')->updateOrInsert(
            ['work_item_id' => $this->getKey(), 'user_id' => $id],
            ['created_at' => now()],
        );
    }

    public function scopeOpen(Builder $query): Builder
    {
        return $query->whereNull('archived_at')
            ->whereHas('status', fn (Builder $status) => $status->where('category', '!=', WorkStatus::DONE));
    }

    public function scopeForProject(Builder $query, int $projectId): Builder
    {
        return $query->where('project_id', $projectId);
    }

    /**
     * The workflow governing this item: its project's, or the company default.
     *
     * Resolved from the column rather than through a relation on `Project`, and that is the module
     * graph rather than a style choice: `work` requires `projects`, so a `workWorkflow()` relation
     * on the Project model would point back the other way and make the pair a cycle. Reading
     * `work_workflow_id` names no class of this module's, which is why Projects can carry the
     * column without knowing what fills it. See docs/project-management-plan.md §13.3.
     */
    public function workflow(): ?WorkWorkflow
    {
        $id = $this->project?->work_workflow_id;

        return $id !== null
            ? WorkWorkflow::query()->with('rules')->whereKey($id)->first()
            : WorkWorkflow::default()?->load('rules');
    }

    public function isDone(): bool
    {
        return $this->status?->isDone() ?? false;
    }

    /** What a person calls it: `WRK-12 Export fails on Safari`. */
    public function getDisplayLabelAttribute(): string
    {
        return trim($this->key.' '.$this->title);
    }

    /**
     * Who hears that this changed — `App\Support\RecordAudience`.
     *
     * The reporter asked for it, the assignee is doing it, the watchers said they cared. Returned as
     * ids because that is what the audience resolver wants, and because two of the three are not
     * rows in this database.
     *
     * @return iterable<int, int>
     */
    public function changeAudience(): iterable
    {
        return $this->watcherIds()
            ->when($this->reporter_user_id !== null, fn (Collection $ids) => $ids->push((int) $this->reporter_user_id))
            ->when($this->assignee?->user_id !== null, fn (Collection $ids) => $ids->push((int) $this->assignee->user_id))
            ->unique()
            ->values()
            ->all();
    }

    /**
     * Move to a status, obeying the workflow — docs/project-management-plan.md §4.2 and §4.3.
     *
     * **The only thing in the application allowed to write `work_status_id`.** Everything that moves
     * an item comes through here: the board's drag, the item form, the API, and in a later phase a
     * merged pull request. That is what makes `started_at` and `resolved_at` trustworthy enough to
     * compute cycle time from — they are stamped in one place, by the transition itself, rather than
     * by whichever screen happened to make the change.
     *
     * Refuses by throwing, with the reason in the message: the caller turns that into a notification
     * and the card snaps back. A refusal is never silent, because a card that quietly does not move
     * is the first thing a team blames the tool for.
     *
     * @param  ?string  $resolution  required when the target is `done` and a rule says so
     *
     * @throws RuntimeException when a workflow rule refuses the move
     */
    public function moveTo(WorkStatus $target, ?User $by = null, ?string $resolution = null): self
    {
        /*
         * The column, not the loaded relation.
         *
         * `$this->status` is whatever was loaded when the item was read, and a second move in the
         * same request would compare against the status it held *before* the first one — which made
         * the guard below think the item was already there and return silently. A move that quietly
         * does nothing is worse than one that refuses out loud.
         */
        if ((int) $this->work_status_id === (int) $target->getKey()) {
            return $this;
        }

        $from = $this->work_status_id !== null
            ? WorkStatus::query()->find($this->work_status_id)
            : null;

        $this->guardTransition($from, $target, $by, $resolution);

        $this->work_status_id = $target->getKey();

        // First time into in-progress, and only the first: a card dragged back and forth keeps the
        // moment work actually started on it, which is what lead time is measured from.
        if ($target->isInProgress() && $this->started_at === null) {
            $this->started_at = now();
        }

        if ($target->isDone()) {
            $this->resolved_at = now();
            $this->resolution = $resolution ?? $this->resolution;
        } else {
            // Reopened. The resolution goes with it — an item that is open and "Won't do" is a
            // contradiction somebody would have to read twice.
            $this->resolved_at = null;
            $this->resolution = null;
        }

        $this->save();

        // So the caller reading `$item->status` straight afterwards gets where it went, not where
        // it was — the same staleness the guard above had to stop trusting.
        $this->unsetRelation('status');

        // The two of §10.4's events a transition fires. Resolved is its own event rather than a
        // field on moved, because "it is done" is what most subscribers want and nothing else.
        WorkItemMoved::dispatch($this, $from, $target);

        if ($target->isDone()) {
            WorkItemResolved::dispatch($this);
        }

        return $this;
    }

    /**
     * The four rule kinds, in the order they can refuse.
     *
     * @throws RuntimeException
     */
    private function guardTransition(?WorkStatus $from, WorkStatus $target, ?User $by, ?string $resolution): void
    {
        $rules = $this->workflow()?->rules ?? collect();

        foreach ($rules as $rule) {
            match ($rule->kind) {
                WorkWorkflowRule::ONLY_TO => $this->guardOnlyTo($rule, $from, $target),
                WorkWorkflowRule::REQUIRES_RESOLUTION => $this->guardResolution($rule, $target, $resolution),
                WorkWorkflowRule::REQUIRES_ASSIGNEE => $this->guardAssignee($rule, $target),
                WorkWorkflowRule::ASSIGNEE_OR_MANAGER_ONLY => $this->guardMover($rule, $from, $by),
                default => null,
            };
        }
    }

    private function guardOnlyTo(WorkWorkflowRule $rule, ?WorkStatus $from, WorkStatus $target): void
    {
        if ($from === null || $rule->work_status_id !== $from->getKey()) {
            return;
        }

        $allowed = array_map('intval', (array) ($rule->to_status_ids ?? []));

        if (! in_array((int) $target->getKey(), $allowed, true)) {
            throw new RuntimeException(
                $from->name.' may only move to '.
                (WorkStatus::query()->whereKey($allowed)->pluck('name')->implode(', ') ?: 'nothing').'.'
            );
        }
    }

    private function guardResolution(WorkWorkflowRule $rule, WorkStatus $target, ?string $resolution): void
    {
        if ($rule->work_status_id === $target->getKey() && blank($resolution) && blank($this->resolution)) {
            throw new RuntimeException($target->name.' needs a resolution — say how it ended.');
        }
    }

    private function guardAssignee(WorkWorkflowRule $rule, WorkStatus $target): void
    {
        if ($rule->work_status_id === $target->getKey() && $this->assignee_employee_id === null) {
            throw new RuntimeException($target->name.' needs somebody assigned to it first.');
        }
    }

    private function guardMover(WorkWorkflowRule $rule, ?WorkStatus $from, ?User $by): void
    {
        if ($from === null || $rule->work_status_id !== $from->getKey() || $by === null) {
            return;
        }

        $isAssignee = $this->assignee?->user_id !== null && (int) $this->assignee->user_id === (int) $by->getKey();

        if (! $isAssignee && ! $by->can('BoardManage')) {
            throw new RuntimeException('Only '.($this->assignee?->display_label ?? 'the assignee')
                .' or somebody who manages the board may move this out of '.$from->name.'.');
        }
    }

    /**
     * One level each way, enforced here rather than on the form — §3.4.
     *
     * The API can set `parent_id` too, and three levels is what every team that was asked actually
     * uses. The fourth level is a Jira Premium feature whose main use is making roadmaps for people
     * who do not open the tool.
     */
    protected static function booted(): void
    {
        static::saving(function (self $item): void {
            if ($item->parent_id === null) {
                return;
            }

            if ((int) $item->parent_id === (int) $item->getKey()) {
                throw new RuntimeException('An item cannot be its own parent.');
            }

            $parent = static::query()->whereKey($item->parent_id)->first();

            if ($parent === null) {
                return;
            }

            if ($parent->isOfType(self::TYPE_SUBTASK)) {
                throw new RuntimeException('A subtask cannot have children of its own.');
            }

            if ($item->isEpic()) {
                throw new RuntimeException('An epic sits at the top; it has no parent.');
            }
        });
    }
}
