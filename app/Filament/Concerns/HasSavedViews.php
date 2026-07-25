<?php

namespace App\Filament\Concerns;

use App\Models\TableView;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Illuminate\Support\Collection;

/**
 * Adds saved "table views" to a Filament ListRecords page: save the current
 * filters/columns/sort/search as a named view, apply saved or preset views,
 * favorite/share them, and auto-apply the user's default view on load.
 *
 * Everything is scoped to the current company via the TableView global scope
 * (spatie teams) — see [[permission-teams-model]].
 *
 * Usage: `use HasSavedViews;` on the ListRecords page, then merge
 * `$this->savedViewActions()` into `getHeaderActions()`. Optionally override
 * `presetViews()` to ship developer-defined views.
 */
trait HasSavedViews
{
    public function mountHasSavedViews(): void
    {
        if ($view = $this->defaultView()) {
            $this->applyViewState($view->state ?? []);
        }
    }

    /** Stable key tying views to this resource. */
    protected function savedViewsKey(): string
    {
        return method_exists($this, 'getResource') ? static::getResource() : static::class;
    }

    /**
     * Developer-defined preset views. Override per page. Each: [
     *   'key' => string, 'name' => string, 'icon' => ?string,
     *   'color' => ?string, 'state' => array (filters/columns/sort/search) ].
     *
     * @return array<int, array<string, mixed>>
     */
    public function presetViews(): array
    {
        return [];
    }

    // ---- state capture / restore -------------------------------------------

    protected function captureViewState(): array
    {
        return [
            'filters' => $this->tableFilters ?? [],
            'columns' => $this->tableColumns ?? [],
            'columnSearches' => $this->tableColumnSearches ?? [],
            'search' => $this->tableSearch ?? null,
            'sort' => $this->tableSort ?? null,
            'grouping' => $this->tableGrouping ?? null,
        ];
    }

    public function applyViewState(array $state): void
    {
        $this->tableFilters = $state['filters'] ?? [];
        if (! empty($state['columns'])) {
            $this->tableColumns = $state['columns'];
        }
        $this->tableColumnSearches = $state['columnSearches'] ?? [];
        $this->tableSearch = $state['search'] ?? '';
        $this->tableSort = $state['sort'] ?? null;
        $this->tableGrouping = $state['grouping'] ?? null;

        if (method_exists($this, 'resetPage')) {
            $this->resetPage();
        }
    }

    // ---- data ---------------------------------------------------------------

    protected function savedViews(): Collection
    {
        $user = auth()->user();

        if (! $user) {
            return collect();
        }

        return TableView::query()
            ->visibleTo($user, $this->savedViewsKey())
            ->orderByDesc('is_favorite')
            ->orderBy('sort')
            ->orderBy('name')
            ->get();
    }

    protected function defaultView(): ?TableView
    {
        $user = auth()->user();

        if (! $user) {
            return null;
        }

        return TableView::query()
            ->where('resource', $this->savedViewsKey())
            ->where('user_id', $user->getKey())
            ->where('is_default', true)
            ->first();
    }

    // ---- actions ------------------------------------------------------------

    /**
     * @return array<int, Action|ActionGroup>
     */
    protected function savedViewActions(): array
    {
        $viewItems = [];

        foreach ($this->presetViews() as $preset) {
            $viewItems[] = Action::make('preset_'.($preset['key'] ?? md5($preset['name'])))
                ->label($preset['name'])
                ->icon($preset['icon'] ?? 'heroicon-o-view-columns')
                ->color($preset['color'] ?? 'gray')
                ->action(fn () => $this->applyViewState($preset['state'] ?? []));
        }

        foreach ($this->savedViews() as $view) {
            $viewItems[] = Action::make('view_'.$view->getKey())
                ->label($view->name)
                ->icon($view->icon ?: ($view->is_favorite ? 'heroicon-s-star' : 'heroicon-o-bookmark'))
                ->color($view->color ?: 'gray')
                ->badge($view->is_global ? 'Global' : ($view->is_public ? 'Shared' : null))
                ->action(fn () => $this->applyViewState($view->state ?? []));
        }

        $actions = [];

        if ($viewItems) {
            $actions[] = ActionGroup::make($viewItems)
                ->label('Views')
                ->icon('heroicon-o-eye')
                ->button();
        }

        $actions[] = $this->saveViewAction();

        return $actions;
    }

    protected function saveViewAction(): Action
    {
        $user = auth()->user();
        $canShare = $user && $user->can('setGlobal', TableView::class);

        return Action::make('saveView')
            ->label('Save view')
            ->icon('heroicon-o-bookmark-square')
            ->color('gray')
            ->schema(array_values(array_filter([
                TextInput::make('name')->required()->maxLength(255),
                Select::make('icon')->options([
                    'heroicon-o-star' => 'Star',
                    'heroicon-o-flag' => 'Flag',
                    'heroicon-o-funnel' => 'Filter',
                    'heroicon-o-clock' => 'Clock',
                    'heroicon-o-check-circle' => 'Check',
                ])->native(false)->nullable(),
                Select::make('color')->options([
                    'primary' => 'Primary', 'success' => 'Green', 'warning' => 'Amber',
                    'danger' => 'Red', 'info' => 'Blue', 'gray' => 'Gray',
                ])->native(false)->nullable(),
                Toggle::make('is_favorite')->label('Add to favorites')->default(true),
                Toggle::make('is_default')->label('Make this my default view'),
                $canShare ? Toggle::make('is_public')->label('Share with everyone in this company') : null,
            ])))
            ->action(function (array $data): void {
                $user = auth()->user();

                if (! empty($data['is_default'])) {
                    TableView::query()
                        ->where('resource', $this->savedViewsKey())
                        ->where('user_id', $user->getKey())
                        ->update(['is_default' => false]);
                }

                TableView::create([
                    'user_id' => $user->getKey(),
                    'resource' => $this->savedViewsKey(),
                    'name' => $data['name'],
                    'icon' => $data['icon'] ?? null,
                    'color' => $data['color'] ?? null,
                    'is_favorite' => (bool) ($data['is_favorite'] ?? false),
                    'is_default' => (bool) ($data['is_default'] ?? false),
                    'is_public' => (bool) ($data['is_public'] ?? false),
                    'state' => $this->captureViewState(),
                ]);

                Notification::make()->title('View saved.')->success()->send();
            });
    }
}
