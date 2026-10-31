<?php

namespace App\Modules\Work\Services;

use App\Modules\Core\Models\User;
use App\Modules\Work\Models\WorkBoard;
use App\Modules\Work\Models\WorkItem;
use App\Modules\Work\Models\WorkItemLink;
use App\Modules\Work\Models\WorkSprint;
use App\Modules\Work\Models\WorkStatus;
use App\Modules\Work\Support\Rank;
use App\Support\EmployeeAccess;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use RuntimeException;

/**
 * Turning a board into columns of cards, and taking a card from one to another.
 *
 * **The board is a query, and this is the query** — docs/project-management-plan.md §5. Everything
 * the screen shows comes from here, which is what keeps the blade free of decisions and what lets
 * the backlog, the sprint board and "My work" be the same thing with a different `where`.
 */
class Boards
{
    /**
     * The board's columns, each with the cards in it, in rank order.
     *
     * One query for the items and one for the blockers, whatever the number of columns: grouping is
     * done in PHP because a query per column is how a board with eight of them becomes the slowest
     * screen in the application.
     *
     * @param  array<string, mixed>  $filters  the quick filters above the columns
     * @return array<int, array{label: string, status_ids: array<int, int>, limit: ?int, over: bool, lanes: array<int, array{label: string, cards: array<int, array<string, mixed>>}>, total: int}>
     */
    public function columns(WorkBoard $board, array $filters = []): array
    {
        $items = $this->items($board, $filters);
        $blocked = $this->blockedIds($items);

        $columns = [];

        // Each column's status category, for the coloured dot in its header — one query for the board.
        $categories = WorkStatus::query()
            ->whereIn('id', collect($board->columns ?? [])->pluck('status_ids')->flatten()->map(fn ($id): int => (int) $id)->all())
            ->pluck('category', 'id');

        foreach (($board->columns ?? []) as $index => $column) {
            $statusIds = array_map('intval', $column['status_ids'] ?? []);
            $inColumn = $items->filter(
                fn (WorkItem $item): bool => in_array((int) $item->work_status_id, $statusIds, true),
            );

            $limit = $board->limitFor($index);

            $columns[] = [
                'index' => $index,
                'label' => $column['label'] ?? 'Column',
                'status_ids' => $statusIds,
                // The status a card dropped here lands in: the first. The card's own menu offers the
                // others, which is how a two-status column stays usable without being ambiguous.
                'drop_status_id' => $statusIds[0] ?? null,
                'category' => $categories[$statusIds[0] ?? 0] ?? null,
                'limit' => $limit,
                'over' => $limit !== null && $inColumn->count() > $limit,
                'total' => $inColumn->count(),
                'lanes' => $this->lanes($board, $inColumn, $blocked),
            ];
        }

        return $columns;
    }

    /**
     * The cards of one column, split into lanes.
     *
     * @param  Collection<int, WorkItem>  $items
     * @param  array<int, int>  $blocked
     * @return array<int, array{key: string, label: string, cards: array<int, array<string, mixed>>}>
     */
    private function lanes(WorkBoard $board, Collection $items, array $blocked): array
    {
        if ($board->swimlane === WorkBoard::SWIMLANE_NONE) {
            return [[
                'key' => 'all',
                'label' => '',
                'cards' => $items->map(fn (WorkItem $i): array => $this->card($i, $blocked))->values()->all(),
            ]];
        }

        $grouped = $items->groupBy(fn (WorkItem $item): string => match ($board->swimlane) {
            'assignee' => $item->assignee?->display_label ?? 'Unassigned',
            'epic' => $item->parent?->display_label ?? 'No epic',
            'priority' => (string) ($item->priority ?: 'None'),
            default => 'all',
        });

        return $grouped->map(fn (Collection $lane, string $label): array => [
            'key' => $label,
            'label' => $label,
            'cards' => $lane->map(fn (WorkItem $i): array => $this->card($i, $blocked))->values()->all(),
        ])->sortKeys()->values()->all();
    }

    /**
     * What a card carries — §5.4.
     *
     * Built here rather than read off the model in the view so that the board, the backlog and any
     * later screen showing a card show the same one.
     *
     * @param  array<int, int>  $blocked
     * @return array<string, mixed>
     */
    private function card(WorkItem $item, array $blocked): array
    {
        return [
            'id' => (int) $item->getKey(),
            'key' => $item->key,
            'title' => $item->title,
            'type' => $item->type,
            'priority' => $item->priority,
            'assignee' => $item->assignee?->display_label,
            'initials' => $this->initials($item->assignee?->display_label),
            'due_on' => $item->due_on?->format('d M'),
            'overdue' => $item->due_on !== null && $item->due_on->isPast() && ! $item->isDone(),
            'points' => $item->estimate_points !== null ? (float) $item->estimate_points : null,
            'labels' => $item->labels->map(fn ($l): array => ['name' => $l->name, 'colour' => $l->colour])->all(),
            'epic' => $item->parent?->key,
            'blocked' => in_array((int) $item->getKey(), $blocked, true),
            'subtasks' => $item->children_count ?? 0,
            'subtasks_done' => $item->children_done_count ?? 0,
            'url' => \App\Modules\Work\Filament\Resources\WorkItems\WorkItemResource::getUrl('edit', ['record' => $item->getKey()]),
        ];
    }

    /**
     * Items on this board, filtered.
     *
     * @param  array<string, mixed>  $filters
     * @return Collection<int, WorkItem>
     */
    private function items(WorkBoard $board, array $filters): Collection
    {
        $query = WorkItem::query()
            ->with(['assignee.user', 'labels', 'parent'])
            ->withCount([
                'children',
                'children as children_done_count' => fn (Builder $q) => $q->whereHas(
                    'status',
                    fn (Builder $s) => $s->where('category', WorkStatus::DONE),
                ),
            ])
            ->whereNull('archived_at')
            ->orderBy('rank');

        if ($board->project_id !== null) {
            $query->where('project_id', $board->project_id);
        }

        // Subtasks are shown under their parent unless the board asks for them as cards — §5.4.
        if (! $board->shows_subtasks) {
            $query->notOfType(WorkItem::TYPE_SUBTASK);
        }

        /*
         * A Scrum board is the active sprint — §5.1, and the whole difference between the two kinds.
         *
         * With no sprint running it shows nothing rather than everything: a team between sprints has
         * an empty board, which is the truth, where a board quietly falling back to the backlog would
         * be the commitment they have not made yet.
         */
        if ($board->kind === WorkBoard::SCRUM && $board->project_id !== null) {
            $query->where('sprint_id', WorkSprint::query()
                ->where('project_id', $board->project_id)
                ->active()
                ->value('id') ?? 0);
        }

        $this->applyFilters($query, array_merge($board->filters ?? [], $filters));

        return $query->get();
    }

    /**
     * The quick filters, which are the board's saved ones with the reader's own on top.
     *
     * @param  array<string, mixed>  $filters
     */
    private function applyFilters(Builder $query, array $filters): void
    {
        if ($filters['mine'] ?? false) {
            $query->whereHas('assignee', fn (Builder $e) => $e->where('user_id', auth()->id()));
        }

        if ($filters['unassigned'] ?? false) {
            $query->whereNull('assignee_employee_id');
        }

        // "My work" sets this rather than `mine`, because it is the board's own filter rather than
        // one the reader toggled — and because a null employee id must show nothing rather than
        // everything, which is what `whereNull` on a missing value would do.
        if (array_key_exists('assignee_employee_id', $filters)) {
            $query->where('assignee_employee_id', $filters['assignee_employee_id'] ?? 0);
        }

        if ($filters['blocked'] ?? false) {
            $query->whereHas('linkedFrom', fn (Builder $l) => $l->where('kind', WorkItemLink::BLOCKS));
        }

        if (filled($filters['type'] ?? null)) {
            $query->where('type', $filters['type']);
        }

        if (filled($filters['label'] ?? null)) {
            $query->whereHas('labels', fn (Builder $l) => $l->whereKey($filters['label']));
        }

        if (filled($filters['search'] ?? null)) {
            $search = $filters['search'];
            $query->where(fn (Builder $q) => $q
                ->where('key', 'like', "%{$search}%")
                ->orWhere('title', 'like', "%{$search}%"));
        }
    }

    /**
     * Which of these items something is holding up — one query, not one per card.
     *
     * @param  Collection<int, WorkItem>  $items
     * @return array<int, int>
     */
    private function blockedIds(Collection $items): array
    {
        if ($items->isEmpty()) {
            return [];
        }

        return WorkItemLink::query()
            ->where('kind', WorkItemLink::BLOCKS)
            ->whereIn('to_item_id', $items->modelKeys())
            ->pluck('to_item_id')
            ->map(fn ($id): int => (int) $id)
            ->unique()
            ->all();
    }

    /**
     * A card dropped somewhere: the move, the rank, and the two things that can refuse it.
     *
     * The WIP limit is checked here and the workflow in `moveTo()`, and both refuse by throwing so
     * the screen can snap the card back and say why. **A limit is a limit, not a warning** — a team
     * that wants a soft one sets it one higher, and a column that goes red while accepting the card
     * anyway teaches people to ignore it.
     *
     * @throws RuntimeException
     */
    public function move(WorkBoard $board, WorkItem $item, int $columnIndex, ?int $afterId, ?int $beforeId, ?User $by = null): WorkItem
    {
        $columns = $board->columns ?? [];
        $column = $columns[$columnIndex] ?? null;

        if ($column === null) {
            throw new RuntimeException('That column is no longer on this board.');
        }

        $statusIds = array_map('intval', $column['status_ids'] ?? []);
        $target = WorkStatus::query()->find($statusIds[0] ?? null);

        if ($target === null) {
            throw new RuntimeException('That column has no status to put it in.');
        }

        $changingColumn = ! in_array((int) $item->work_status_id, $statusIds, true);

        if ($changingColumn) {
            $limit = $board->limitFor($columnIndex);

            if ($limit !== null) {
                $held = WorkItem::query()
                    ->whereNull('archived_at')
                    ->whereIn('work_status_id', $statusIds)
                    ->when($board->project_id !== null, fn (Builder $q) => $q->where('project_id', $board->project_id))
                    ->count();

                if ($held >= $limit) {
                    throw new RuntimeException(
                        ($column['label'] ?? 'That column').' is at its limit of '.$limit
                        .'. Finish something there before starting this.'
                    );
                }
            }
        }

        $item->rank = Rank::between(
            $afterId !== null ? WorkItem::query()->whereKey($afterId)->value('rank') : null,
            $beforeId !== null ? WorkItem::query()->whereKey($beforeId)->value('rank') : null,
        );

        if ($changingColumn) {
            // Saves the rank too, and goes through the one door that writes a status.
            $item->moveTo($target, $by);
        } else {
            $item->save();
        }

        return $item;
    }

    /**
     * Everything assigned to one person, across every project they can see — §5.5.
     *
     * A board nobody has to make, and for most people the first screen they open. Columns are the
     * three categories rather than a workflow's statuses, because the items on it come from projects
     * whose workflows need not agree about anything except those.
     */
    public function myWork(User $user): WorkBoard
    {
        $employeeId = app(EmployeeAccess::class)->ownEmployeeId($user);

        $board = new WorkBoard([
            'name' => 'My work',
            'kind' => WorkBoard::KANBAN,
            'swimlane' => 'none',
            'columns' => collect(WorkStatus::CATEGORIES)
                ->map(fn (string $label, string $category): array => [
                    'label' => $label,
                    'status_ids' => WorkStatus::query()->where('category', $category)->pluck('id')->map(fn ($id): int => (int) $id)->all(),
                ])
                ->values()
                ->all(),
        ]);

        // Not saved: it is derived from who is looking, and a row per user is a row to keep in step
        // with every workflow change for no gain.
        $board->setAttribute('filters', ['assignee_employee_id' => $employeeId]);

        return $board;
    }

    private function initials(?string $name): string
    {
        if (blank($name)) {
            return '';
        }

        return collect(preg_split('/\s+/', trim($name)))
            ->filter()
            ->take(2)
            ->map(fn (string $part): string => mb_strtoupper(mb_substr($part, 0, 1)))
            ->implode('');
    }
}
