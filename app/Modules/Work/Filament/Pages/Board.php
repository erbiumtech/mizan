<?php

namespace App\Modules\Work\Filament\Pages;

use App\Filament\Concerns\BelongsToModule;
use App\Filament\Support\HelpAction;
use App\Modules\Projects\Models\Project;
use App\Modules\Work\Models\WorkBoard;
use App\Modules\Work\Models\WorkItem;
use App\Modules\Work\Models\WorkWorkflow;
use App\Modules\Work\Services\Boards;
use BackedEnum;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\Support\Htmlable;
use Livewire\Attributes\Url;
use RuntimeException;
use UnitEnum;

/**
 * The Kanban board — docs/project-management-plan.md §5.
 *
 * **This screen decides nothing.** The board row says which items and which columns, `Boards`
 * turns that into cards, and `WorkItem::moveTo()` decides whether a drag is allowed. What is left
 * here is Livewire: holding the filters, handling a drop, and saying why one was refused.
 *
 * The quick filters live in the URL, so a filtered board is a link somebody can send — which is the
 * difference between a board and a report nobody else can reproduce.
 */
class Board extends Page
{
    use BelongsToModule;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedViewColumns;

    protected static string|UnitEnum|null $navigationGroup = 'Projects';

    protected static ?int $navigationSort = 15;

    protected string $view = 'filament.work.board';

    #[Url]
    public ?int $project = null;

    #[Url]
    public bool $mine = false;

    #[Url]
    public bool $unassigned = false;

    #[Url]
    public bool $blocked = false;

    #[Url]
    public ?string $search = null;

    /** Polled when there is no socket to push with — see `pollingInterval()`. */
    public function getTitle(): string|Htmlable
    {
        return $this->board()?->name ?? __('Board');
    }

    public static function getNavigationLabel(): string
    {
        return __('Board');
    }

    protected function getHeaderActions(): array
    {
        return [
            HelpAction::make('work-board', 'Board: Help'),
        ];
    }

    /** Projects this reader may pick between, which is also what the empty state is about. */
    public function projects(): array
    {
        return Project::query()->orderBy('name')->pluck('name', 'id')->all();
    }

    public function board(): ?WorkBoard
    {
        $project = $this->currentProject();

        if ($project === null) {
            return null;
        }

        $workflow = $project->work_workflow_id !== null
            ? WorkWorkflow::query()->with('statuses')->whereKey($project->work_workflow_id)->first()
            : WorkWorkflow::default()?->load('statuses');

        if ($workflow === null) {
            return null;
        }

        return WorkBoard::defaultFor($project, $workflow);
    }

    private function currentProject(): ?Project
    {
        if ($this->project !== null) {
            return Project::query()->find($this->project);
        }

        // The one a person is most likely to want: the project they have work in.
        $project = Project::query()->whereKey(
            WorkItem::query()->whereNull('archived_at')->orderByDesc('updated_at')->value('project_id'),
        )->first();

        return $project ?? Project::query()->orderBy('name')->first();
    }

    /** @return array<int, array<string, mixed>> */
    public function columns(): array
    {
        $board = $this->board();

        return $board === null ? [] : app(Boards::class)->columns($board, $this->filters());
    }

    /** @return array<string, mixed> */
    public function filters(): array
    {
        return [
            'mine' => $this->mine,
            'unassigned' => $this->unassigned,
            'blocked' => $this->blocked,
            'search' => $this->search,
        ];
    }

    /** Statuses this board shows nowhere, named on the screen rather than kept quiet — §5.2. */
    public function unmapped(): array
    {
        $board = $this->board();
        $project = $this->currentProject();

        if ($board === null || $project === null) {
            return [];
        }

        $workflow = $project->work_workflow_id !== null
            ? WorkWorkflow::query()->with('statuses')->whereKey($project->work_workflow_id)->first()
            : WorkWorkflow::default()?->load('statuses');

        if ($workflow === null) {
            return [];
        }

        return \App\Modules\Work\Models\WorkStatus::query()
            ->whereKey($board->unmappedStatusIds($workflow))
            ->pluck('name')
            ->all();
    }

    /**
     * A card was dropped: move it, or say why it stayed.
     *
     * Both refusals — the column's limit and the workflow's rules — arrive as exceptions, and both
     * end the same way: a notification with the reason and a re-render, which puts the card back
     * where it was because the card's position is drawn from the data rather than from the drag.
     */
    public function moveCard(int $item, int $column, ?int $after = null, ?int $before = null): void
    {
        $board = $this->board();
        $record = WorkItem::query()->find($item);

        if ($board === null || $record === null) {
            return;
        }

        if (! auth()->user()?->can('move', $record)) {
            Notification::make()->danger()->title('You may read this board but not move cards on it.')->send();

            return;
        }

        try {
            app(Boards::class)->move($board, $record, $column, $after, $before, auth()->user());
        } catch (RuntimeException $e) {
            Notification::make()->danger()->title('It stayed where it was')->body($e->getMessage())->send();
        }
    }

    /**
     * Live when there is a socket, polled when there is not — §5.6.
     *
     * The rule the rest of the application follows: nothing is queued for a broadcaster that is not
     * configured, and a board that silently stopped updating is worse than one that says it checks
     * every thirty seconds.
     */
    public function getPollingInterval(): ?string
    {
        return \App\Support\Broadcasting::isConfigured() ? null : '30s';
    }
}
