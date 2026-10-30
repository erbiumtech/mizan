<?php

namespace App\Modules\Work\Filament\Pages;

use App\Filament\Concerns\BelongsToModule;
use App\Filament\Support\HelpAction;
use App\Modules\Projects\Models\Project;
use App\Modules\Work\Models\WorkItem;
use App\Modules\Work\Models\WorkStatus;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\Collection;
use Livewire\Attributes\Url;
use UnitEnum;

/**
 * The Epics of a project, each with how far it has got — docs/project-management-plan.md §6.3.
 *
 * An Epic is a work item with children; nothing here is a table of its own. Progress is done
 * children over all children, by count and by points, and it is computed on the way through rather
 * than stored — a child reopened after the fact takes the bar back down.
 *
 * **An Epic is done when the company says so, not when its last child is.** The bar reaching the
 * end is the moment to ask; closing it is still a decision, because the last child being merged
 * and the feature being finished are not always the same day.
 */
class Epics extends Page
{
    use BelongsToModule;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedFlag;

    protected static string|UnitEnum|null $navigationGroup = 'Projects';

    protected static ?int $navigationSort = 19;

    protected string $view = 'filament.work.epics';

    #[Url]
    public ?int $project = null;

    /** Closed Epics are hidden by default: the list is for what is in flight. */
    #[Url]
    public bool $done = false;

    public function getTitle(): string|Htmlable
    {
        return __('Epics');
    }

    /**
     * The module and the permission. `BelongsToModule::canAccess()` checks the module alone, and a
     * panel user whose role lacks `WorkItemView` must not read a board through this page when the
     * item list and the release list refuse them.
     */
    public static function canAccess(): bool
    {
        return static::moduleIsAvailable() && (auth()->user()?->can('viewAny', WorkItem::class) ?? false);
    }

    public static function getNavigationLabel(): string
    {
        return __('Epics');
    }

    protected function getHeaderActions(): array
    {
        return [
            HelpAction::make('work-epics', 'Epics: Help'),
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

    /**
     * The Epics, each with its children loaded so progress costs one query for the lot.
     *
     * @return Collection<int, array{epic: WorkItem, progress: array<string, mixed>, open: Collection<int, WorkItem>, done: Collection<int, WorkItem>}>
     */
    public function epics(): Collection
    {
        $project = $this->currentProject();

        if ($project === null) {
            return collect();
        }

        return WorkItem::query()
            ->where('project_id', $project->getKey())
            ->whereNull('archived_at')
            ->epics()
            ->when(! $this->done, fn ($q) => $q->whereHas('status', fn ($s) => $s->where('category', '!=', WorkStatus::DONE)))
            ->with([
                'status',
                'assignee.user',
                'children' => fn ($q) => $q->whereNull('archived_at')->with('status', 'assignee.user')->orderBy('rank'),
            ])
            ->orderBy('rank')
            ->get()
            ->map(fn (WorkItem $epic): array => [
                'epic' => $epic,
                'progress' => $epic->progress(),
                'open' => $epic->children->reject(fn (WorkItem $c): bool => $c->isDone())->values(),
                'done' => $epic->children->filter(fn (WorkItem $c): bool => $c->isDone())->values(),
            ]);
    }
}
