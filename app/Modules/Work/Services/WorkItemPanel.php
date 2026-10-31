<?php

namespace App\Modules\Work\Services;

use App\Modules\Core\Models\ActivityLog;
use App\Modules\Core\Models\User;
use App\Modules\Employees\Models\Employee;
use App\Modules\Work\Filament\Resources\WorkItems\WorkItemResource;
use App\Modules\Work\Models\WorkItem;
use App\Modules\Work\Models\WorkItemLink;
use App\Modules\Work\Models\WorkStatus;
use Illuminate\Support\Collection;
use Throwable;

/**
 * The item panel a board card opens — everything about one work item, read in one go.
 *
 * Like `Boards`, **this decides nothing**: it reads the item and its neighbours with every relation
 * eager-loaded (the panel draws a dozen lists and none of them may lazy-load), and hands the view
 * plain arrays. The writes the panel offers — status, assignee, a comment, a subtask — go through
 * the same doors the form and the board use: `moveTo()`, the item's own save, `WorkItems::create()`.
 */
class WorkItemPanel
{
    public function __construct(private readonly WorkTime $time) {}

    /** The item with everything the panel reads, or null when it is not there (or not this company's). */
    public function find(int $id): ?WorkItem
    {
        return WorkItem::query()
            ->with([
                'project',
                'status',
                'assignee.user',
                'parent',
                'labels',
                'release',
                'sprint',
                'children' => fn ($q) => $q->with(['status', 'assignee.user'])->orderBy('rank'),
                'links.to.status',
                'linkedFrom.from.status',
                'comments' => fn ($q) => $q->with('user')->latest('id'),
                'branches' => fn ($q) => $q->with('repository')->latest('id'),
                'commits' => fn ($q) => $q->with('repository')->latest('committed_at')->limit(20),
                'pullRequests' => fn ($q) => $q->with('repository')->latest('id'),
            ])
            ->find($id);
    }

    /**
     * What the panel draws.
     *
     * @return array<string, mixed>
     */
    public function present(WorkItem $item, ?User $viewer): array
    {
        $statuses = $item->workflow()?->statuses()->get() ?? collect();
        $reporter = $item->reporter_user_id !== null ? User::query()->find($item->reporter_user_id) : null;
        $watchers = $item->watcherIds();
        $me = $viewer !== null ? Employee::query()->where('user_id', $viewer->getKey())->value('id') : null;

        return [
            'id' => (int) $item->getKey(),
            'key' => $item->key,
            'title' => $item->title,
            'type' => (string) $item->type,
            'description' => (string) $item->description,
            'url' => WorkItemResource::getUrl('edit', ['record' => $item->getKey()]),
            'parent' => $item->parent === null ? null : [
                'key' => $item->parent->key,
                'title' => $item->parent->title,
                'id' => (int) $item->parent->getKey(),
            ],
            'status_id' => (int) $item->work_status_id,
            'status' => $item->status?->name,
            'category' => $item->status?->category,
            'statuses' => $statuses->map(fn (WorkStatus $s): array => [
                'id' => (int) $s->getKey(),
                'name' => $s->name,
                'category' => $s->category,
            ])->values()->all(),
            'priority' => (string) $item->priority,
            'priorities' => self::priorities((string) $item->priority),
            'assignee' => $item->assignee?->display_label,
            'assignee_name' => $item->assignee?->fullName(),
            'assignee_initials' => self::initials($item->assignee?->fullName()),
            'is_mine' => $me !== null && (int) $item->assignee_employee_id === (int) $me,
            'can_take' => $me !== null,
            'reporter' => $reporter?->name,
            'reporter_initials' => self::initials($reporter?->name),
            'labels' => $item->labels->map(fn ($l): array => ['name' => $l->name, 'colour' => $l->colour])->all(),
            'watchers' => $watchers->count(),
            'watching' => $viewer !== null && $watchers->contains((int) $viewer->getKey()),
            'points' => $item->estimate_points !== null ? (float) $item->estimate_points : null,
            'estimate_minutes' => $item->estimate_minutes,
            'due_on' => $item->due_on?->format('d M Y'),
            'overdue' => $item->due_on !== null && $item->due_on->isPast() && ! $item->isDone(),
            'sprint' => $item->sprint?->name,
            'release' => $item->release?->name,
            'resolution' => $item->resolution,
            'created' => $item->created_at,
            'updated' => $item->updated_at,
            'resolved' => $item->resolved_at,
            'can_have_subtasks' => ! $item->isOfType(WorkItem::TYPE_SUBTASK),
            'subtasks' => $item->children->map(fn (WorkItem $c): array => [
                'id' => (int) $c->getKey(),
                'key' => $c->key,
                'title' => $c->title,
                'status' => $c->status?->name,
                'category' => $c->status?->category,
                'initials' => self::initials($c->assignee?->fullName()),
            ])->all(),
            'subtasks_done' => $item->children->filter(fn (WorkItem $c): bool => $c->status?->isDone() ?? false)->count(),
            'links' => $this->links($item),
            'comments' => $item->comments->map(fn ($c): array => [
                'id' => (int) $c->getKey(),
                'author' => $c->user?->name ?? __('Somebody'),
                'initials' => self::initials($c->user?->name),
                'body' => (string) $c->body,
                'at' => $c->created_at,
            ])->all(),
            'history' => $this->history($item),
            'worklog' => $this->worklog($item),
            'time_available' => $this->time->isAvailable(),
            'branches' => $item->branches->map(fn ($b): array => [
                'name' => $b->name,
                'url' => $b->url,
                'repository' => $b->repository?->full_name,
                'deleted' => $b->isDeleted(),
            ])->all(),
            'commits' => $item->commits->map(fn ($c): array => [
                'sha' => $c->shortSha(),
                'message' => str((string) $c->message)->before("\n")->limit(80)->toString(),
                'url' => $c->url,
                'at' => $c->committed_at,
            ])->all(),
            'pull_requests' => $item->pullRequests->map(fn ($p): array => [
                'number' => $p->number,
                'title' => $p->title,
                'state' => $p->state,
                'url' => $p->url,
            ])->all(),
        ];
    }

    /**
     * Both directions of every link, each read from this item's side: "blocks APP-3", "is blocked by APP-1".
     *
     * @return array<int, array<string, mixed>>
     */
    private function links(WorkItem $item): array
    {
        $out = $item->links->filter(fn (WorkItemLink $l): bool => $l->to !== null)->map(fn (WorkItemLink $l): array => [
            'relation' => __(WorkItemLink::FORWARD[$l->kind] ?? $l->kind),
            'id' => (int) $l->to->getKey(),
            'key' => $l->to->key,
            'title' => $l->to->title,
            'status' => $l->to->status?->name,
            'category' => $l->to->status?->category,
        ]);

        $in = $item->linkedFrom->filter(fn (WorkItemLink $l): bool => $l->from !== null)->map(fn (WorkItemLink $l): array => [
            'relation' => __(WorkItemLink::REVERSE[$l->kind] ?? $l->kind),
            'id' => (int) $l->from->getKey(),
            'key' => $l->from->key,
            'title' => $l->from->title,
            'status' => $l->from->status?->name,
            'category' => $l->from->status?->category,
        ]);

        return $out->concat($in)->values()->all();
    }

    /**
     * The audit trail of this item: what changed, by whom. The last twenty-five.
     *
     * @return array<int, array<string, mixed>>
     */
    private function history(WorkItem $item): array
    {
        try {
            return ActivityLog::query()
                ->where('subject_type', $item->getMorphClass())
                ->where('subject_id', $item->getKey())
                ->with('causer')
                ->latest('id')
                ->limit(25)
                ->get()
                ->map(fn (ActivityLog $a): array => [
                    'who' => $a->causer?->name ?? __('The system'),
                    'initials' => self::initials($a->causer?->name),
                    'event' => (string) ($a->event ?? $a->description),
                    'fields' => collect($a->properties['attributes'] ?? [])->keys()
                        ->reject(fn (string $f): bool => in_array($f, ['rank', 'updated_at', 'assigned_at'], true))
                        ->map(fn (string $f): string => str_replace('_', ' ', str($f)->replaceEnd('_id', '')->toString()))
                        ->values()->all(),
                    'at' => $a->created_at,
                ])
                ->all();
        } catch (Throwable) {
            // A missing or unreachable audit table is no reason the item cannot be read.
            return [];
        }
    }

    /** @return array<int, array<string, mixed>> */
    private function worklog(WorkItem $item): array
    {
        return $this->time->entriesFor($item)->map(fn ($e): array => [
            'who' => $e->employee?->fullName() ?? __('Somebody'),
            'initials' => self::initials($e->employee?->fullName()),
            'minutes' => (int) $e->minutes,
            'date' => $e->date?->format('d M Y'),
            'note' => $e->description,
        ])->all();
    }

    /**
     * The priority list, with the item's own value kept when the list no longer has it. Compared
     * without case: items made before the lists were seeded hold `medium`, the list says `Medium`,
     * and offering both would be one choice drawn twice.
     *
     * @return array<string, string>
     */
    public static function priorities(string $current): array
    {
        $options = options('work.priority');
        $known = array_map('strtolower', array_map('strval', array_keys($options)));

        if ($current !== '' && ! in_array(strtolower($current), $known, true)) {
            $options = [$current => ucfirst($current)] + $options;
        }

        return $options;
    }

    public static function initials(?string $name): string
    {
        if (blank($name)) {
            return '';
        }

        return Collection::make(preg_split('/\s+/', trim((string) $name)) ?: [])
            ->filter()
            ->take(2)
            ->map(fn (string $part): string => mb_strtoupper(mb_substr($part, 0, 1)))
            ->implode('');
    }
}
