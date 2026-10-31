<?php

namespace App\Modules\Work\Http\Controllers\Api;

use App\Http\ApiResponse;
use App\Http\Controllers\Controller;
use App\Modules\Core\Models\User;
use App\Modules\Projects\Models\Project;
use App\Modules\Work\Models\WorkBoard;
use App\Modules\Work\Models\WorkItem;
use App\Modules\Work\Models\WorkSprint;
use App\Modules\Work\Models\WorkStatus;
use App\Modules\Work\Models\WorkWorkflow;
use App\Modules\Work\Services\Boards;
use App\Modules\Work\Services\WorkItems;
use App\Modules\Work\Services\WorkTime;
use App\Modules\Work\Support\Rank;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * The surface a mobile board needs and nothing it does not — docs/project-management-plan.md §11.
 *
 * Every write goes through the same services the screens use: `WorkItems::create()` for the key,
 * `WorkItem::moveTo()` for the transition (the same rules, the same refusals as 422s with the
 * reason), `WorkTime::log()` for an hour. So a write from a phone is attributed to the token's user
 * in the activity log and reaches §10's audience like any other, because it is any other.
 *
 * Items are addressed by **key** (`WEB-12`) rather than id, because the key is what a person has.
 */
class WorkApiController extends Controller
{
    use AuthorizesRequests;

    /** The fields of §3.2 a client may write, by name. */
    private const WRITABLE = [
        'title', 'description', 'type', 'priority', 'assignee_employee_id', 'parent_id', 'sprint_id',
        'release_id', 'estimate_points', 'estimate_minutes', 'due_on',
    ];

    /** `GET /work/projects` — the projects the token's user may see, with key and open count. */
    public function projects(Request $request): JsonResponse
    {
        $this->authorize('viewAny', WorkItem::class);

        $projects = Project::query()
            ->whereIn('id', WorkItem::query()->select('project_id'))
            ->orWhereNotNull('key')
            ->orderBy('name')
            ->get()
            ->map(fn (Project $project): array => [
                'id' => (int) $project->getKey(),
                'key' => $project->key,
                'code' => $project->code,
                'name' => $project->name,
                'status' => $project->status,
                'open_items' => WorkItem::query()->where('project_id', $project->getKey())->open()->count(),
                'active_sprint' => WorkSprint::query()->where('project_id', $project->getKey())->active()->value('name'),
            ]);

        return ApiResponse::success(['data' => $projects->values()->all()]);
    }

    /** `GET /work/projects/{key}/board` — columns with their cards, in rank order. */
    public function board(Request $request, string $key): JsonResponse
    {
        $this->authorize('viewAny', WorkItem::class);

        $project = Project::query()->where('key', strtoupper($key))->first();

        if ($project === null) {
            return ApiResponse::error('No project has the key '.$key.'.', 404);
        }

        $workflow = $project->work_workflow_id !== null
            ? WorkWorkflow::query()->with('statuses')->whereKey($project->work_workflow_id)->first()
            : WorkWorkflow::default()?->load('statuses');

        if ($workflow === null) {
            return ApiResponse::error('This company has no workflow to draw a board from.', 404);
        }

        $board = WorkBoard::defaultFor($project, $workflow);

        // `?board=` picks another of this project's boards; the first is the default, as on screen.
        if ($request->filled('board')) {
            $board = WorkBoard::query()->where('project_id', $project->getKey())->whereKey((int) $request->query('board'))->first();

            if ($board === null) {
                return ApiResponse::error('This project has no board with that id.', 404);
            }
        }

        return ApiResponse::success([
            'board' => ['id' => (int) $board->getKey(), 'name' => $board->name, 'kind' => $board->kind],
            'columns' => app(Boards::class)->columns($board, $this->quickFilters($request)),
        ]);
    }

    /** `GET /work/items?project=&status_category=&assignee=me&sprint=active&search=` */
    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', WorkItem::class);

        $query = WorkItem::query()
            ->with(['project', 'status', 'assignee.user', 'labels'])
            ->whereNull('archived_at')
            ->when($request->filled('project'), function (Builder $q) use ($request): void {
                $project = Project::query()->where('key', strtoupper((string) $request->query('project')))->first();
                $q->where('project_id', $project?->getKey() ?? 0);
            })
            ->when($request->filled('status_category'), fn (Builder $q) => $q->whereHas('status', fn (Builder $s) => $s->where('category', $request->query('status_category'))))
            ->when($request->query('assignee') === 'me', fn (Builder $q) => $q->whereHas('assignee', fn (Builder $e) => $e->where('user_id', $request->user()->getKey())))
            ->when($request->filled('assignee') && $request->query('assignee') !== 'me', fn (Builder $q) => $q->where('assignee_employee_id', $request->query('assignee')))
            ->when($request->query('sprint') === 'active', fn (Builder $q) => $q->whereIn('sprint_id', WorkSprint::query()->active()->select('id')))
            ->when($request->filled('sprint') && $request->query('sprint') !== 'active', fn (Builder $q) => $q->where('sprint_id', $request->query('sprint')))
            ->when($request->filled('search'), fn (Builder $q) => $q->where(fn (Builder $s) => $s
                ->where('key', 'like', '%'.strtoupper((string) $request->query('search')).'%')
                ->orWhere('title', 'like', '%'.$request->query('search').'%')))
            ->orderBy('rank');

        $page = $query->paginate(min(200, max(1, $request->integer('per_page', 50))));

        return ApiResponse::success([
            'data' => collect($page->items())->map(fn (WorkItem $item): array => $this->item($item))->all(),
            'pagination' => ['total' => $page->total(), 'per_page' => $page->perPage(), 'current_page' => $page->currentPage(), 'last_page' => $page->lastPage()],
        ]);
    }

    /** `POST /work/items` — a project key and a title are enough; everything else takes a default. */
    public function store(Request $request): JsonResponse
    {
        $this->authorize('create', WorkItem::class);

        $data = $request->validate([
            'project' => ['required', 'string'],
            'title' => ['required', 'string', 'max:255'],
            ...$this->rules(),
        ]);

        $project = Project::query()->where('key', strtoupper($data['project']))->first();

        if ($project === null) {
            return ApiResponse::error('No project has the key '.$data['project'].'.', 404);
        }

        unset($data['project']);

        try {
            $item = app(WorkItems::class)->create($project, $data, $request->user());
        } catch (RuntimeException $e) {
            return ApiResponse::error($e->getMessage(), 422);
        }

        return ApiResponse::success(['data' => $this->item($item->load(['project', 'status', 'assignee.user', 'labels']))], 201);
    }

    /** `GET /work/items/{key}` */
    public function show(Request $request, string $key): JsonResponse
    {
        $item = $this->find($key);

        if ($item === null) {
            return ApiResponse::error('No work item has the key '.$key.'.', 404);
        }

        $this->authorize('view', $item);

        return ApiResponse::success(['data' => $this->item($item, full: true)]);
    }

    /** `PATCH /work/items/{key}` — the fields of §3.2 by name. Status is not among them: that is `move`. */
    public function update(Request $request, string $key): JsonResponse
    {
        $item = $this->find($key);

        if ($item === null) {
            return ApiResponse::error('No work item has the key '.$key.'.', 404);
        }

        $this->authorize('update', $item);

        $data = $request->validate(['title' => ['sometimes', 'string', 'max:255'], ...$this->rules()]);

        try {
            $item->fill($data)->save();
        } catch (RuntimeException $e) {
            return ApiResponse::error($e->getMessage(), 422);
        }

        return ApiResponse::success(['data' => $this->item($item->fresh(['project', 'status', 'assignee.user', 'labels']), full: true)]);
    }

    /**
     * `POST /work/items/{key}/move` — status by id or name, plus optional neighbours for rank.
     *
     * The same `moveTo()` the board calls, the same rules, the same refusals as 422s with the reason.
     */
    public function move(Request $request, string $key): JsonResponse
    {
        $item = $this->find($key);

        if ($item === null) {
            return ApiResponse::error('No work item has the key '.$key.'.', 404);
        }

        $this->authorize('move', $item);

        $data = $request->validate([
            'status' => ['required'],
            'resolution' => ['nullable', 'string', 'max:30'],
            'after' => ['nullable', 'integer'],
            'before' => ['nullable', 'integer'],
        ]);

        $workflow = $item->workflow();
        $statuses = $workflow?->statuses()->get() ?? collect();

        $target = is_numeric($data['status'])
            ? $statuses->firstWhere('id', (int) $data['status'])
            : $statuses->first(fn (WorkStatus $s): bool => strcasecmp($s->name, (string) $data['status']) === 0);

        if ($target === null) {
            return ApiResponse::error('"'.$data['status'].'" is not a status of this item\'s workflow.', 422, [
                'statuses' => $statuses->map(fn (WorkStatus $s): array => ['id' => (int) $s->getKey(), 'name' => $s->name, 'category' => $s->category])->values()->all(),
            ]);
        }

        if (array_key_exists('after', $data) || array_key_exists('before', $data)) {
            $item->rank = Rank::between(
                isset($data['after']) ? WorkItem::query()->whereKey($data['after'])->value('rank') : null,
                isset($data['before']) ? WorkItem::query()->whereKey($data['before'])->value('rank') : null,
            );
            $item->save();
        }

        try {
            $item->moveTo($target, $request->user(), $data['resolution'] ?? null);
        } catch (RuntimeException $e) {
            return ApiResponse::error($e->getMessage(), 422);
        }

        return ApiResponse::success(['data' => $this->item($item->fresh(['project', 'status', 'assignee.user', 'labels']))]);
    }

    /** `POST /work/items/{key}/comments` */
    public function comment(Request $request, string $key): JsonResponse
    {
        $item = $this->find($key);

        if ($item === null) {
            return ApiResponse::error('No work item has the key '.$key.'.', 404);
        }

        $this->authorize('view', $item);

        $data = $request->validate(['body' => ['required', 'string', 'max:10000']]);

        $comment = $item->comments()->create(['user_id' => $request->user()->getKey(), 'body' => $data['body']]);

        return ApiResponse::success(['data' => ['id' => (int) $comment->getKey(), 'body' => $comment->body, 'created_at' => $comment->created_at?->toIso8601String()]], 201);
    }

    /** `POST /work/items/{key}/time` — minutes, a date, a note; one timesheet row, nothing else. */
    public function time(Request $request, string $key): JsonResponse
    {
        $item = $this->find($key);

        if ($item === null) {
            return ApiResponse::error('No work item has the key '.$key.'.', 404);
        }

        $this->authorize('update', $item);

        $data = $request->validate([
            'minutes' => ['required', 'integer', 'min:1', 'max:1440'],
            'date' => ['nullable', 'date'],
            'note' => ['nullable', 'string', 'max:2000'],
            'billable' => ['nullable', 'boolean'],
        ]);

        try {
            $entry = app(WorkTime::class)->log($item, $request->user(), (int) $data['minutes'], $data['date'] ?? today()->toDateString(), $data['note'] ?? null, (bool) ($data['billable'] ?? true));
        } catch (RuntimeException $e) {
            return ApiResponse::error($e->getMessage(), 422);
        }

        return ApiResponse::success(['data' => ['id' => (int) $entry->getKey(), 'minutes' => $entry->minutes, 'date' => $entry->date->toDateString()]], 201);
    }

    /** `GET /work/my` — the "My work" board as data. */
    public function my(Request $request): JsonResponse
    {
        $this->authorize('viewAny', WorkItem::class);

        /** @var User $user */
        $user = $request->user();
        $board = app(Boards::class)->myWork($user);

        return ApiResponse::success(['columns' => app(Boards::class)->columns($board)]);
    }

    // ---------------------------------------------------------------- helpers

    private function find(string $key): ?WorkItem
    {
        return WorkItem::query()->with(['project', 'status', 'assignee.user', 'labels'])->where('key', strtoupper($key))->first();
    }

    /** @return array<string, array<int, string>> */
    private function rules(): array
    {
        return [
            'description' => ['nullable', 'string'],
            'type' => ['nullable', 'string', 'max:20'],
            'priority' => ['nullable', 'string', 'max:20'],
            'assignee_employee_id' => ['nullable', 'integer'],
            'parent_id' => ['nullable', 'integer'],
            'sprint_id' => ['nullable', 'integer'],
            'release_id' => ['nullable', 'integer'],
            'estimate_points' => ['nullable', 'numeric', 'min:0'],
            'estimate_minutes' => ['nullable', 'integer', 'min:0'],
            'due_on' => ['nullable', 'date'],
        ];
    }

    /** @return array<string, mixed> */
    private function quickFilters(Request $request): array
    {
        return [
            'mine' => $request->boolean('mine'),
            'unassigned' => $request->boolean('unassigned'),
            'blocked' => $request->boolean('blocked'),
            'search' => $request->query('search'),
        ];
    }

    /** @return array<string, mixed> */
    private function item(WorkItem $item, bool $full = false): array
    {
        $data = [
            'id' => (int) $item->getKey(),
            'key' => $item->key,
            'project' => $item->project?->key,
            'type' => $item->type,
            'title' => $item->title,
            'status' => $item->status === null ? null : ['id' => (int) $item->status->getKey(), 'name' => $item->status->name, 'category' => $item->status->category],
            'priority' => $item->priority,
            'assignee' => $item->assignee === null ? null : ['employee_id' => (int) $item->assignee->getKey(), 'name' => $item->assignee->display_label],
            'parent_id' => $item->parent_id !== null ? (int) $item->parent_id : null,
            'sprint_id' => $item->sprint_id !== null ? (int) $item->sprint_id : null,
            'release_id' => $item->release_id !== null ? (int) $item->release_id : null,
            'estimate_points' => $item->estimate_points !== null ? (float) $item->estimate_points : null,
            'estimate_minutes' => $item->estimate_minutes,
            'due_on' => $item->due_on?->toDateString(),
            'labels' => $item->labels->pluck('name')->all(),
            'rank' => $item->rank,
            'started_at' => $item->started_at?->toIso8601String(),
            'resolved_at' => $item->resolved_at?->toIso8601String(),
            'resolution' => $item->resolution,
            'updated_at' => $item->updated_at?->toIso8601String(),
        ];

        if ($full) {
            $data['description'] = $item->description;
            $data['comments'] = $item->comments()->orderBy('id')->get()->map(fn ($c): array => [
                'id' => (int) $c->getKey(), 'user_id' => $c->user_id, 'body' => $c->body, 'created_at' => $c->created_at?->toIso8601String(),
            ])->all();
        }

        return $data;
    }
}
