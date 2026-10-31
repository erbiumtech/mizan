<?php

namespace App\Modules\Work\Models;

use App\Models\TenantModel as Model;
use App\Modules\Projects\Models\Project;
use App\Traits\Auditable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A saved query with columns — docs/project-management-plan.md §5.1.
 *
 * **A board decides nothing.** It says which items to show, which statuses each column holds, and
 * how many cards a column will take; the screen draws that and `WorkItem::moveTo()` enforces the
 * workflow. Keeping the two apart is what lets a company have three boards over one workflow
 * without three places to change a rule.
 */
class WorkBoard extends Model
{
    use Auditable;

    public const KANBAN = 'kanban';

    public const SCRUM = 'scrum';

    public const SWIMLANE_NONE = 'none';

    /** What a board may group its rows by — a group-by, nothing more. */
    public const SWIMLANES = [
        self::SWIMLANE_NONE => 'No lanes',
        'assignee' => 'By assignee',
        'epic' => 'By epic',
        'priority' => 'By priority',
    ];

    protected $fillable = [
        'project_id', 'name', 'kind', 'columns', 'swimlane', 'wip_limits', 'filters', 'shows_subtasks',
    ];

    protected $casts = [
        'columns' => 'array',
        'wip_limits' => 'array',
        'filters' => 'array',
        'shows_subtasks' => 'boolean',
    ];

    protected $attributes = ['kind' => self::KANBAN, 'swimlane' => self::SWIMLANE_NONE];

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /**
     * The board a project gets without anybody making one: one column per status, in order.
     *
     * Made on first view rather than when the project is created, because a project that never has a
     * work item should not carry a board — and because the workflow it maps may not have existed
     * when the project did.
     */
    public static function defaultFor(Project $project, WorkWorkflow $workflow): self
    {
        $existing = static::query()->where('project_id', $project->getKey())->orderBy('id')->first();

        if ($existing !== null) {
            return $existing;
        }

        return static::create([
            'project_id' => $project->getKey(),
            'name' => 'Board',
            'kind' => self::KANBAN,
            'columns' => static::columnsFor($workflow),
        ]);
    }

    /**
     * One column per status, in the workflow's order — what a new board starts with and what "reset
     * columns" goes back to.
     *
     * @return array<int, array{label: string, status_ids: array<int, int>}>
     */
    public static function columnsFor(WorkWorkflow $workflow): array
    {
        return $workflow->statuses->map(fn (WorkStatus $status): array => [
            'label' => $status->name,
            'status_ids' => [(int) $status->getKey()],
        ])->values()->all();
    }

    /** How many cards this column will take, or null for no limit. */
    public function limitFor(int $column): ?int
    {
        $limit = ($this->wip_limits ?? [])[(string) $column] ?? null;

        return $limit !== null && (int) $limit > 0 ? (int) $limit : null;
    }

    /**
     * Statuses this board shows nowhere.
     *
     * Surfaced on the screen rather than kept quiet: a board that hides a status is a board where
     * items vanish when somebody moves them there, and the fix is to say which ones.
     *
     * @return array<int, int>
     */
    public function unmappedStatusIds(WorkWorkflow $workflow): array
    {
        $mapped = collect($this->columns ?? [])->flatMap(fn (array $c): array => $c['status_ids'] ?? [])
            ->map(fn ($id): int => (int) $id)
            ->all();

        return $workflow->statuses
            ->reject(fn (WorkStatus $s): bool => in_array((int) $s->getKey(), $mapped, true))
            ->map(fn (WorkStatus $s): int => (int) $s->getKey())
            ->values()
            ->all();
    }
}
