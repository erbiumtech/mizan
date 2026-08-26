<?php

namespace App\Modules\Core\Filament\Pages;

use App\Filament\Concerns\BelongsToModule;
use App\Filament\Support\HelpAction;
use App\Modules\Core\Models\ReportDefinition;
use App\Modules\Core\Models\SavedReportView;
use App\Support\Reporting\BuiltReport;
use App\Support\Reporting\DatasetColumn;
use App\Support\Reporting\DatasetFilter;
use App\Support\Reporting\DatasetRegistry;
use App\Support\Reporting\ExportsTheOpenReport;
use App\Support\Reporting\RelativePeriod;
use App\Support\Reporting\ReportCatalogue;
use App\Support\Reporting\ReportComparison;
use App\Support\Reporting\ReportPaneRenderer;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Livewire\Attributes\Url;

/**
 * One door to every report.
 *
 * The sidebar used to carry a Reports group of fourteen entries — the
 * statements, the ageing, the payroll and bank files and two interactive
 * ledgers, all at one level with nothing to say which was which. They are all
 * still here, grouped and described, behind a single link.
 *
 * Each link is filtered through the owning page's own canAccess(), so this page
 * never offers a report that would refuse to open: not one whose module the
 * company has not licensed, and not one the role has no permission for. When
 * that leaves nothing, the page itself disappears from the sidebar rather than
 * greeting somebody with an empty screen.
 *
 * Lives in Core, not Accounting, because it spans four modules — and Core is the
 * one module that is always on, which is what lets the per-link gates above be
 * the only thing deciding what appears.
 */
class Reports extends Page
{
    use BelongsToModule;
    use ExportsTheOpenReport;

    protected string $view = 'filament.pages.reports';

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-chart-pie';

    protected static ?string $title = 'Reports';

    /**
     * Top level, immediately below the Dashboard (which is -2) and above every
     * group. Deliberately not in a group of its own: a group named Reports
     * holding a single item named Reports says the same thing twice.
     */
    protected static ?int $navigationSort = -1;

    /*
     * The eighteen reports this page used to list are registered by the modules that own them —
     * App\Support\Reporting\ReportCatalogue, written to from each module's service provider.
     *
     * This file's own comment used to explain itself as "lives in Core, not Accounting, because it spans
     * four modules". That was the right instinct and the wrong destination: something spanning four modules
     * belongs above all four, and in this application "above" means a registry the four write to rather
     * than a list inside the one module that is always licensed. See docs/module-packaging-plan.md §9.
     *
     * Adding a report still means one line — it is just a line in that report's own module now, and
     * ReportsHubTest still fails for a page that is hidden from the sidebar and registered nowhere.
     */

    /**
     * Every page this hub links to, ungrouped.
     *
     * @return array<int, class-string>
     */
    public static function linkedPages(): array
    {
        return ReportCatalogue::pages();
    }

    // -------------------------------------------------------- the 4c explorer

    /**
     * Which section is showing. Null is all of them.
     *
     * In the query string rather than in component state alone, so that a filtered view can be
     * linked to and lands filtered — "the payroll ones" is a thing people send each other. #[Url]
     * also means the browser's back button steps back through the filters, which is what a person
     * expects from something that changes what is on screen.
     */
    #[Url]
    public ?string $section = null;

    /** The filter box. Deliberately not in the URL: a half-typed word is not a place to return to. */
    public string $query = '';

    /**
     * The report being read, by key.
     *
     * In the URL for the same reason as the date beside it: "the balance sheet as of 30 June" is a link
     * somebody sends, and 4c's whole premise is that the pane is a view of a report rather than a step
     * in a flow. Which means the key arrives from the browser — so mount() puts it through the same
     * check select() applies, and for the same reason.
     */
    #[Url]
    public ?string $selected = null;

    /**
     * The date the statement in the right-hand pane is drawn to.
     *
     * In the URL, because a statement at a date is the thing people send each other — "the balance sheet
     * at the end of June" is a link, not an instruction. Defaults in mount() rather than here so it is
     * today's date at the moment of asking rather than at the moment the class was loaded.
     */
    #[Url]
    public ?string $asOf = null;

    /**
     * Whether the prior year's column is shown. A preference, so it travels too.
     *
     * **Superseded by `$compare` in Phase 4.2 and kept because links carry it.** A URL somebody saved says
     * `?comparison=0`, and answering that with the comparison back on would be a small betrayal of a
     * bookmark. `mount()` translates it once and nothing else reads it.
     */
    #[Url]
    public bool $comparison = true;

    /**
     * What the statement is compared against — `App\Support\Reporting\ReportComparison`.
     *
     * A basis rather than a boolean, because Phase 4.2 adds the previous month and the previous quarter to
     * the previous year. Nullable so that `mount()` can tell "not specified" from "specified as none", which
     * is what lets the legacy flag still mean something.
     */
    #[Url]
    public ?string $compare = null;

    /**
     * What the three reports that need more than a date are looking at.
     *
     * The account register needs an account, find-transactions a search, budget-vs-actual a budget. In the
     * URL with the rest, so a register of one account at one date is as linkable as a balance sheet is —
     * and `null` means "whatever the pane would pick", which is what makes them open without a form.
     */
    #[Url]
    public int|string|null $account = null;

    #[Url]
    public int|string|null $budget = null;

    #[Url]
    public string $find = '';

    /**
     * The filing month, for the two reports that are read a month at a time.
     *
     * Null is the whole fiscal year, which is the more useful default of the two: a tax summary is
     * reconciled for the year and filed for a month, and only one of those can be the thing that opens.
     */
    #[Url]
    public ?string $month = null;

    public function mount(): void
    {
        $this->asOf ??= now()->toDateString();

        // The basis, from whichever of the two the link carried. An explicit `?compare=` wins; a bare
        // `?comparison=` is translated; neither means the previous year, as it always did.
        $this->compare = $this->compare === null
            ? ReportComparison::fromLegacyFlag($this->comparison)
            : ReportComparison::normalise($this->compare);

        // The key came off the query string, so it gets the same treatment as one that came off a click:
        // anything not in this role's catalogue is refused. Without this, `?selected=` would be a way to
        // have the pane render a link to a report the role cannot open — see select().
        if (filled($this->selected)) {
            $this->select($this->selected);
        }
    }

    /**
     * The statement for the selected report, with its comparison column.
     *
     * Null covers three cases the pane draws differently: nothing selected yet, a report whose shape
     * this cannot draw without asking for input first (see ReportPane — it offers that report's own
     * page instead), and a
     * report the role cannot open, which select() has already refused.
     *
     * @return array<string, mixed>|null
     */
    public function statement(): ?array
    {
        // While a report is being assembled the pane shows *that* report — see the builder below. Which is
        // what item 7 means by "reads it in the same pane as every built-in report": the preview is not a
        // preview of the report, it is the report, drawn by the one renderer.
        if ($this->building) {
            return app(BuiltReport::class)->for($this->draftDefinition(), $this->asOf ?: now()->toDateString());
        }

        $report = $this->selectedReport();

        if ($report === null || ! app(ReportPaneRenderer::class)->supportsReport($report['key'])) {
            return null;
        }

        return app(ReportPaneRenderer::class)->for(
            $report['key'],
            $this->asOf ?: now()->toDateString(),
            $this->comparisonBasis(),
            [
                'account' => $this->account,
                'budget' => $this->budget,
                'search' => $this->find,
                'month' => $this->month,
            ],
        );
    }

    // ------------------------------------------------ the builder (Phase 6.7)

    /**
     * Whether a report is being assembled — `docs/reports-expansion-plan.md` Phase 6, item 7.
     *
     * **A mode of this page rather than a screen of its own**, which is Phase 7's arranger decision applied
     * to the other half of the plan and for the same two reasons. The first is that item 4 already decided a
     * built report has no page: it is drawn in this pane so that it inherits the pane, and a builder anywhere
     * else would have to reproduce the pane to show what it was building. The second is that it costs nothing
     * when nobody is building — this property is false, the form is not rendered, and no dataset is asked for
     * its columns.
     *
     * Not in the URL, for the same reason `$query` and `$viewName` are not: a half-assembled report is not a
     * place to return to, and a link that opened somebody else's unsaved draft would be a link to nothing.
     */
    public bool $building = false;

    /**
     * The report being edited, by report key, or null while building a new one.
     *
     * What makes Save a rename rather than a duplicate — see `ReportDefinition::put()`'s `$replacing`.
     */
    public ?string $editing = null;

    /**
     * The report being assembled.
     *
     * A plain array bound straight to the form, and every value in it is checked twice before it reaches a
     * query: `ReportDefinition::sanitise()` when the preview is drawn and again when it is saved. So the form
     * may hold anything the browser sends — the draft is not the boundary, the registry is.
     *
     * `sort_column` and `sort_direction` are flat rather than a nested `sort` array because Livewire binds to
     * a path that exists, and a nullable nested array is a path that does not.
     *
     * @var array<string, mixed>
     */
    public array $draft = [];

    /** The subjects this person may build over, grouped by module — the picker's options. */
    public function subjects(): array
    {
        return DatasetRegistry::labels();
    }

    /** The subject being built over, or null before one is chosen. */
    public function subject(): ?string
    {
        return DatasetRegistry::find(is_string($this->draft['dataset'] ?? null) ? $this->draft['dataset'] : null);
    }

    /**
     * Start a new report.
     *
     * Gated on `ReportBuild` here as well as in `ReportDefinition::put()`, because a mode that opens and then
     * refuses to save is a worse answer than a button that was never offered.
     */
    public function startBuilding(): void
    {
        if (! $this->canBuild()) {
            return;
        }

        $this->editing = null;
        $this->draft = static::emptyDraft();
        $this->building = true;
    }

    /**
     * Open one of my own reports for editing.
     *
     * Mine only. A shared report belongs to whoever made it — somebody else editing it would change what
     * every reader of it sees, and "save a copy of my own" is the same as building a new one, which the
     * button beside it already does.
     */
    public function editReport(string $key): void
    {
        $definition = $this->ownedDefinition($key);

        if ($definition === null || ! $this->canBuild()) {
            return;
        }

        $settings = $definition->settings();

        $this->editing = $definition->reportKey();
        $this->draft = [
            'dataset' => $definition->dataset,
            'name' => (string) $definition->name,
            'description' => (string) $definition->description,
            'is_public' => (bool) $definition->is_public,
            'columns' => $settings['columns'],
            'filters' => $settings['filters'],
            'group_by' => $settings['group_by'],
            'aggregates' => $settings['aggregates'],
            'sort_column' => $settings['sort']['column'] ?? null,
            'sort_direction' => $settings['sort']['direction'] ?? ReportDefinition::ASCENDING,
            'period' => $settings['period'],
        ];
        $this->building = true;
    }

    public function cancelBuilding(): void
    {
        $this->building = false;
        $this->editing = null;
        $this->draft = [];
    }

    /**
     * Keep the report, and open it.
     *
     * Opening it afterwards is the point: the thing somebody just built is the thing they want to read, and
     * leaving them on an empty form having saved is the small rudeness that makes a feature feel unfinished.
     */
    public function saveReport(): void
    {
        if (! $this->building || ! $this->canBuild()) {
            return;
        }

        $name = trim((string) ($this->draft['name'] ?? ''));

        if ($name === '' || $this->subject() === null) {
            $this->warn('A report needs a name and a subject.');

            return;
        }

        $definition = ReportDefinition::put(
            $name,
            (string) $this->draft['dataset'],
            $this->draftState(),
            filled($this->draft['description'] ?? null) ? (string) $this->draft['description'] : null,
            (bool) ($this->draft['is_public'] ?? false),
            $this->editing === null ? null : $this->ownedDefinition($this->editing),
        );

        if ($definition === null) {
            // Three ways to get here and each is worth a sentence rather than a silent no-op: the share box
            // is ticked without `ReportShare`, the new name is one of this person's own already, or the
            // subject stopped being available while the form was open.
            $this->warn('That report could not be saved. Check the name is not already used, and that you may share it.');

            return;
        }

        $this->building = false;
        $this->editing = null;
        $this->draft = [];

        $this->select($definition->reportKey());
    }

    /** Forget one of my own reports. */
    public function deleteReport(string $key): void
    {
        $definition = $this->ownedDefinition($key);

        if ($definition === null) {
            return;
        }

        $definition->delete();

        if ($this->selected === $key) {
            $this->deselect();
        }

        $this->cancelBuilding();
    }

    /**
     * Add a column, or take it out.
     *
     * Appended in the order they are chosen, because that order *is* the report's shape — which is also why
     * these are buttons rather than a checkbox group bound to an array: a checkbox group hands back the
     * order the boxes are drawn in, so every report would come out in the dataset's declaration order and
     * nobody could say why.
     */
    public function toggleColumn(string $key): void
    {
        $columns = array_values((array) ($this->draft['columns'] ?? []));

        $this->draft['columns'] = in_array($key, $columns, true)
            ? array_values(array_filter($columns, fn (string $column): bool => $column !== $key))
            : [...$columns, $key];
    }

    /** Move a chosen column one place left or right. */
    public function moveColumn(string $key, int $by): void
    {
        $columns = array_values((array) ($this->draft['columns'] ?? []));
        $at = array_search($key, $columns, true);
        $to = $at === false ? null : $at + $by;

        if ($at === false || $to < 0 || $to > count($columns) - 1) {
            return;
        }

        [$columns[$at], $columns[$to]] = [$columns[$to], $columns[$at]];

        $this->draft['columns'] = $columns;
    }

    /**
     * A subject changed, so everything chosen against the old one goes.
     *
     * Columns, filters, grouping and aggregates are all keys of a *particular* dataset. Keeping them would
     * not be dangerous — `sanitise()` drops what the new subject does not declare — but it would be
     * confusing: half a report would survive a change of subject and the half that vanished would look like
     * a bug.
     */
    public function updatedDraft(mixed $value, ?string $key = null): void
    {
        if ($key === 'dataset') {
            $this->draft = [
                ...static::emptyDraft(),
                'dataset' => $value,
                'name' => $this->draft['name'] ?? '',
                'description' => $this->draft['description'] ?? '',
                'is_public' => $this->draft['is_public'] ?? false,
            ];
        }
    }

    /** The columns the chosen subject offers. @return array<int, DatasetColumn> */
    public function subjectColumns(): array
    {
        $class = $this->subject();

        return $class === null ? [] : $class::columns();
    }

    /** The chosen columns, in the order they will be drawn. @return array<int, DatasetColumn> */
    public function chosenColumns(): array
    {
        $class = $this->subject();

        if ($class === null) {
            return [];
        }

        return array_values(array_filter(array_map(
            fn (string $key): ?DatasetColumn => $class::column($key),
            array_values((array) ($this->draft['columns'] ?? [])),
        )));
    }

    /** The filters the chosen subject offers. @return array<int, DatasetFilter> */
    public function subjectFilters(): array
    {
        $class = $this->subject();

        return $class === null ? [] : $class::filters();
    }

    /** Whether the chosen subject can be bounded by a period at all — see `Dataset::periodColumn()`. */
    public function subjectHasPeriod(): bool
    {
        $class = $this->subject();

        return $class !== null && $class::periodColumn() !== null;
    }

    /** The relative spans a report may be filed for. @return array<string, string> */
    public function periods(): array
    {
        return RelativePeriod::PERIODS;
    }

    /** The ceiling a built report is held to, for the sentence that says so — item 5. */
    public function rowCeiling(): int
    {
        return BuiltReport::MAX_ROWS;
    }

    public function canBuild(): bool
    {
        return (bool) auth()->user()?->can(ReportDefinition::BUILD);
    }

    public function canShare(): bool
    {
        return (bool) auth()->user()?->can(ReportDefinition::SHARE);
    }

    /** The open report, if it is one of mine to edit. */
    public function editableReport(): ?ReportDefinition
    {
        return $this->canBuild() ? $this->ownedDefinition((string) $this->selected) : null;
    }

    /**
     * The draft as a definition, unsaved.
     *
     * An unsaved model rather than a second payload builder, so the preview goes down exactly the path a
     * saved report does — including `settings()`, which sanitises the state against the subject. A preview
     * drawn any other way would be a second answer to "what does this report say".
     */
    private function draftDefinition(): ReportDefinition
    {
        return new ReportDefinition([
            'name' => trim((string) ($this->draft['name'] ?? '')) ?: 'Untitled report',
            'description' => (string) ($this->draft['description'] ?? ''),
            'dataset' => (string) ($this->draft['dataset'] ?? ''),
            'state' => $this->draftState(),
            'is_public' => false,
        ]);
    }

    /**
     * The draft's state in the shape a definition holds — the flat sort put back together.
     *
     * @return array<string, mixed>
     */
    private function draftState(): array
    {
        $sortColumn = $this->draft['sort_column'] ?? null;

        return [
            'columns' => array_values((array) ($this->draft['columns'] ?? [])),
            'filters' => (array) ($this->draft['filters'] ?? []),
            'group_by' => $this->draft['group_by'] ?? null,
            'aggregates' => (array) ($this->draft['aggregates'] ?? []),
            'sort' => filled($sortColumn) ? [
                'column' => (string) $sortColumn,
                'direction' => (string) ($this->draft['sort_direction'] ?? ReportDefinition::ASCENDING),
            ] : null,
            'period' => $this->draft['period'] ?? RelativePeriod::YEAR_TO_DATE,
        ];
    }

    /** A definition by key, but only if it is this person's own. */
    private function ownedDefinition(?string $key): ?ReportDefinition
    {
        $definition = ReportDefinition::forKey($key);

        return $definition !== null && (int) $definition->user_id === (int) auth()->id()
            ? $definition
            : null;
    }

    /** @return array<string, mixed> */
    private static function emptyDraft(): array
    {
        return [
            'dataset' => null,
            'name' => '',
            'description' => '',
            'is_public' => false,
            'columns' => [],
            'filters' => [],
            'group_by' => null,
            'aggregates' => [],
            'sort_column' => null,
            'sort_direction' => ReportDefinition::ASCENDING,
            'period' => RelativePeriod::YEAR_TO_DATE,
        ];
    }

    /** Say what went wrong, where the person is looking. */
    private function warn(string $message): void
    {
        Notification::make()->danger()->title($message)->send();
    }

    // ------------------------------------------------- saved views (Phase 4.5)

    /**
     * The name being typed into the save box. Not in the URL: a half-typed name is not a place to return to.
     */
    public string $viewName = '';

    /**
     * The signed-in user's saved views for the open report.
     *
     * @return \Illuminate\Database\Eloquent\Collection<int, SavedReportView>
     */
    public function savedViews(): \Illuminate\Database\Eloquent\Collection
    {
        return SavedReportView::forReport($this->selected);
    }

    /**
     * Keep the current filters under a name.
     *
     * **The date is not among them**, which is Phase 4.5's own point: the plan asks for "the filters somebody
     * uses every month", and the date is the one thing that changes every month. `SavedReportView::FILTERS`
     * is the allow-list and `asOf` is deliberately absent from it.
     */
    public function saveView(): void
    {
        $name = trim($this->viewName);

        if ($name === '' || blank($this->selected)) {
            return;
        }

        SavedReportView::put($this->selected, $name, [
            'compare' => $this->comparisonBasis(),
            'account' => $this->account,
            'budget' => $this->budget,
            'find' => $this->find,
            'month' => $this->month,
        ]);

        $this->viewName = '';
    }

    /**
     * Put a saved view's filters back on the page.
     *
     * Read through the model's own scope rather than by id alone, so a id belonging to somebody else — or to
     * another report — finds nothing. `$id` arrives from the browser like every other parameter here.
     *
     * The date is left exactly as it is, because a saved view has no opinion about it. Opening last month's
     * filters should show them against today unless the person changes the date themselves.
     */
    public function applyView(int|string $id): void
    {
        $view = SavedReportView::query()->mine()->whereKey($id)->where('report_key', $this->selected)->first();

        if ($view === null) {
            return;
        }

        $filters = $view->filters();

        // Only what the view carries. A view that stored no account must not blank an account the person has
        // since picked — the absence of a filter is not an instruction to clear one.
        foreach ($filters as $key => $value) {
            $this->{$key} = $value;
        }
    }

    /** Forget a saved view. Scoped the same way, and for the same reason. */
    public function forgetView(int|string $id): void
    {
        SavedReportView::query()->mine()->whereKey($id)->where('report_key', $this->selected)->delete();
    }

    /**
     * The basis in force, safe against anything the query string says.
     *
     * Normalised on every read rather than only in `mount()`, because Livewire writes `$compare` straight
     * from the wire when the picker changes — and a value that never went through `mount()` would otherwise
     * reach the statement unchecked.
     */
    public function comparisonBasis(): string
    {
        return ReportComparison::normalise($this->compare);
    }

    /**
     * The bases the picker offers.
     *
     * @return array<string, string>
     */
    public function comparisonBases(): array
    {
        return ReportComparison::BASES;
    }

    /**
     * The filters the open report carries, ready to render.
     *
     * Each report declares what it needs (ReportPane::ASKS) and this turns that into controls: an account
     * picker, a budget picker, a month picker, a search box. Built here rather than in the view because the
     * view should not know that a budget is a model and a month is a name — and because the *set* differs
     * per report, which is the whole reason the bar exists. The date and the comparison column are not in
     * here: they apply to nearly everything and the pane offers them unconditionally.
     *
     * @return array<int, array{ask: string, control: string, label: string, model: string, placeholder: string, options: array<int|string, string>}>
     */
    public function filters(): array
    {
        $key = $this->selectedReport()['key'] ?? null;

        if ($key === null) {
            return [];
        }

        $pane = app(ReportPaneRenderer::class);
        $asOf = $this->asOf ?: now()->toDateString();

        return array_map(fn (string $ask): array => [
            'ask' => $ask,
            // Which control to draw, decided here rather than inferred from whether there is anything to
            // pick: a company with no registerable account has an account picker with nothing in it, and
            // inferring from an empty list turned that into a *search box bound to the account id*.
            'control' => $ask === 'search' ? 'search' : 'select',
            'label' => match ($ask) {
                'account' => 'Account',
                'budget' => 'Budget',
                'month' => 'Month',
                'search' => 'Search',
                default => ucfirst($ask),
            },
            // Which property the control is bound to. `search` binds to `find` because `search` is a
            // Livewire-adjacent name and this page already had one.
            'model' => $ask === 'search' ? 'find' : $ask,
            'placeholder' => match ($ask) {
                'month' => 'The whole year',
                'search' => 'Account, description or amount',
                default => 'Choose one',
            },
            'options' => $pane->options($key, $ask, $asOf),
        ], app(ReportPaneRenderer::class)->asksFor($key));
    }

    /** Whether the selected report can be shown in the pane at all. */
    public function statementIsAvailable(): bool
    {
        return app(ReportPaneRenderer::class)->supportsReport($this->selectedReport()['key'] ?? null);
    }

    /**
     * The first report the pane can actually draw, so the screen never opens empty.
     *
     * Chosen from what this role may open rather than hard-coded to the balance sheet: a company
     * without the accounting module, or a role without ReportView, would otherwise land on a pane
     * pointing at a report that is not in their catalogue.
     */
    public function defaultStatementKey(): ?string
    {
        // From what is currently *in view*, not from the whole catalogue. Offering "show the balance
        // sheet" while the list is filtered to payroll — or while a search has emptied it — points at
        // something the person cannot see, and quietly contradicts the filter they just set.
        foreach ($this->visibleReports() as $report) {
            if (app(ReportPaneRenderer::class)->supportsReport($report['key'])) {
                return $report['key'];
            }
        }

        return null;
    }

    /**
     * Every report as one flat list, with a key.
     *
     * Keyed on the class basename rather than the label: the key travels in the URL and in
     * wire:click, and renaming a report's title should not break a link somebody kept.
     *
     * @return array<string, array{key: string, label: string, description: string, url: string, icon: string|BackedEnum|null, section: string}>
     */
    public static function catalogue(): array
    {
        $catalogue = [];

        foreach (static::sections() as $heading => $links) {
            foreach ($links as $link) {
                $catalogue[$link['key']] = $link + ['section' => $heading];
            }
        }

        return $catalogue;
    }

    /**
     * The section filter, once it has been checked against the sections that exist.
     *
     * `section` arrives from the query string, so it can say anything. An unrecognised value is
     * treated as no filter rather than as a filter that matches nothing: a section this company has
     * lost — payroll unlicensed, a role without the permission — is the ordinary way to arrive here
     * with a stale link, and answering it with an empty screen reads as "the reports are gone".
     */
    public function currentSection(): ?string
    {
        return array_key_exists((string) $this->section, static::sections())
            ? $this->section
            : null;
    }

    /**
     * The sections to draw, after the section filter and the search box.
     *
     * Search covers the description as well as the title, which is the point of having written
     * descriptions: "how late" finds the two ageing reports without knowing they are called ageing.
     *
     * @return array<string, array<int, array<string, mixed>>>
     */
    public function visibleSections(): array
    {
        $query = trim(mb_strtolower($this->query));
        $section = $this->currentSection();
        $visible = [];

        foreach (static::sections() as $heading => $links) {
            if (filled($section) && $section !== $heading) {
                continue;
            }

            $matching = array_values(array_filter($links, function (array $link) use ($query): bool {
                if ($query === '') {
                    return true;
                }

                return str_contains(mb_strtolower($link['label']), $query)
                    || str_contains(mb_strtolower($link['description']), $query);
            }));

            if ($matching !== []) {
                $visible[$heading] = $matching;
            }
        }

        return $visible;
    }

    /**
     * Flat, for the list view — the same set the grid shows, without the headings.
     *
     * The section travels with each row rather than being implied by position: the list view has a
     * Category column, which is what it shows there.
     *
     * @return array<int, array<string, mixed>>
     */
    public function visibleReports(): array
    {
        $reports = [];

        foreach ($this->visibleSections() as $heading => $links) {
            foreach ($links as $link) {
                $reports[] = $link + ['section' => $heading];
            }
        }

        return $reports;
    }

    /**
     * Section name => how many reports in it, for the column.
     *
     * Counted before the search filter: a category showing 0 while you type is noise, and the
     * counts are there to say how big each section is, not how many matched.
     *
     * @return array<string, int>
     */
    public static function sectionCounts(): array
    {
        return array_map('count', static::sections());
    }

    public static function total(): int
    {
        return array_sum(static::sectionCounts());
    }

    /** @return array<string, mixed>|null */
    public function selectedReport(): ?array
    {
        return static::catalogue()[$this->selected] ?? null;
    }

    public function select(string $key): void
    {
        // Only a key that is actually on offer. `selected` arrives from the browser, and the panel
        // it opens carries a link to the report — so an unfiltered value here would be a way to
        // have this page render a URL for a report the role cannot open.
        $this->selected = array_key_exists($key, static::catalogue()) ? $key : null;
    }

    /**
     * Open the transactions behind a figure.
     *
     * A statement answers "how much" and immediately raises "of what" — so a line on the balance sheet or
     * the trial balance switches the pane to that account's register, at the same date. The date is what
     * makes it a drill rather than a jump: the register opens on the period the figure came from.
     *
     * Refused for a code the register cannot open. Silently, because the view does not render the affordance
     * for those rows in the first place; this is the guard for a code that arrives anyway.
     *
     * Which account a code names is the pane's to answer, not this page's — asking it was the last thing in
     * Core that reached into a module. See `App\Support\Reporting\ReportPaneRenderer::drillTarget()`.
     */
    public function drillInto(string $code): void
    {
        $account = app(ReportPaneRenderer::class)->drillTarget($code);

        if ($account === null) {
            return;
        }

        $this->account = $account;
        $this->select('AccountRegister');
    }

    public function deselect(): void
    {
        $this->selected = null;
    }

    public function updatedQuery(): void
    {
        // A filter that hides the open report should not leave its panel open over the results.
        $this->deselect();
    }

    public static function canAccess(): bool
    {
        if (! static::moduleIsAvailable()) {
            return false;
        }

        // Whether *any* report would open. Deliberately not sections(): that
        // builds a URL per link, and this runs while the sidebar is assembled on
        // every request.
        foreach (static::linkedPages() as $page) {
            if ($page::canAccess()) {
                return true;
            }
        }

        return false;
    }

    /**
     * Help, and the two exports — Phase 4.1.
     *
     * The exports sit on the hub as well as on each report's own page because the two are views of one
     * payload: `ReportPaneRenderer` feeds both, and there is a test per report asserting they agree. An
     * export offered on one screen and not the other would be an arbitrary difference between two ways of
     * looking at the same thing.
     */
    protected function getHeaderActions(): array
    {
        return [
            HelpAction::make('reports', 'Reports: Help'),
            ...$this->buildActions(),
            ...$this->exportActions(),
        ];
    }

    /**
     * One button to start a report — Phase 6, item 7.
     *
     * **Only "new" lives up here.** Save and Cancel belong inside the form, beside the fields they act on, and
     * the two mistakes that would follow from putting them in the header are worth stating: a header action is
     * built when the component boots, so a mode's own buttons are the awkward ones to test (Phase 7 records
     * that trap), and a Save somebody has to look away from the form to find is a Save people miss.
     *
     * Hidden without `ReportBuild`. A mode that opens and then refuses to save is a worse answer than a button
     * that was never there.
     *
     * @return array<int, Action>
     */
    private function buildActions(): array
    {
        if ($this->building || ! $this->canBuild()) {
            return [];
        }

        return [
            Action::make('buildReport')
                ->label('New report')
                ->icon('heroicon-m-squares-plus')
                ->color('gray')
                ->action(fn (): mixed => $this->startBuilding()),
        ];
    }

    /**
     * The heading follows the filter, so the page says what it is showing.
     *
     * Filament's own header carries it rather than the view drawing a second one: that keeps one
     * title on the page, in the place every other page in this panel puts it, and leaves the search
     * and the grid/list toggle to sit beside it through PAGE_HEADER_ACTIONS_BEFORE — which is 3a's
     * single header row.
     */
    /**
     * Just the page's name.
     *
     * 4c gives each pane its own title — "Reports" over the list, the statement's own name over the
     * figures — so a heading that restated the filter ("All reports · showing 17 of 17") said the same
     * thing a third time, directly above two places that said it better.
     */
    public function getHeading(): string
    {
        return 'Reports';
    }

    /**
     * The links to render, with empty sections dropped.
     *
     * Static so that canAccess() above and the view below ask the same question
     * of the same list; labels and icons come from each page rather than being
     * repeated here, so renaming a report renames its link.
     *
     * @return array<string, array<int, array{label: string, description: string, url: string, icon: string|BackedEnum|null}>>
     */
    public static function sections(): array
    {
        $sections = [];

        foreach (ReportCatalogue::sections() as $heading => $pages) {
            $links = [];

            foreach ($pages as $page => $description) {
                if (! $page::canAccess()) {
                    continue;
                }

                $links[] = [
                    // Stable across a rename of the title, because it travels in the URL. See
                    // catalogue().
                    'key' => class_basename($page),
                    'label' => (string) $page::getNavigationLabel(),
                    'description' => $description,
                    'url' => $page::getUrl(),
                    'icon' => $page::getNavigationIcon(),
                    // Every coded report has a screen of its own, where its actions live.
                    'own_page' => true,
                ];
            }

            if ($links !== []) {
                $sections[$heading] = $links;
            }
        }

        $custom = static::customReports();

        if ($custom !== []) {
            $sections[ReportCatalogue::CUSTOM] = $custom;
        }

        return $sections;
    }

    /**
     * The reports somebody assembled, as rows of the hub — `docs/reports-expansion-plan.md` Phase 6, item 4.
     *
     * **Rows rather than pages, which is the one way this section differs from the other nine.** A coded
     * report is a class with a `canAccess()`, so the loop above can ask it; a definition is a row, and what
     * stands in for `canAccess()` is `ReportDefinition::readable()` — visible to this reader *and* over a
     * subject their licence and permissions let them open. Nothing else here needs to know the difference.
     *
     * **The URL is the hub itself with the report selected**, and that is not a shortcut. A built report has
     * no page of its own: item 4 puts it in the pane precisely so it inherits the pane's filters, record row,
     * export and URL state, so "the report's own screen" *is* this screen with `?selected=` set — which is
     * also the link Phase 8 will put in an email.
     *
     * @return array<int, array<string, mixed>>
     */
    private static function customReports(): array
    {
        $rows = [];

        foreach (ReportDefinition::readable() as $definition) {
            $dataset = $definition->dataset();

            $rows[] = [
                'key' => $definition->reportKey(),
                'label' => (string) $definition->name,
                // A definition need not carry a description, and a blank one reads as a broken row rather
                // than as an omission — so the subject and the span stand in, which is what somebody would
                // have written anyway.
                'description' => filled($definition->description)
                    ? (string) $definition->description
                    : trim(($dataset === null ? '' : $dataset::label().' · ')
                        .RelativePeriod::label($definition->settings()['period']), ' ·'),
                'url' => static::getUrl(['selected' => $definition->reportKey()]),
                // The row's icon comes from its section, as every row's has since Phase 4 replaced fifty-one
                // inline heroicons with nine symbols. See ReportIcons.
                'icon' => null,
                'own_page' => false,
            ];
        }

        return $rows;
    }
}
