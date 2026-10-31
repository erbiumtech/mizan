<?php

namespace App\Modules\Work\Filament\Pages;

use App\Filament\Concerns\BelongsToModule;
use App\Filament\Support\HelpAction;
use App\Modules\Employees\Models\Employee;
use App\Modules\Projects\Filament\Resources\Projects\ProjectResource;
use App\Modules\Projects\Models\Project;
use App\Modules\Work\Filament\Resources\WorkItems\WorkItemResource;
use App\Modules\Work\Filament\Resources\WorkReleases\WorkReleaseResource;
use App\Modules\Work\Filament\Resources\WorkWorkflows\WorkWorkflowResource;
use App\Modules\Work\Models\WorkBoard;
use App\Modules\Work\Models\WorkItem;
use App\Modules\Work\Models\WorkStatus;
use App\Modules\Work\Models\WorkWorkflow;
use App\Modules\Work\Services\Boards;
use App\Modules\Work\Services\BoardSettings;
use App\Modules\Work\Services\WorkItemPanel;
use App\Modules\Work\Services\WorkItems;
use BackedEnum;
use Closure;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Enums\Width;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\Support\Htmlable;
use InvalidArgumentException;
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

    /**
     * Which of the project's boards — in the address as `board`, like the project and the filters,
     * so a link opens the board it was copied from. Empty is the project's first board.
     */
    #[Url(as: 'board')]
    public ?int $boardId = null;

    #[Url]
    public bool $mine = false;

    #[Url]
    public bool $unassigned = false;

    #[Url]
    public bool $blocked = false;

    #[Url]
    public ?string $search = null;

    /** One person's cards, picked from the avatar stack in the toolbar — an employee id. */
    #[Url]
    public ?int $assignee = null;

    /** The toolbar's Group menu: the lanes for this view only, without changing the board's own setting. */
    #[Url]
    public ?string $group = null;

    /**
     * The item open in the panel — in the address as `item`, so "copy link" on the panel is the
     * address bar, and a link someone sends opens the board with the item already showing.
     */
    #[Url(as: 'item')]
    public ?int $selected = null;

    /** The panel's comment box and its "add subtask" line. */
    public string $comment = '';

    public string $subtask = '';

    /** Polled when there is no socket to push with — see `pollingInterval()`. */
    public function getTitle(): string|Htmlable
    {
        return $this->board()?->name ?? __('Board');
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
        return __('Board');
    }

    /** "Work · {project}" over the title, as the Material board design reads. */
    public function getBreadcrumbs(): array
    {
        return array_values(array_filter([__('Work'), $this->currentProject()?->name]));
    }

    /**
     * List | Board | Timeline — the same project in three of its views, in that order, and the core of
     * the space tabs over the board (`tabs()`). A link is null when its page is not reachable here.
     *
     * @return array<int, array{label: string, url: ?string, current: bool}>
     */
    public function viewSwitch(): array
    {
        $project = $this->currentProject()?->getKey();

        return [
            [
                'label' => __('List'),
                'url' => WorkItemResource::canAccess()
                    ? WorkItemResource::getUrl('index', $project !== null ? ['filters' => ['project_id' => ['value' => $project]]] : [])
                    : null,
                'current' => false,
            ],
            ['label' => __('Board'), 'url' => static::getUrl($project !== null ? ['project' => $project] : []), 'current' => true],
            [
                'label' => __('Timeline'),
                'url' => Timeline::canAccess() ? Timeline::getUrl($project !== null ? ['project' => $project] : []) : null,
                'current' => false,
            ],
        ];
    }

    protected function getHeaderActions(): array
    {
        return [
            HelpAction::make('work-board', 'Board: Help'),
            $this->boardSettingsAction(),
            ActionGroup::make([
                $this->newBoardAction(),
                $this->editWorkflowAction(),
                $this->resetColumnsAction(),
                $this->deleteBoardAction(),
            ])
                ->label(__('Boards'))
                ->icon('heroicon-o-ellipsis-vertical')
                ->button()
                ->color('gray'),
        ];
    }

    /**
     * The board's shape: its name, its kind, the lanes, and the columns with the statuses each holds
     * and how many cards each takes. `BoardSettings` keeps the rules; the repeater's own rule says
     * them before anything is saved.
     */
    protected function boardSettingsAction(): Action
    {
        return Action::make('boardSettings')
            ->label(__('Board settings'))
            ->icon('heroicon-o-adjustments-horizontal')
            // A Material text button beside the List | Board | Timeline control.
            ->link()
            ->visible(fn (): bool => ($board = $this->board()) !== null && (auth()->user()?->can('update', $board) ?? false))
            ->modalHeading(fn (): string => __('Settings for :board', ['board' => $this->board()?->name ?? __('Board')]))
            ->modalWidth(Width::FourExtraLarge)
            ->fillForm(fn (): array => app(BoardSettings::class)->formState($this->board()))
            ->schema([
                TextInput::make('name')
                    ->label(__('Name'))
                    ->required()
                    ->maxLength(255),
                Select::make('kind')
                    ->label(__('Kind'))
                    ->options(self::kinds())
                    ->required()
                    ->native(false)
                    ->helperText(__('A Scrum board shows the active sprint\'s items; a Kanban board shows every open item.')),
                Select::make('swimlane')
                    ->label(__('Lanes'))
                    ->options(fn (): array => array_map(fn (string $label): string => __($label), WorkBoard::SWIMLANES))
                    ->required()
                    ->native(false),
                Toggle::make('shows_subtasks')
                    ->label(__('Show subtasks as cards'))
                    ->helperText(__('Off, a subtask is counted on its parent\'s card instead.')),
                Repeater::make('columns')
                    ->label(__('Columns'))
                    // A column can only hold statuses the workflow has; a new one is added there. A new tab,
                    // so the settings typed here so far are not lost.
                    ->hintAction(
                        Action::make('editWorkflowFromSettings')
                            ->label(__('Edit workflow'))
                            ->icon('heroicon-m-arrow-top-right-on-square')
                            ->url(fn (): ?string => $this->workflowEditUrl(), shouldOpenInNewTab: true)
                            ->visible(fn (): bool => $this->workflowEditUrl() !== null),
                    )
                    ->schema([
                        TextInput::make('label')
                            ->label(__('Column name'))
                            ->required()
                            ->maxLength(60),
                        Select::make('status_ids')
                            ->label(__('Statuses'))
                            ->multiple()
                            ->options(fn (): array => $this->statusOptions())
                            ->required()
                            ->native(false),
                        TextInput::make('limit')
                            ->label(__('Card limit'))
                            ->integer()
                            ->minValue(1)
                            ->nullable()
                            ->placeholder(__('No limit')),
                    ])
                    ->columns(3)
                    ->reorderable()
                    ->minItems(1)
                    ->addActionLabel(__('Add column'))
                    ->itemLabel(fn (array $state): ?string => $state['label'] ?? null)
                    ->rules([
                        fn (): Closure => function (string $attribute, mixed $value, Closure $fail): void {
                            $workflow = $this->workflow();

                            if ($workflow !== null && ($problem = app(BoardSettings::class)->columnProblem(is_array($value) ? $value : [], $workflow))) {
                                $fail($problem);
                            }
                        },
                    ])
                    ->helperText(__('A status may be in one column only. A status in no column is named above the board, so its cards are never lost silently.')),
            ])
            ->action(function (array $data): void {
                $board = $this->board();
                $workflow = $this->workflow();

                if ($board === null || $workflow === null) {
                    return;
                }

                try {
                    app(BoardSettings::class)->save($board, $data, $workflow);
                } catch (InvalidArgumentException $e) {
                    Notification::make()->danger()->title(__('The board was not saved'))->body($e->getMessage())->send();

                    return;
                }

                Notification::make()->success()->title(__('Board saved'))->send();
            });
    }

    /** A further board on this project, starting from one column per status. */
    protected function newBoardAction(): Action
    {
        return Action::make('newBoard')
            ->label(__('New board'))
            ->icon('heroicon-o-plus')
            ->visible(fn (): bool => $this->currentProject() !== null && $this->workflow() !== null
                && (auth()->user()?->can('create', WorkBoard::class) ?? false))
            ->schema([
                TextInput::make('name')
                    ->label(__('Name'))
                    ->required()
                    ->maxLength(255),
                Select::make('kind')
                    ->label(__('Kind'))
                    ->options(self::kinds())
                    ->default(WorkBoard::KANBAN)
                    ->required()
                    ->native(false),
            ])
            ->action(function (array $data): void {
                $project = $this->currentProject();
                $workflow = $this->workflow();

                if ($project === null || $workflow === null) {
                    return;
                }

                try {
                    $board = app(BoardSettings::class)->create($project, (string) $data['name'], (string) $data['kind'], $workflow);
                } catch (InvalidArgumentException $e) {
                    Notification::make()->danger()->title(__('The board was not created'))->body($e->getMessage())->send();

                    return;
                }

                $this->boardId = (int) $board->getKey();
                Notification::make()->success()->title(__('Board created'))->send();
            });
    }

    /** Back to one column per status, limits cleared. */
    protected function resetColumnsAction(): Action
    {
        return Action::make('resetColumns')
            ->label(__('Reset columns from workflow'))
            ->icon('heroicon-o-arrow-path')
            ->requiresConfirmation()
            ->modalDescription(__('Every column is replaced by one per status of the project\'s workflow, in order, and every card limit is cleared. Cards do not move.'))
            ->visible(fn (): bool => ($board = $this->board()) !== null && (auth()->user()?->can('update', $board) ?? false))
            ->action(function (): void {
                $board = $this->board();
                $workflow = $this->workflow();

                if ($board === null || $workflow === null) {
                    return;
                }

                app(BoardSettings::class)->reset($board, $workflow);
                Notification::make()->success()->title(__('Columns reset from the workflow'))->send();
            });
    }

    /** Any board but the project's last one. */
    protected function deleteBoardAction(): Action
    {
        return Action::make('deleteBoard')
            ->label(__('Delete this board'))
            ->icon('heroicon-o-trash')
            ->color('danger')
            ->requiresConfirmation()
            ->modalDescription(__('Only the board goes: its items, statuses and history stay, and show on the project\'s other boards.'))
            ->visible(fn (): bool => ($board = $this->board()) !== null
                && count($this->boards()) > 1
                && (auth()->user()?->can('delete', $board) ?? false))
            ->action(function (): void {
                $board = $this->board();

                if ($board === null) {
                    return;
                }

                try {
                    app(BoardSettings::class)->delete($board);
                } catch (InvalidArgumentException $e) {
                    Notification::make()->danger()->title(__('The board was not deleted'))->body($e->getMessage())->send();

                    return;
                }

                $this->boardId = null;
                Notification::make()->success()->title(__('Board deleted'))->send();
            });
    }

    /** @return array<string, string> */
    private static function kinds(): array
    {
        return [WorkBoard::KANBAN => __('Kanban'), WorkBoard::SCRUM => __('Scrum')];
    }

    /** @return array<int, string> the project's workflow statuses, in order */
    private function statusOptions(): array
    {
        return $this->workflow()?->statuses->mapWithKeys(fn ($status): array => [(int) $status->getKey() => $status->name])->all() ?? [];
    }

    /** A different project starts on that project's first board. */
    public function updatedProject(): void
    {
        $this->boardId = null;
    }

    /** @return array<int, string> this project's boards, the default first */
    public function boards(): array
    {
        $project = $this->currentProject();

        if ($project === null || $this->board() === null) {
            return [];
        }

        return WorkBoard::query()->where('project_id', $project->getKey())->orderBy('id')->pluck('name', 'id')->all();
    }

    /**
     * The project's workflow, to add, rename or reorder the statuses a board's columns map. Only for
     * whoever may edit that workflow; the default workflow is the same record every project without
     * its own shares, so editing it reaches them too.
     */
    protected function editWorkflowAction(): Action
    {
        return Action::make('editWorkflow')
            ->label(__('Edit workflow'))
            ->icon('heroicon-o-arrow-path-rounded-square')
            ->url(fn (): ?string => $this->workflowEditUrl())
            ->visible(fn (): bool => $this->workflowEditUrl() !== null);
    }

    private function workflowEditUrl(): ?string
    {
        $workflow = $this->workflow();

        if ($workflow === null || ! (auth()->user()?->can('update', $workflow) ?? false)) {
            return null;
        }

        return WorkWorkflowResource::getUrl('edit', ['record' => $workflow]);
    }

    private function workflow(): ?WorkWorkflow
    {
        $project = $this->currentProject();

        return $project === null ? null : WorkWorkflow::forProject($project);
    }

    /** Projects this reader may pick between, which is also what the empty state is about. */
    public function projects(): array
    {
        return Project::query()->orderBy('name')->pluck('name', 'id')->all();
    }

    /**
     * The board on screen: the one the address names, if it is this project's, else the project's
     * first — made on first view if the project has none.
     */
    public function board(): ?WorkBoard
    {
        $project = $this->currentProject();
        $workflow = $this->workflow();

        if ($project === null || $workflow === null) {
            return null;
        }

        $default = WorkBoard::defaultFor($project, $workflow);

        if ($this->boardId === null) {
            return $default;
        }

        return WorkBoard::query()->where('project_id', $project->getKey())->whereKey($this->boardId)->first() ?? $default;
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

        if ($board === null) {
            return [];
        }

        // Grouping from the toolbar is this view's, not the board's: set on the model, never saved.
        if ($this->group !== null && array_key_exists($this->group, WorkBoard::SWIMLANES)) {
            $board->swimlane = $this->group;
        }

        return app(Boards::class)->columns($board, $this->filters());
    }

    /** @return array<string, mixed> */
    public function filters(): array
    {
        return [
            'mine' => $this->mine,
            'unassigned' => $this->unassigned,
            'blocked' => $this->blocked,
            'search' => $this->search,
        ] + ($this->assignee !== null ? ['assignee_employee_id' => $this->assignee] : []);
    }

    /** Statuses this board shows nowhere, named on the screen rather than kept quiet — §5.2. */
    public function unmapped(): array
    {
        $board = $this->board();
        $workflow = $this->workflow();

        if ($board === null || $workflow === null) {
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
     * The avatar stack in the toolbar: who has open cards on this board, most cards first. One query
     * for the ids and one for the people, whatever the size of the board.
     *
     * @return array<int, array{id: int, name: string, initials: string, on: bool}>
     */
    public function people(): array
    {
        $board = $this->board();

        if ($board === null || $board->project_id === null) {
            return [];
        }

        $ids = WorkItem::query()
            ->where('project_id', $board->project_id)
            ->whereNull('archived_at')
            ->whereNotNull('assignee_employee_id')
            ->selectRaw('assignee_employee_id, count(*) as cards')
            ->groupBy('assignee_employee_id')
            ->orderByDesc('cards')
            ->limit(8)
            ->pluck('assignee_employee_id')
            ->map(fn ($id): int => (int) $id)
            ->all();

        if ($ids === []) {
            return [];
        }

        $people = Employee::query()->with('user')->whereKey($ids)->get()->keyBy(fn (Employee $e): int => (int) $e->getKey());

        return collect($ids)
            ->filter(fn (int $id): bool => $people->has($id))
            ->map(fn (int $id): array => [
                'id' => $id,
                'name' => $people[$id]->fullName(),
                'initials' => WorkItemPanel::initials($people[$id]->fullName()),
                'on' => $this->assignee === $id,
            ])
            ->values()
            ->all();
    }

    /** The avatar stack: pick one person, or the same one again to show everybody. */
    public function pickAssignee(int $employee): void
    {
        $this->assignee = $this->assignee === $employee ? null : $employee;
    }

    /**
     * The Group menu's choices — the board's own lane setting is the default.
     *
     * @return array<string, string>
     */
    public function groupings(): array
    {
        return array_map(fn (string $label): string => __($label), WorkBoard::SWIMLANES);
    }

    public function currentGrouping(): string
    {
        return $this->group ?? ($this->board()?->swimlane ?: WorkBoard::SWIMLANE_NONE);
    }

    /** How many of the Filter menu's choices are on, for the count on its button. */
    public function activeFilterCount(): int
    {
        return count(array_filter([$this->mine, $this->unassigned, $this->blocked]));
    }

    /**
     * The space tabs over the board, as the project's own navigation has them: each a page this
     * reader may open, for the same project. Built on `viewSwitch()` for List, Board and Timeline.
     *
     * @return array<int, array{label: string, url: ?string, current: bool}>
     */
    public function tabs(): array
    {
        $project = $this->currentProject()?->getKey();
        $byProject = $project !== null ? ['project' => $project] : [];
        [$list, $board, $timeline] = $this->viewSwitch();

        $tabs = [
            [
                'label' => __('Summary'),
                'url' => $project !== null && ProjectResource::canAccess() ? ProjectResource::getUrl('view', ['record' => $project]) : null,
                'current' => false,
            ],
            ['label' => __('Backlog'), 'url' => Backlog::canAccess() ? Backlog::getUrl($byProject) : null, 'current' => false],
            $board,
            $list,
            $timeline,
            ['label' => __('Epics'), 'url' => Epics::canAccess() ? Epics::getUrl($byProject) : null, 'current' => false],
            [
                'label' => __('Releases'),
                'url' => WorkReleaseResource::canAccess()
                    ? WorkReleaseResource::getUrl('index', $project !== null ? ['tableFilters' => ['project_id' => ['value' => $project]]] : [])
                    : null,
                'current' => false,
            ],
        ];

        return array_values(array_filter($tabs, fn (array $tab): bool => $tab['current'] || filled($tab['url'])));
    }

    public function projectName(): ?string
    {
        return $this->currentProject()?->name;
    }

    /**
     * "+ Create" at the foot of a column: a title, and the item starts in that column.
     *
     * Made through `WorkItems::create()` like every other item, then moved through `moveTo()` into
     * the column's first status — so a column the workflow will not allow straight from the start
     * refuses here exactly as a drag would, and the item stays in the first column, said out loud.
     */
    public function quickAdd(int $column, string $title): void
    {
        $title = trim($title);
        $board = $this->board();
        $project = $this->currentProject();

        if ($title === '' || $board === null || $project === null || $board->project_id === null) {
            return;
        }

        if (! (auth()->user()?->can('create', WorkItem::class) ?? false)) {
            Notification::make()->danger()->title(__('You may read this board but not add to it.'))->send();

            return;
        }

        $item = app(WorkItems::class)->create($project, ['title' => mb_substr($title, 0, 255)], auth()->user());
        $statusId = $board->columns[$column]['status_ids'][0] ?? null;
        $status = $statusId !== null && (int) $statusId !== (int) $item->work_status_id ? WorkStatus::query()->find($statusId) : null;

        if ($status !== null) {
            try {
                $item->moveTo($status, auth()->user());
            } catch (RuntimeException $e) {
                Notification::make()->warning()->title(__(':key was added to the first column', ['key' => $item->key]))->body($e->getMessage())->send();

                return;
            }
        }

        Notification::make()->success()->title(__(':key added', ['key' => $item->key]))->send();
    }

    /** A card was clicked: the panel opens on it. */
    public function openItem(int $id): void
    {
        $record = WorkItem::query()->find($id);

        if ($record === null || ! (auth()->user()?->can('view', $record) ?? false)) {
            return;
        }

        $this->selected = $id;
        $this->comment = '';
        $this->subtask = '';
    }

    public function closeItem(): void
    {
        $this->selected = null;
        $this->comment = '';
        $this->subtask = '';
    }

    /**
     * What the panel draws, or null when nothing is open or the open item is not the reader's to see.
     *
     * @return array<string, mixed>|null
     */
    public function panel(): ?array
    {
        if ($this->selected === null) {
            return null;
        }

        $record = app(WorkItemPanel::class)->find($this->selected);
        $user = auth()->user();

        if ($record === null || $user === null || ! $user->can('view', $record)) {
            return null;
        }

        return app(WorkItemPanel::class)->present($record, $user) + [
            'can_update' => $user->can('update', $record),
            'can_move' => $user->can('move', $record),
            'can_create' => $user->can('create', WorkItem::class),
        ];
    }

    /** The panel's status menu: the same move a drag makes, and the same refusals. */
    public function setItemStatus(int $status): void
    {
        $record = $this->selectedRecord('move');
        $target = $record?->workflow()?->statuses()->whereKey($status)->first();

        if ($record === null || $target === null) {
            return;
        }

        try {
            $record->moveTo($target, auth()->user());
        } catch (RuntimeException $e) {
            Notification::make()->danger()->title(__('It stayed where it was'))->body($e->getMessage())->send();
        }
    }

    public function setItemPriority(string $priority): void
    {
        $record = $this->selectedRecord('update');

        if ($record === null || ! array_key_exists($priority, WorkItemPanel::priorities((string) $record->priority))) {
            return;
        }

        $record->update(['priority' => $priority]);
    }

    /** "Assign to me": the reader's own employee record, or a sentence saying there is none. */
    public function assignToMe(): void
    {
        $record = $this->selectedRecord('update');

        if ($record === null) {
            return;
        }

        $me = Employee::query()->where('user_id', auth()->id())->value('id');

        if ($me === null) {
            Notification::make()->warning()->title(__('Your login has no employee record to assign to.'))->send();

            return;
        }

        $record->update(['assignee_employee_id' => $me]);
    }

    public function watchItem(): void
    {
        $record = $this->selectedRecord('view');

        if ($record !== null && auth()->user() !== null) {
            $record->watch(auth()->user());
        }
    }

    /** The comment box. Commenting needs only reading, as the API has it. */
    public function addComment(): void
    {
        $body = trim($this->comment);
        $record = $this->selectedRecord('view');

        if ($record === null || $body === '') {
            return;
        }

        $record->comments()->create(['user_id' => auth()->id(), 'body' => mb_substr($body, 0, 10000)]);
        $this->comment = '';
    }

    /** "Add subtask": a subtask under the open item, in the same project. */
    public function addSubtask(): void
    {
        $title = trim($this->subtask);
        $record = $this->selectedRecord('view');

        if ($record === null || $title === '' || $record->isOfType(WorkItem::TYPE_SUBTASK)) {
            return;
        }

        if (! (auth()->user()?->can('create', WorkItem::class) ?? false)) {
            Notification::make()->danger()->title(__('You may read this item but not change it.'))->send();

            return;
        }

        $project = Project::query()->find($record->project_id);

        if ($project === null) {
            return;
        }

        app(WorkItems::class)->create($project, [
            'title' => mb_substr($title, 0, 255),
            'type' => WorkItem::TYPE_SUBTASK,
            'parent_id' => $record->getKey(),
        ], auth()->user());

        $this->subtask = '';
    }

    /** The open item, if the reader may do `$ability` to it; a sentence saying why not otherwise. */
    private function selectedRecord(string $ability): ?WorkItem
    {
        $record = $this->selected === null ? null : WorkItem::query()->find($this->selected);

        if ($record === null) {
            return null;
        }

        if (! (auth()->user()?->can($ability, $record) ?? false)) {
            Notification::make()->danger()->title(__('You may read this item but not change it.'))->send();

            return null;
        }

        return $record;
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
