<?php

namespace App\Filament\Concerns;

use App\Support\ModuleMap;
use App\Models\TableView;
use Filament\Actions\Action;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Components\ToggleButtons;
use Filament\Notifications\Notification;
use Illuminate\Support\Collection;
use Illuminate\Support\HtmlString;
use Illuminate\Support\Str;

/**
 * Saved "table views" for a Filament ListRecords page, rendered as a custom bar
 * in the table header (favorite tabs on the left; quick-save + views panel on
 * the right — the Advanced Tables layout). Saves the current
 * filters/columns/sort/search/grouping as a named view, applies saved/preset
 * views, favorites/shares them, and auto-applies the user's default on load.
 *
 * Scoped to the current company via the TableView global scope (spatie teams).
 *
 * Usage: `use HasSavedViews;` on the ListRecords page, and add
 * `->header(view('filament.tables.saved-views-bar'))` to the resource table.
 */
trait HasSavedViews
{
    public ?int $activeSavedViewId = null;

    public function mountHasSavedViews(): void
    {
        if ($view = $this->defaultView()) {
            $this->activeSavedViewId = $view->getKey();
            $this->applyViewState($view->state ?? []);
        }
    }

    /**
     * Stable key tying views to this resource.
     *
     * Deliberately the resource's *alias* rather than its current class name:
     * `table_views.resource` holds this string, so returning the live FQCN would
     * orphan every saved view of every user the day the resource moves into its
     * module directory.
     */
    protected function savedViewsKey(): string
    {
        return ModuleMap::alias(
            method_exists($this, 'getResource') ? static::getResource() : static::class
        );
    }

    /**
     * Developer-defined preset views. Override per page. Each:
     * ['key','name','icon','color','state'].
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

    // ---- Livewire actions called from the bar -------------------------------

    public function applySavedView(int $id): void
    {
        if ($view = $this->findSavedView($id)) {
            $this->activeSavedViewId = $view->getKey();
            $this->applyViewState($view->state ?? []);
        }
    }

    public function applyPresetView(string $key): void
    {
        foreach ($this->presetViews() as $preset) {
            if (($preset['key'] ?? null) === $key) {
                $this->activeSavedViewId = null;
                $this->applyViewState($preset['state'] ?? []);

                return;
            }
        }
    }

    public function resetSavedView(): void
    {
        $this->activeSavedViewId = null;
        $this->applyViewState([]);
    }

    public function saveCurrentView(string $name, bool $isFavorite = false): void
    {
        $name = trim($name);
        if ($name === '') {
            Notification::make()->title('Please enter a view name.')->danger()->send();

            return;
        }

        $view = TableView::create([
            'user_id' => auth()->id(),
            'resource' => $this->savedViewsKey(),
            'name' => $name,
            'is_favorite' => $isFavorite,
            'state' => $this->captureViewState(),
        ]);

        $this->activeSavedViewId = $view->getKey();
        Notification::make()->title('View saved.')->success()->send();
    }

    /**
     * Slide-over "Save view" form (name / icon / color / favorite / public /
     * summary). Registered in the page's getHeaderActions() and triggered by the
     * bar's "+" and the panel's "Save" via $wire.mountAction('saveView').
     */
    public function saveViewAction(): Action
    {
        $canShare = (bool) auth()->user()?->can('setGlobal', TableView::class);

        return Action::make('saveView')
            ->label('Save view')
            ->icon('heroicon-o-plus')
            ->iconButton()
            ->tooltip('Save current view')
            ->color('gray')
            ->slideOver()
            ->modalWidth('md')
            ->modalHeading('Save view')
            ->modalSubmitActionLabel('Save view')
            ->schema(array_values(array_filter([
                TextInput::make('name')
                    ->required()
                    ->maxLength(255)
                    ->autofocus(),

                Select::make('icon')
                    ->native(false)
                    ->options([
                        'heroicon-o-star' => 'Star',
                        'heroicon-o-flag' => 'Flag',
                        'heroicon-o-funnel' => 'Filter',
                        'heroicon-o-clock' => 'Clock',
                        'heroicon-o-check-circle' => 'Check',
                        'heroicon-o-exclamation-triangle' => 'Warning',
                        'heroicon-o-fire' => 'Fire',
                        'heroicon-o-truck' => 'Truck',
                    ]),

                ToggleButtons::make('color')
                    ->inline()
                    ->options([
                        'success' => 'Green',
                        'info' => 'Blue',
                        'warning' => 'Amber',
                        'danger' => 'Red',
                        'gray' => 'Gray',
                    ])
                    ->colors([
                        'success' => 'success',
                        'info' => 'info',
                        'warning' => 'warning',
                        'danger' => 'danger',
                        'gray' => 'gray',
                    ]),

                Toggle::make('is_favorite')
                    ->label('Add to favorites')
                    ->helperText('Add this view to your favorites')
                    ->default(true),

                Toggle::make('is_default')
                    ->label('Make default')
                    ->helperText('Load this view automatically when you open the table'),

                $canShare ? Toggle::make('is_public')
                    ->label('Make public')
                    ->helperText('Make this view available to everyone in this company') : null,

                Placeholder::make('summary')
                    ->label('View summary')
                    ->content(fn (): HtmlString => new HtmlString($this->viewSummaryHtml())),
            ])))
            ->action(function (array $data): void {
                $user = auth()->user();

                if (! empty($data['is_default'])) {
                    TableView::query()
                        ->where('resource', $this->savedViewsKey())
                        ->where('user_id', $user->getKey())
                        ->update(['is_default' => false]);
                }

                $view = TableView::create([
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

                $this->activeSavedViewId = $view->getKey();
                Notification::make()->title('View saved.')->success()->send();
            });
    }

    /** Human-readable chips describing what the current view captures. */
    protected function viewSummaryHtml(): string
    {
        $chips = [];

        if (! empty($this->tableSearch)) {
            $chips[] = 'Search: '.$this->tableSearch;
        }

        foreach (($this->tableFilters ?? []) as $name => $state) {
            $values = collect((array) $state)->flatten()->filter(fn ($v) => $v !== null && $v !== '' && $v !== false);
            if ($values->isNotEmpty()) {
                $chips[] = 'Filter: '.Str::headline($name);
            }
        }

        if (! empty($this->tableSort)) {
            [$col, $dir] = array_pad(explode(':', (string) $this->tableSort), 2, 'asc');
            $chips[] = 'Sort: '.Str::headline($col).' '.strtoupper($dir);
        }

        if (! empty($this->tableGrouping)) {
            $chips[] = 'Group by: '.Str::headline((string) $this->tableGrouping);
        }

        if (empty($chips)) {
            return '<span style="color:#9ca3af;font-size:.8125rem">No filters, sort or grouping applied — this saves the current column layout.</span>';
        }

        $html = '<div style="display:flex;flex-wrap:wrap;gap:.35rem">';
        foreach ($chips as $chip) {
            $html .= '<span style="background:rgba(var(--primary-500,245 158 11),.12);color:rgb(var(--primary-700,180 83 9));font-size:.75rem;padding:.15rem .5rem;border-radius:6px">'.e($chip).'</span>';
        }

        return $html.'</div>';
    }

    public function deleteSavedView(int $id): void
    {
        $view = $this->findSavedView($id);

        if ($view && auth()->user()?->can('delete', $view)) {
            if ($this->activeSavedViewId === $view->getKey()) {
                $this->resetSavedView();
            }
            $view->delete();
            Notification::make()->title('View deleted.')->success()->send();
        }
    }

    public function setDefaultSavedView(int $id): void
    {
        $view = $this->findSavedView($id);
        $user = auth()->user();

        if (! $view || ! $user) {
            return;
        }

        TableView::query()
            ->where('resource', $this->savedViewsKey())
            ->where('user_id', $user->getKey())
            ->update(['is_default' => false]);

        if ($view->user_id === $user->getKey()) {
            $view->update(['is_default' => true]);
            Notification::make()->title('Default view set.')->success()->send();
        }
    }

    // ---- data ---------------------------------------------------------------

    protected function findSavedView(int $id): ?TableView
    {
        return $this->savedViews()->firstWhere('id', $id);
    }

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

    /**
     * Data for the saved-views bar Blade partial.
     *
     * @return array<string, mixed>
     */
    public function getViewsBarData(): array
    {
        $userId = auth()->id();
        $views = $this->savedViews();

        $map = fn (TableView $v): array => [
            'id' => $v->getKey(),
            'name' => $v->name,
            'icon' => $v->icon,
            'color' => $v->color,
            'is_default' => $v->is_default,
            'owned' => $v->user_id === $userId,
        ];

        return [
            'activeId' => $this->activeSavedViewId,
            'favorites' => $views->where('is_favorite', true)->map($map)->values()->all(),
            'mine' => $views->where('user_id', $userId)->where('is_favorite', false)->map($map)->values()->all(),
            'shared' => $views->where('user_id', '!=', $userId)->map($map)->values()->all(),
            'presets' => collect($this->presetViews())->map(fn ($p) => [
                'key' => $p['key'] ?? md5($p['name']),
                'name' => $p['name'],
                'icon' => $p['icon'] ?? null,
            ])->all(),
            'canShare' => (bool) auth()->user()?->can('setGlobal', TableView::class),
        ];
    }
}
