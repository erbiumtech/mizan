<?php

namespace App\Modules\Work\Services;

use App\Modules\Projects\Models\Project;
use App\Modules\Work\Models\WorkBoard;
use App\Modules\Work\Models\WorkItem;
use App\Modules\Work\Models\WorkStatus;
use App\Modules\Work\Models\WorkWorkflow;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/**
 * The shape of a board, and which workflow a project's boards are drawn from.
 *
 * A board decides nothing about what may move where (`WorkItem::moveTo()` does), so what is changed
 * here is only what the screen draws: the columns and the statuses each holds, how many cards a
 * column takes, the lanes, and whether subtasks get cards of their own. The rules a save must keep
 * are few and all of them are about not losing a card: every column holds at least one status, no
 * status is in two columns, a limit is a positive number or nothing.
 *
 * A project's workflow is changed here too, because the change is only safe if its boards change
 * with it. The rule is the strict one: **a project may change workflow only while none of its work
 * items is in a status the new workflow lacks** — in practice, before it has items, since a status
 * belongs to one workflow. Nothing is remapped silently; somebody who wants to move a running
 * project moves its items' statuses first, knowingly. When the change is allowed, every board of the
 * project is rebuilt with one column per status of the new workflow, because a column holding the
 * old workflow's statuses would hold nothing, and limits that were set against those columns are
 * cleared with them.
 */
class BoardSettings
{
    /**
     * The board as the settings form shows it: each column carrying its own limit, so reordering the
     * columns carries the limits with them.
     *
     * @return array{name: string, kind: string, swimlane: string, shows_subtasks: bool, columns: array<int, array{label: string, status_ids: array<int, int>, limit: ?int}>}
     */
    public function formState(WorkBoard $board): array
    {
        $columns = [];

        foreach (($board->columns ?? []) as $index => $column) {
            $columns[] = [
                'label' => (string) ($column['label'] ?? ''),
                'status_ids' => array_map('intval', $column['status_ids'] ?? []),
                'limit' => $board->limitFor((int) $index),
            ];
        }

        return [
            'name' => (string) $board->name,
            'kind' => (string) $board->kind,
            'swimlane' => (string) ($board->swimlane ?? WorkBoard::SWIMLANE_NONE),
            'shows_subtasks' => (bool) $board->shows_subtasks,
            'columns' => $columns,
        ];
    }

    /**
     * What is wrong with a set of columns, in a sentence a person can act on — or null.
     *
     * @param  array<int|string, array<string, mixed>>  $columns
     */
    public function columnProblem(array $columns, WorkWorkflow $workflow): ?string
    {
        $columns = array_values($columns);

        if ($columns === []) {
            return __('A board needs at least one column.');
        }

        $allowed = $workflow->statuses->map(fn (WorkStatus $s): int => (int) $s->getKey())->all();
        $seen = [];

        foreach ($columns as $column) {
            $label = trim((string) ($column['label'] ?? ''));
            $statusIds = array_map('intval', array_values($column['status_ids'] ?? []));

            if ($label === '') {
                return __('Every column needs a name.');
            }

            if ($statusIds === []) {
                return __('The column :column shows no status. Give it one, or remove the column.', ['column' => $label]);
            }

            foreach ($statusIds as $id) {
                if (! in_array($id, $allowed, true)) {
                    return __('The column :column shows a status that is not in this project\'s workflow.', ['column' => $label]);
                }

                if (isset($seen[$id])) {
                    $status = $workflow->statuses->firstWhere('id', $id)?->name ?? (string) $id;

                    return __('The status :status is in two columns, :first and :second. A status may be in one column only.', [
                        'status' => $status,
                        'first' => $seen[$id],
                        'second' => $label,
                    ]);
                }

                $seen[$id] = $label;
            }

            $limit = $column['limit'] ?? null;

            if ($limit !== null && $limit !== '' && filter_var($limit, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) === false) {
                return __('The limit on :column must be a whole number of at least one, or empty for no limit.', ['column' => $label]);
            }
        }

        return null;
    }

    /**
     * Save the form. Refuses rather than repairs: a board saved half-right is a board where a card
     * goes missing.
     *
     * @param  array<string, mixed>  $data  the settings form's state
     */
    public function save(WorkBoard $board, array $data, WorkWorkflow $workflow): WorkBoard
    {
        $columns = array_values($data['columns'] ?? []);

        if ($problem = $this->columnProblem($columns, $workflow)) {
            throw new InvalidArgumentException($problem);
        }

        $swimlane = (string) ($data['swimlane'] ?? WorkBoard::SWIMLANE_NONE);

        if (! array_key_exists($swimlane, WorkBoard::SWIMLANES)) {
            throw new InvalidArgumentException(__('That is not a way this board can group its cards.'));
        }

        $kind = (string) ($data['kind'] ?? $board->kind);

        if (! in_array($kind, [WorkBoard::KANBAN, WorkBoard::SCRUM], true)) {
            throw new InvalidArgumentException(__('A board is either Kanban or Scrum.'));
        }

        $name = trim((string) ($data['name'] ?? ''));

        if ($name === '') {
            throw new InvalidArgumentException(__('A board needs a name.'));
        }

        // Limits are stored by column position, which is what `limitFor()` reads. They are rebuilt
        // from the columns as saved, so a reorder moves each limit with its column.
        $stored = [];
        $limits = [];

        foreach ($columns as $index => $column) {
            $stored[] = [
                'label' => trim((string) $column['label']),
                'status_ids' => array_values(array_map('intval', $column['status_ids'])),
            ];

            if (($column['limit'] ?? null) !== null && $column['limit'] !== '') {
                $limits[(string) $index] = (int) $column['limit'];
            }
        }

        $board->update([
            'name' => $name,
            'kind' => $kind,
            'swimlane' => $swimlane,
            'shows_subtasks' => (bool) ($data['shows_subtasks'] ?? false),
            'columns' => $stored,
            'wip_limits' => $limits,
        ]);

        return $board;
    }

    /** One column per status again, and no limits — for a board somebody has lost track of. */
    public function reset(WorkBoard $board, WorkWorkflow $workflow): WorkBoard
    {
        $board->update(['columns' => WorkBoard::columnsFor($workflow), 'wip_limits' => []]);

        return $board;
    }

    /** A second (or third) board on the same project, starting from one column per status. */
    public function create(Project $project, string $name, string $kind, WorkWorkflow $workflow): WorkBoard
    {
        $name = trim($name);

        if ($name === '') {
            throw new InvalidArgumentException(__('A board needs a name.'));
        }

        if (! in_array($kind, [WorkBoard::KANBAN, WorkBoard::SCRUM], true)) {
            throw new InvalidArgumentException(__('A board is either Kanban or Scrum.'));
        }

        // The project's first board is made on first view; make sure it exists before a second one
        // does, or the new board would become the default by being the oldest.
        WorkBoard::defaultFor($project, $workflow);

        return WorkBoard::create([
            'project_id' => $project->getKey(),
            'name' => $name,
            'kind' => $kind,
            'columns' => WorkBoard::columnsFor($workflow),
        ]);
    }

    /** Any board but a project's last: a project with work always has somewhere to show it. */
    public function delete(WorkBoard $board): void
    {
        $count = WorkBoard::query()->where('project_id', $board->project_id)->count();

        if ($count <= 1) {
            throw new InvalidArgumentException(__('This is the project\'s only board, and a project always keeps one.'));
        }

        $board->delete();
    }

    /**
     * How many of the project's items sit in a status the given workflow does not have. Null for the
     * workflow means the company default, which is what an empty project field means.
     */
    public function itemsOutside(Project $project, ?int $workflowId): int
    {
        if (! $project->exists) {
            return 0;
        }

        $workflow = $workflowId !== null ? WorkWorkflow::query()->find($workflowId) : WorkWorkflow::default();

        $query = WorkItem::query()->where('project_id', $project->getKey());

        if ($workflow !== null) {
            $query->whereNotIn('work_status_id', WorkStatus::query()->where('work_workflow_id', $workflow->getKey())->select('id'));
        }

        return $query->count();
    }

    /** The sentence the project form and the model guard both refuse with, or null when it may change. */
    public function workflowChangeProblem(Project $project, ?int $workflowId): ?string
    {
        $outside = $this->itemsOutside($project, $workflowId);

        if ($outside === 0) {
            return null;
        }

        return trans_choice(
            ':count work item of this project is in a status the chosen workflow does not have. Move it to that workflow\'s statuses first; nothing is remapped automatically.|:count work items of this project are in statuses the chosen workflow does not have. Move them to that workflow\'s statuses first; nothing is remapped automatically.',
            $outside,
            ['count' => $outside],
        );
    }

    /**
     * The guard behind the form's rule, for every other way a project is saved. Called while the
     * project is being updated, before anything is written.
     */
    public function assertWorkflowMayChange(Project $project): void
    {
        $new = $project->work_workflow_id !== null ? (int) $project->work_workflow_id : null;

        if ($problem = $this->workflowChangeProblem($project, $new)) {
            throw ValidationException::withMessages(['work_workflow_id' => $problem]);
        }
    }

    /**
     * After a project's workflow changed: every board is redrawn from the new one. Its name, kind
     * and lanes stay; its columns and limits were the old workflow's and go.
     *
     * Nothing happens when the workflow in effect did not change — choosing the default by name on
     * a project that was already using it by default keeps the boards as they are.
     */
    public function rebuildBoards(Project $project, ?int $previousWorkflowId): void
    {
        $default = WorkWorkflow::default()?->getKey();
        $before = $previousWorkflowId ?? $default;
        $after = $project->work_workflow_id !== null ? (int) $project->work_workflow_id : $default;

        if ($before !== null && $after !== null && (int) $before === (int) $after) {
            return;
        }

        $workflow = WorkWorkflow::forProject($project);

        if ($workflow === null) {
            return;
        }

        WorkBoard::query()->where('project_id', $project->getKey())->get()
            ->each(fn (WorkBoard $board) => $board->update([
                'columns' => WorkBoard::columnsFor($workflow),
                'wip_limits' => [],
            ]));
    }
}
