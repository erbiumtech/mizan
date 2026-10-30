<?php

namespace App\Modules\Work\Filament\Pages;

use App\Filament\Concerns\BelongsToModule;
use App\Filament\Support\HelpAction;
use App\Modules\Projects\Models\Project;
use App\Modules\Work\Models\WorkItem;
use App\Modules\Work\Models\WorkSprint;
use App\Modules\Work\Services\WorkItems;
use App\Modules\Work\Support\Rank;
use BackedEnum;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\Support\Htmlable;
use Livewire\Attributes\Url;
use UnitEnum;

/**
 * The ranked list of what is not yet in a sprint — docs/project-management-plan.md §6.1.
 *
 * **Written at speed and refined later**, which is the whole design of the inline create: a title
 * and a type, nothing else, because a backlog is filled during a conversation. The item's full form
 * is one click away and never in the way.
 *
 * Order is the same `rank` string the board uses, so dragging here writes one row rather than
 * renumbering everything below it.
 */
class Backlog extends Page
{
    use BelongsToModule;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedQueueList;

    protected static string|UnitEnum|null $navigationGroup = 'Projects';

    protected static ?int $navigationSort = 18;

    protected string $view = 'filament.work.backlog';

    #[Url]
    public ?int $project = null;

    /**
     * What the inline create row holds between keystrokes.
     *
     * **Not `$title`**, and the name is load-bearing: Filament's `BasePage` declares
     * `static ?string $title`, so a non-static `public string $title` here is a fatal at boot —
     * *"Cannot redeclare static BasePage::$title as non static"* — which takes down every page and
     * every artisan command, not only this screen. Found the hard way.
     */
    public string $newTitle = '';

    public string $type = 'Task';

    /** @var array<int, int> */
    public array $selected = [];

    public function getTitle(): string|Htmlable
    {
        return __('Backlog');
    }

    public static function getNavigationLabel(): string
    {
        return __('Backlog');
    }

    protected function getHeaderActions(): array
    {
        return [
            HelpAction::make('work-backlog', 'Backlog: Help'),
        ];
    }

    public function projects(): array
    {
        return Project::query()->orderBy('name')->pluck('name', 'id')->all();
    }

    public function currentProject(): ?Project
    {
        return $this->project !== null
            ? Project::query()->find($this->project)
            : Project::query()->orderBy('name')->first();
    }

    /** Open, unarchived, in nobody's sprint, in rank order. */
    public function items()
    {
        $project = $this->currentProject();

        if ($project === null) {
            return collect();
        }

        return WorkItem::query()
            ->where('project_id', $project->getKey())
            ->backlog()
            ->with(['assignee.user', 'status', 'labels'])
            ->orderBy('rank')
            ->get();
    }

    /** Sprints this backlog can be fed into: anything not closed. */
    public function sprints(): array
    {
        $project = $this->currentProject();

        return $project === null ? [] : WorkSprint::query()
            ->where('project_id', $project->getKey())
            ->where('state', '!=', WorkSprint::CLOSED)
            ->orderBy('starts_on')
            ->pluck('name', 'id')
            ->all();
    }

    public function types(): array
    {
        return options('work.type');
    }

    /**
     * One row, created from a title.
     *
     * Everything else takes a default — the workflow's first status, the backlog's bottom, nobody
     * assigned — because the alternative is a form, and a form is what stops a backlog being
     * written during the conversation that produced it.
     */
    public function add(): void
    {
        $project = $this->currentProject();

        if ($project === null || trim($this->newTitle) === '') {
            return;
        }

        if (! auth()->user()?->can('create', WorkItem::class)) {
            Notification::make()->danger()->title('You may not add items to this backlog.')->send();

            return;
        }

        app(WorkItems::class)->create($project, [
            'title' => trim($this->newTitle),
            'type' => $this->type,
        ], auth()->user());

        $this->newTitle = '';
    }

    /** Dragged: one row rewritten, between its two new neighbours. */
    public function reorder(int $item, ?int $after = null, ?int $before = null): void
    {
        $record = WorkItem::query()->find($item);

        if ($record === null || ! auth()->user()?->can('move', $record)) {
            return;
        }

        $record->forceFill(['rank' => Rank::between(
            $after !== null ? WorkItem::query()->whereKey($after)->value('rank') : null,
            $before !== null ? WorkItem::query()->whereKey($before)->value('rank') : null,
        )])->save();
    }

    /**
     * Whatever is ticked, into a sprint — or back out of one.
     *
     * Bulk because planning is done in handfuls: a backlog is groomed by choosing eight things and
     * saying "those", not by opening eight forms.
     */
    public function moveToSprint(?int $sprint = null): void
    {
        if ($this->selected === []) {
            return;
        }

        $target = $sprint !== null ? WorkSprint::query()->find($sprint) : null;

        if ($sprint !== null && $target === null) {
            return;
        }

        $moved = WorkItem::query()
            ->whereKey($this->selected)
            ->get()
            ->filter(fn (WorkItem $item): bool => auth()->user()?->can('update', $item) ?? false);

        foreach ($moved as $item) {
            $item->forceFill(['sprint_id' => $target?->getKey()])->save();
        }

        $this->selected = [];

        Notification::make()->success()
            ->title($moved->count().' item(s) moved')
            ->body($target !== null ? 'Into '.$target->name.'.' : 'Back to the backlog.')
            ->send();
    }
}
