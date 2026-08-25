<?php

namespace App\Modules\Core\Filament\Pages;

use App\Filament\Support\HelpAction;
use App\Modules\Core\Models\DashboardLayout;
use App\Support\Reporting\DashboardArrangement;
use App\Support\Reporting\DashboardPeriod;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Forms\Components\DatePicker;
use Filament\Notifications\Notification;
use Filament\Pages\Dashboard as BaseDashboard;
use Filament\Schemas\Components\View;
use Filament\Schemas\Schema;
use Livewire\Attributes\Url;

/**
 * The dashboard, with a period of its own — `docs/reports-expansion-plan.md` Phase 5.1.
 *
 * "A dashboard page of our own, replacing `Dashboard::class` in `AdminPanelProvider`, carrying a period
 * filter in the URL, so a dashboard someone links to opens on the period they meant. Widgets read the page's
 * filter; none of them keeps its own idea of 'now'."
 *
 * **The period reaches widgets through `getWidgetData()`**, which Filament spreads into every widget's mount
 * properties. So a widget declares `public ?string $period` and is handed the page's, rather than reading
 * `now()` itself — which is the whole point of the item. A widget with its own idea of the period is a widget
 * that disagrees with the one beside it, and on a dashboard the two sit a centimetre apart.
 *
 * **Filament's own filter form was not used, because the plan asks for the URL.** `HasFiltersForm` keeps
 * filter state in a schema, which is fine and is not linkable; "a dashboard someone links to opens on the
 * period they meant" needs `#[Url]`. So the properties are ours and `getWidgetData()` is the bridge.
 *
 * **In Core, not Accounting.** It belongs to no module and every module contributes widgets to it — the same
 * reasoning that puts the Reports hub, `OperationsOverview` and `FiscalYear` there, and Core is the one
 * module always licensed, which is what lets each widget's own `canView()` be the only thing deciding what
 * appears.
 *
 * ---
 *
 * **Phase 7 makes the order a default rather than the only arrangement.** A bookkeeper wants receivables
 * first and a store manager wants stock, and neither wants to scroll past the other's charts every morning.
 * `DashboardArrangement` resolves what to render and `DashboardLayout` stores it; this page is the interaction.
 *
 * **The widgets are hidden while the arranger is open, and that is a performance decision rather than a visual
 * one.** Every drop is a Livewire round trip, so a page that kept twenty-three widgets on screen would remount
 * and re-run every aggregate behind them each time somebody dragged a card. Arranging shows the arrangement.
 */
class Dashboard extends BaseDashboard
{
    /**
     * Which span the figures cover. In the URL so a dashboard is a link.
     *
     * Nullable rather than defaulted here so `DashboardPeriod::normalise()` is the single place that decides
     * what "unspecified" means — a default written twice is a default that eventually differs.
     */
    #[Url]
    public ?string $period = null;

    /** The ends of a custom range. In the URL for the same reason, and ignored unless both are set. */
    #[Url]
    public ?string $from = null;

    #[Url]
    public ?string $to = null;

    /**
     * Whether the arranger is open.
     *
     * **Not a `#[Url]` property, unlike everything above it.** The period is in the address because a
     * dashboard someone links to should open on the period they meant; "the dashboard while I was moving
     * things around" is not a view of the company that anybody would send. It is a mode, not a filter.
     */
    public bool $arranging = false;

    /**
     * The layout in force, read once per request.
     *
     * **Memoised on the page and cleared on every write**, which is the shape Phase 5.8 got wrong in a
     * different costume: Livewire mutates the layout and re-renders inside the *same* request, so a memo that
     * outlived a write would draw the arrangement as it was before the drop. `forgetSavedLayout()` is called
     * by every mutator below and there is a test that reorders twice in one pass.
     *
     * Two properties rather than one because null is a real answer — most companies have no layout at all, and
     * re-querying to rediscover that on every call is the query this memo exists to avoid.
     *
     * **Named `$savedLayout` rather than `$layout` because `Filament\Pages\Page::$layout` already exists** —
     * it is the static naming the Blade layout to render in, and PHP refuses to redeclare a static property as
     * an instance one. The error names the line of the class rather than the property, so it is worth the note.
     */
    private ?DashboardLayout $savedLayout = null;

    private bool $savedLayoutRead = false;

    /** @var array<int, \Filament\Widgets\WidgetConfiguration>|null */
    private ?array $arrangement = null;

    /**
     * Six columns, because three widths need six — see `DashboardArrangement::COLUMNS`.
     *
     * Filament's dashboard is two columns wide and two cannot express two thirds. Nothing already built
     * changes appearance: every widget declares either `$columnSpan = 1`, which was half of two columns and is
     * three of these, or `'full'`, which is a keyword rather than a number.
     *
     * @return int|array<string, ?int>
     */
    public function getColumns(): int|array
    {
        return DashboardArrangement::COLUMNS;
    }

    /**
     * The widgets to render, arranged.
     *
     * `parent::getWidgets()` is the panel's own list and is where this must start: item 4 of the plan asks
     * that a layout can never reveal a widget `canView()` refuses, and the way to guarantee that is never to
     * build the list from stored keys in the first place.
     *
     * @return array<int, class-string<\Filament\Widgets\Widget>|\Filament\Widgets\WidgetConfiguration>
     */
    public function getWidgets(): array
    {
        return $this->arrangement ??= DashboardArrangement::widgets(parent::getWidgets(), $this->savedLayout());
    }

    /**
     * The arranger's list: every widget this person may see, hidden ones included.
     *
     * Hidden widgets appear *here and nowhere else*, because a list that omitted them would make hiding a
     * one-way door.
     *
     * @return array<int, array{alias: string, label: string, module: ?string, width: string, hidden: bool}>
     */
    public function arrangerRows(): array
    {
        return DashboardArrangement::rows(parent::getWidgets(), $this->savedLayout());
    }

    /**
     * The page's body: the arranger, or the widgets.
     *
     * One or the other rather than both, for the reason in the class docblock — each drop is a round trip, so
     * leaving the widgets rendered would re-run every aggregate on the page per drag.
     */
    public function content(Schema $schema): Schema
    {
        return $schema->components([
            View::make('filament.pages.dashboard-arranger')
                ->viewData(fn (): array => [
                    'rows' => $this->arrangerRows(),
                    'widths' => DashboardArrangement::WIDTHS,
                ])
                ->visible(fn (): bool => $this->arranging),

            $this->getWidgetsContentComponent()
                ->visible(fn (): bool => ! $this->arranging),
        ]);
    }

    // ─────────────────────────────── arranging ──

    /**
     * Put the widgets in this order.
     *
     * Called from the sortable's `onEnd`, so `$aliases` is what the browser has just finished showing — the
     * arranger's rows, which are the widgets this person may see.
     *
     * **Anything stored but absent from that list keeps a position at the end.** An alias can be missing
     * because its module is switched off or because the person lost a permission, and its stored position is
     * speculative either way; appending it treats it exactly as a widget added since the layout was saved,
     * which is the one behaviour this feature already had to get right.
     *
     * @param  array<int, string>  $aliases
     */
    public function reorderWidgets(array $aliases): void
    {
        $state = $this->state();
        $ordered = array_values(array_filter($aliases, 'is_string'));

        $state['order'] = array_values(array_unique([
            ...$ordered,
            ...array_diff($state['order'], $ordered),
        ]));

        $this->save($state);
    }

    /** Hide a widget, or bring it back. */
    public function toggleWidget(string $alias): void
    {
        $state = $this->state();

        $state['hidden'] = in_array($alias, $state['hidden'], true)
            ? array_values(array_diff($state['hidden'], [$alias]))
            : [...$state['hidden'], $alias];

        $this->save($state);
    }

    /**
     * Set a widget's width.
     *
     * **A width equal to the widget's own is stored as nothing at all**, which is item 1's "partial override"
     * applied to widths as well as order: somebody who never widened a chart follows that chart's own default
     * if it changes, rather than being pinned to whatever it happened to be the day they arranged their
     * dashboard.
     */
    public function setWidgetWidth(string $alias, string $width): void
    {
        $class = DashboardArrangement::classFor($alias);

        if ($class === null || ! DashboardArrangement::isWidth($width)) {
            return;
        }

        $state = $this->state();

        if ($width === DashboardArrangement::defaultWidth($class)) {
            unset($state['spans'][$alias]);
        } else {
            $state['spans'][$alias] = $width;
        }

        $this->save($state);
    }

    /**
     * The state to start from: whatever is in force.
     *
     * Which is the company default when somebody has no layout of their own — so departing from the default
     * is an adjustment to it rather than starting from an empty arrangement, and moving one card does not
     * silently discard everything an administrator arranged.
     *
     * @return array{order: array<int, string>, hidden: array<int, string>, spans: array<string, string>}
     */
    private function state(): array
    {
        $layout = $this->savedLayout();

        return [
            'order' => $layout?->order() ?? [],
            'hidden' => $layout?->hidden() ?? [],
            'spans' => $layout?->spans() ?? [],
        ];
    }

    /**
     * Store a state as this person's own layout.
     *
     * Always the personal row, never the company default — an administrator dragging a card is arranging
     * their own dashboard like everybody else, and the default only moves when they say so.
     *
     * @param  array{order: array<int, string>, hidden: array<int, string>, spans: array<string, string>}  $state
     */
    private function save(array $state): void
    {
        if ($userId = auth()->id()) {
            DashboardLayout::put($userId, $state);
        }

        $this->forgetSavedLayout();
    }

    private function savedLayout(): ?DashboardLayout
    {
        if (! $this->savedLayoutRead) {
            $this->savedLayout = DashboardLayout::inForce();
            $this->savedLayoutRead = true;
        }

        return $this->savedLayout;
    }

    private function forgetSavedLayout(): void
    {
        $this->savedLayout = null;
        $this->savedLayoutRead = false;
        $this->arrangement = null;
    }

    /**
     * What every widget on this page is handed.
     *
     * The resolved dates rather than the period name, so no widget has to know that "this quarter" is fiscal
     * or that every period ends today. One place knows; twenty widgets are told.
     *
     * @return array<string, mixed>
     */
    public function getWidgetData(): array
    {
        $range = $this->range();

        return [
            'period' => $this->periodKey(),
            'periodFrom' => $range['from'],
            'periodTo' => $range['to'],
        ];
    }

    public function periodKey(): string
    {
        return DashboardPeriod::normalise($this->period);
    }

    /**
     * The span in force, resolved on every read.
     *
     * Not memoised: Livewire writes these properties straight from the wire when the picker changes, and a
     * cached range would be the previous one for the render that matters.
     *
     * @return array{from: string, to: string}
     */
    public function range(): array
    {
        return DashboardPeriod::range($this->periodKey(), $this->from, $this->to);
    }

    /** @return array<string, string> */
    public function periods(): array
    {
        return DashboardPeriod::PERIODS;
    }

    /**
     * The period picker, as one dropdown in the header.
     *
     * A dropdown rather than four chips because the dashboard's header is shared with whatever else a company
     * puts there, and because the label doubles as the statement of which period is in force — a row of chips
     * says the same thing twice.
     *
     * @return array<int, ActionGroup>
     */
    protected function getHeaderActions(): array
    {
        return [
            ActionGroup::make([
                ...$this->namedPeriodActions(),
                $this->customRangeAction(),
            ])
                ->label(DashboardPeriod::label($this->periodKey(), $this->from, $this->to))
                ->icon('heroicon-m-calendar-days')
                ->button()
                ->color('gray'),

            ...($this->arranging ? $this->arrangingActions() : [$this->arrangeAction()]),

            HelpAction::make('dashboard', 'Dashboard: Help'),
        ];
    }

    /**
     * Open the arranger.
     *
     * One button on the ordinary dashboard, and everything else about arranging lives inside the mode it
     * belongs to — a header carrying "reset my layout" and "apply the default to everybody" at all times
     * would put a destructive action next to the figures somebody came to read.
     */
    private function arrangeAction(): Action
    {
        return Action::make('arrange')
            ->label('Arrange')
            ->icon('heroicon-m-squares-2x2')
            ->color('gray')
            ->action(fn (): bool => $this->arranging = true);
    }

    /**
     * The actions that only exist while arranging.
     *
     * @return array<int, Action|ActionGroup>
     */
    private function arrangingActions(): array
    {
        $company = auth()->user()?->isAdministrator()
            ? [$this->setCompanyDefaultAction(), $this->applyToEveryoneAction()]
            : [];

        return [
            Action::make('doneArranging')
                ->label('Done')
                ->icon('heroicon-m-check')
                ->action(fn (): bool => $this->arranging = false),

            ActionGroup::make([
                $this->resetArrangementAction(),
                ...$company,
            ])
                ->icon('heroicon-m-ellipsis-vertical')
                ->iconButton()
                ->color('gray'),
        ];
    }

    /**
     * Throw away my arrangement and follow the company default again.
     *
     * Deletes the personal row rather than copying the default into it, which is what makes "reset" mean
     * *follow* the default: somebody who resets today still moves with it if an administrator changes it
     * tomorrow.
     *
     * Confirmed because it cannot be undone — an arrangement somebody spent five minutes on is gone, and the
     * button sits beside one that reads "Done".
     */
    private function resetArrangementAction(): Action
    {
        return Action::make('resetArrangement')
            ->label('Reset to the company default')
            ->icon('heroicon-m-arrow-uturn-left')
            ->color('gray')
            ->requiresConfirmation()
            ->modalHeading('Reset your dashboard?')
            ->modalDescription('Your own arrangement is discarded and you go back to the company default. Nobody else is affected.')
            ->modalSubmitActionLabel('Reset')
            ->visible(fn (): bool => DashboardLayout::mine() !== null)
            ->action(function (): void {
                DashboardLayout::forgetMine();
                $this->forgetSavedLayout();

                Notification::make()
                    ->title('Back to the company default')
                    ->success()
                    ->send();
            });
    }

    /**
     * Make what I am looking at the arrangement everybody starts from.
     *
     * Item 2: "An administrator sets the arrangement everyone starts from." It changes what new people and
     * anybody who resets will see, and takes nothing away from anybody — which is why this one does not ask
     * and the next one does.
     */
    private function setCompanyDefaultAction(): Action
    {
        return Action::make('setCompanyDefault')
            ->label('Make this the company default')
            ->icon('heroicon-m-building-office-2')
            ->color('gray')
            ->action(function (): void {
                DashboardLayout::put(null, $this->state());
                $this->forgetSavedLayout();

                Notification::make()
                    ->title('Saved as the company default')
                    ->body('New people, and anybody who resets their dashboard, will start from this arrangement.')
                    ->success()
                    ->send();
            });
    }

    /**
     * Put everybody back on the company default.
     *
     * Item 7: "An admin action to push the default to everybody is worth having and **must ask first** — it
     * discards arrangements people made." So it asks, and the wording says what is lost rather than asking
     * whether the administrator is sure: somebody who has read "are you sure?" twice today reads it as a
     * button with an extra step.
     *
     * It discards the administrator's own arrangement too, which is what "everybody" means — hence the order
     * the two actions appear in, and the reminder in the modal.
     */
    private function applyToEveryoneAction(): Action
    {
        return Action::make('applyToEveryone')
            ->label('Apply the default to everybody')
            ->icon('heroicon-m-users')
            ->color('danger')
            ->requiresConfirmation()
            ->modalHeading('Apply the company default to everybody?')
            ->modalDescription(
                'Every personal arrangement in this company is discarded, including yours, and everybody '
                .'sees the company default. If your own arrangement is the one you want, make it the company '
                .'default first.'
            )
            ->modalSubmitActionLabel('Apply to everybody')
            ->action(function (): void {
                $discarded = DashboardLayout::forgetEveryones();
                $this->forgetSavedLayout();

                Notification::make()
                    ->title('Everybody is on the company default')
                    ->body($discarded === 1
                        ? 'One personal arrangement was discarded.'
                        : $discarded.' personal arrangements were discarded.')
                    ->success()
                    ->send();
            });
    }

    /**
     * One action per named period.
     *
     * Each clears `from` and `to` on the way past. Without that, choosing "this month" after a custom range
     * would leave the custom dates in the URL — harmless while the period is named, and back in force the
     * moment somebody picked custom again, showing a range they had moved on from.
     *
     * @return array<int, Action>
     */
    private function namedPeriodActions(): array
    {
        $named = array_diff_key(DashboardPeriod::PERIODS, [DashboardPeriod::CUSTOM => null]);

        return array_values(array_map(
            fn (string $label, string $key): Action => Action::make('period'.str($key)->studly())
                ->label($label)
                ->action(function () use ($key): void {
                    $this->period = $key;
                    $this->from = null;
                    $this->to = null;
                }),
            $named,
            array_keys($named),
        ));
    }

    /**
     * The custom range, collected in a modal.
     *
     * Defaulted to the span currently in force rather than to empty, so "custom" starts from what somebody is
     * already looking at and is an adjustment rather than a fresh form.
     */
    private function customRangeAction(): Action
    {
        return Action::make('periodCustom')
            ->label(DashboardPeriod::PERIODS[DashboardPeriod::CUSTOM])
            ->modalHeading('Custom range')
            ->modalSubmitActionLabel('Show')
            ->fillForm(fn (): array => $this->range())
            ->schema([
                DatePicker::make('from')->label('From')->native(false)->required(),
                DatePicker::make('to')->label('To')->native(false)->required(),
            ])
            ->action(function (array $data): void {
                $this->period = DashboardPeriod::CUSTOM;
                $this->from = $data['from'];
                $this->to = $data['to'];
            });
    }

    /**
     * The period under the title, so the figures are never unlabelled.
     *
     * A dashboard whose numbers are for a span nobody states is a dashboard somebody reads as "now" and
     * quotes as "this year". A custom range names its own dates, because "Custom range" over a set of
     * figures says nothing about which figures.
     */
    public function getSubheading(): ?string
    {
        return DashboardPeriod::label($this->periodKey(), $this->from, $this->to);
    }
}
