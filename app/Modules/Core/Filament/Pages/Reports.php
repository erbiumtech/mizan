<?php

namespace App\Modules\Core\Filament\Pages;

use App\Filament\Concerns\BelongsToModule;
use App\Filament\Support\HelpAction;
use App\Support\Reporting\ExportsTheOpenReport;
use App\Support\Reporting\ReportCatalogue;
use App\Support\Reporting\ReportComparison;
use App\Support\Reporting\ReportPaneRenderer;
use BackedEnum;
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
            ...$this->exportActions(),
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
                ];
            }

            if ($links !== []) {
                $sections[$heading] = $links;
            }
        }

        return $sections;
    }
}
